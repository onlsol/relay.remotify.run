<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RelayHelpersTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/remotify-ut-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0755, true);
        putenv('DATA_DIR='    . $this->tmp);
        putenv('SCHEME=https');
        putenv('DOMAIN=test.example');
        putenv('PUBLIC_PORT=');
        putenv('SESSION_TTL=60');
        putenv('SOURCE_URL=');
        putenv('MAX_BODY_BYTES=');
        cfg(true); // force re-read
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new RecursiveDirectoryIterator($this->tmp, RecursiveDirectoryIterator::SKIP_DOTS);
            foreach (new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                if ($f->isDir()) { @rmdir($f->getPathname()); } else { @unlink($f->getPathname()); }
            }
            @rmdir($this->tmp);
        }
    }

    private function key(string $seed): string
    {
        // Deterministic 32-hex key per test, no reliance on global state.
        return str_pad(substr(sha1($seed), 0, 32), 32, '0');
    }

    // -----------------------------------------------------------------
    // Config
    // -----------------------------------------------------------------

    public function testCfgReadsEnv(): void
    {
        $c = cfg();
        $this->assertSame('https',        $c['scheme']);
        $this->assertSame('test.example', $c['domain']);
        $this->assertSame('https://test.example', $c['base']);
        $this->assertSame($this->tmp,     $c['data_dir']);
        $this->assertSame(60,             $c['session_ttl']);
        $this->assertFalse($c['audit_log']);
    }

    public function testCfgOmitsDefaultPort(): void
    {
        putenv('PUBLIC_PORT=443');
        cfg(true);
        $this->assertStringNotContainsString(':443', cfg()['base']);
    }

    public function testCfgIncludesNonDefaultPort(): void
    {
        putenv('PUBLIC_PORT=8443');
        cfg(true);
        $this->assertStringContainsString(':8443', cfg()['base']);
    }

    // -----------------------------------------------------------------
    // Session lifecycle
    // -----------------------------------------------------------------

    public function testSessionDirCreateAndTouch(): void
    {
        $k = $this->key('a');
        $this->assertFalse(is_dir(session_dir($k)));
        create_session($k);
        $this->assertDirectoryExists(session_dir($k));
        $this->assertTrue(touch_session($k));
    }

    public function testTouchUnknownSessionReturnsFalse(): void
    {
        $this->assertFalse(touch_session($this->key('unknown')));
    }

    // A key is only worth handing out if its directory really exists. When the
    // data dir cannot be written, create_session() must say so, so the caller
    // answers 500 instead of a 201 whose every follow-up call 410s.
    public function testCreateSessionReportsUnwritableDataDir(): void
    {
        $blocker = $this->tmp . '/not-a-dir';
        file_put_contents($blocker, 'x');
        putenv('DATA_DIR=' . $blocker . '/sessions');
        cfg(true);
        $this->assertFalse(create_session($this->key('nostore')));
    }

    public function testTouchExpiredSessionPurges(): void
    {
        $k = $this->key('expired');
        create_session($k);
        // Backdate the dir mtime past the 60s TTL.
        @touch(session_dir($k), time() - 600);
        clearstatcache(true, session_dir($k));
        $this->assertFalse(touch_session($k));
        $this->assertDirectoryDoesNotExist(session_dir($k));
    }

    public function testPurgeRemovesEverything(): void
    {
        $k = $this->key('purge');
        create_session($k);
        write_queue($k, 'cmd', 'hello');
        write_queue($k, 'result', 'world');
        $this->assertDirectoryExists(session_dir($k));
        purge_session($k);
        $this->assertDirectoryDoesNotExist(session_dir($k));
    }

    // -----------------------------------------------------------------
    // Queue slot I/O
    // -----------------------------------------------------------------

    public function testWriteReadRoundTrip(): void
    {
        $k = $this->key('rw');
        create_session($k);
        $this->assertSame(201, write_queue($k, 'cmd', 'echo hi'));
        $this->assertSame('echo hi', read_queue($k, 'cmd'));
        $this->assertNull(read_queue($k, 'cmd')); // second read: nothing to consume
    }

    public function testWriteQueueLastWins(): void
    {
        $k = $this->key('lastwins');
        create_session($k);
        write_queue($k, 'cmd', 'first');
        write_queue($k, 'cmd', 'second');
        $this->assertSame('second', read_queue($k, 'cmd'));
    }

    public function testArchivesAreKeptOnRotation(): void
    {
        $k = $this->key('archive');
        create_session($k);
        write_queue($k, 'cmd', 'first');
        write_queue($k, 'cmd', 'second');
        $archives = glob(session_dir($k) . '/cmd-*') ?: [];
        $this->assertGreaterThanOrEqual(2, count($archives));
    }

    public function testDeleteHotDropsWithoutConsuming(): void
    {
        $k = $this->key('drop');
        create_session($k);
        write_queue($k, 'cmd', 'bye');
        $this->assertFileExists(session_dir($k) . '/cmd');
        delete_hot($k, 'cmd');
        $this->assertFileDoesNotExist(session_dir($k) . '/cmd');
        $this->assertNull(read_queue($k, 'cmd'));
        // The archive of the dropped push still survives for audit.
        $this->assertNotEmpty(glob(session_dir($k) . '/cmd-*'));
    }

    public function testReadQueueOnUnknownSessionReturnsNull(): void
    {
        $this->assertNull(read_queue($this->key('nope'), 'cmd'));
    }

    public function testBinarySafetyRoundTrip(): void
    {
        $k = $this->key('binary');
        create_session($k);
        $body = "\x00\x01\x02ABC\x7f\xffend";
        write_queue($k, 'result', $body);
        $this->assertSame($body, read_queue($k, 'result'));
    }

    // -----------------------------------------------------------------
    // Stale in-flight self-heal
    // -----------------------------------------------------------------

    public function testSelfHealNoopWhenIdle(): void
    {
        $k = $this->key('sh-idle');
        create_session($k);
        $this->assertFalse(self_heal_stale_inflight($k));
        $this->assertNull(read_queue($k, 'result'), 'no synthetic result on an idle session');
    }

    public function testSelfHealNoopWhileCmdStillQueued(): void
    {
        // A queued-but-unconsumed cmd is "waiting for pickup", not in-flight.
        $k = $this->key('sh-queued');
        create_session($k);
        write_queue($k, 'cmd', 'echo hi');
        $this->assertFalse(self_heal_stale_inflight($k));
        $this->assertSame('echo hi', read_queue($k, 'cmd'), 'queued cmd untouched');
    }

    public function testSelfHealRespectsGraceOnFreshInflight(): void
    {
        $k = $this->key('sh-fresh');
        create_session($k);
        write_queue($k, 'cmd', 'echo hi');
        read_queue($k, 'cmd');
        mark_cmd_consumed($k);
        clearstatcache();
        $this->assertFalse(self_heal_stale_inflight($k), 'within the 2s grace: no heal');
        $this->assertTrue(cmd_in_flight($k), 'still in flight');
    }

    public function testSelfHealClearsStaleInflightAndQueuesMarker(): void
    {
        $k = $this->key('sh-stale');
        create_session($k);
        write_queue($k, 'cmd', 'echo hi');
        read_queue($k, 'cmd');
        mark_cmd_consumed($k);
        touch(session_dir($k) . '/_phase', time() - 10); // backdate past the grace
        clearstatcache();
        $this->assertTrue(self_heal_stale_inflight($k));
        clearstatcache();
        $this->assertFalse(cmd_in_flight($k), 'phase flipped back to idle');
        $result = read_queue($k, 'result');
        $this->assertNotNull($result, 'synthetic result queued for the waiting client');
        $this->assertStringStartsWith('[remotify: ', $result);
    }

    // -----------------------------------------------------------------
    // Payload + runner_script shape
    // -----------------------------------------------------------------

    public function testSessionPayloadShape(): void
    {
        $k = $this->key('payload');
        $p = session_payload($k);
        $this->assertSame($k, $p['key']);
        $this->assertSame(60, $p['ttl_seconds']);
        $this->assertSame("https://test.example/cmd-$k",            $p['urls']['cmd']);
        $this->assertSame("https://test.example/result-$k",         $p['urls']['result']);
        $this->assertSame("https://test.example/r/$k",              $p['urls']['runner']);
        $this->assertSame("https://test.example/api/session/$k",    $p['urls']['api']);
        $this->assertStringStartsWith("curl -fsSL '", $p['remote_quickstart']);
        $this->assertStringContainsString("/r/$k",    $p['remote_quickstart']);
        $this->assertStringNotContainsString('?mode=auto', $p['remote_quickstart']);
        $this->assertSame(
            str_replace("' | bash", "?mode=auto' | bash", $p['remote_quickstart']),
            $p['remote_quickstart_auto']
        );
    }

    public function testMcpMessagesTemplatesServedOnSessionPayload(): void
    {
        // The wording file must parse and reach MCP clients via the session
        // payload, so prompt iterations deploy with the relay instead of
        // requiring an npm re-publish of the MCP package.
        $m = mcp_messages();
        $this->assertIsArray($m, 'php/app/mcp-messages.json must exist and parse');
        $this->assertSame(1, $m['version']);
        $this->assertIsArray($m['templates']);
        $this->assertNotEmpty($m['templates']);
        foreach ($m['templates'] as $key => $tpl) {
            $this->assertIsString($tpl, "template '$key' must be a string");
        }
        // The key the MCP renders the connect hint from, with its placeholder.
        $this->assertStringContainsString('{runner_lines}', $m['templates']['pending_first']);
        $this->assertStringContainsString('{runner_url}',   $m['templates']['runner_lines']);

        $p = session_payload($this->key('mcpmsg'));
        $this->assertSame($m, $p['mcp_messages']);
    }

    public function testRunnerScriptSupervised(): void
    {
        $k = $this->key('runsup');
        $s = runner_script($k, 'supervised');
        $this->assertStringStartsWith('#!/usr/bin/env bash', $s);
        $this->assertStringContainsString('[supervised]',               $s);
        $this->assertStringContainsString("KEY='$k'",                   $s);
        $this->assertStringContainsString('[yY]*',                      $s); // lenient y-match
        $this->assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $s);
        $this->assertStringContainsString('>>> %s',                     $s);
        $this->assertStringContainsString('<<< done',                   $s);
        $this->assertStringContainsString('declined by operator',       $s);
    }

    public function testRunnerScriptAuto(): void
    {
        $k = $this->key('runauto');
        $s = runner_script($k, 'auto');
        $this->assertStringContainsString('[auto]',   $s);
        $this->assertStringContainsString('>>> %s',   $s);
        $this->assertStringContainsString('<<< done', $s);
        $this->assertStringNotContainsString('Run? [y/N]',  $s); // auto does not prompt
        $this->assertStringNotContainsString('[declined',   $s);
        $this->assertStringContainsString("KEY='$k'", $s);
        $this->assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $s);
    }

    // The result push must declare its body as octet-stream on BOTH curl paths
    // (gzip and raw). Without the header curl labels the body as a form and PHP
    // splits a large gzip stream on '&' at request startup; past max_input_vars
    // that emits a warning into the response, so the 201 becomes a 200 and the
    // runner retries a push that already landed. Any non-201 2xx/3xx must be
    // treated as "not confirmed" rather than retried.
    public function testRunnerPushDeclaresOctetStreamAndRejectsNon201Success(): void
    {
        foreach (['auto', 'supervised'] as $mode) {
            $s = runner_script($this->key("push$mode"), $mode);
            $start = strpos($s, 'push_result() {');
            $end   = strpos($s, "\n}\n", $start);
            $this->assertNotFalse($start, "$mode: push_result helper present");
            $fn = substr($s, $start, $end - $start);
            // One curl per path (gzip, raw); each targets $RES_URL and declares octet-stream.
            $this->assertSame(2, substr_count($fn, '"$RES_URL"'), "$mode: gzip + raw push");
            $this->assertSame(2, substr_count($fn, "-H 'Content-Type: application/octet-stream'"), "$mode: both pushes declare octet-stream");
            $this->assertStringContainsString('201) return 0 ;;', $fn, "$mode: only 201 confirms");
            $this->assertStringContainsString('2[0-9][0-9]|3[0-9][0-9])', $fn, "$mode: non-201 2xx/3xx branch");
        }
    }

    // A retired hostname answers 426 on every route, so no command will ever
    // arrive there. The poll loop must treat that like 410 and stop, instead of
    // reprinting the same error every couple of seconds until the operator
    // notices; and the failure branches that CAN recover must back off rather
    // than hammer a relay that is down.
    public function testRunnerPollLoopStopsOnMovedEndpointAndBacksOffOnFailure(): void
    {
        foreach (['auto', 'supervised'] as $mode) {
            $s = runner_script($this->key("poll$mode"), $mode);
            $this->assertStringContainsString('426)', $s, "$mode: 426 branch");
            $this->assertStringContainsString('no longer serves the relay', $s, "$mode: 426 message");
            $this->assertStringContainsString("back_off() {", $s, "$mode: back_off helper");
            // Both recoverable branches use it: network error, and other HTTP.
            $this->assertSame(2, substr_count($s, 'back_off ;;'), "$mode: both retry branches back off");
            $this->assertStringContainsString('POLL_WAIT=2', $s, "$mode: an answered poll resets the wait");
            $this->assertStringContainsString('POLL_WAIT=30', $s, "$mode: the wait is capped");
        }
    }

    // MAX_BODY_SIZE is documented as the single source of truth for body
    // limits. A runner that truncates at a hardcoded 20MB while the relay is
    // configured smaller would just collect 413s on every large result.
    public function testRunnerOutputCapFollowsTheConfiguredBodyLimit(): void
    {
        $this->assertStringContainsString(
            'MAX_OUT_BYTES=20971520',
            runner_script($this->key('cap-default'), 'auto'),
            'unchanged at the 25MB default'
        );
        putenv('MAX_BODY_BYTES=' . (1024 * 1024));
        cfg(true);
        $this->assertStringContainsString(
            'MAX_OUT_BYTES=838860',
            runner_script($this->key('cap-small'), 'auto'),
            '80% of a lowered wire cap'
        );
    }

    public function testRunnerScriptUnknownModeFallsBackToSupervised(): void
    {
        // The h_runner() handler normalizes unknown modes to 'supervised',
        // but runner_script() itself should at least not blow up when handed
        // a value it doesn't recognize.
        $s = runner_script($this->key('weird'), 'banana');
        $this->assertStringStartsWith('#!/usr/bin/env bash', $s);
        // Guard against an approval-bypass regression: an unrecognized mode
        // must fall back to the safe supervised behavior (prompts before
        // running, self-identifies as supervised), never to auto.
        $this->assertStringContainsString('[supervised]', $s);
        $this->assertStringContainsString('Run? [y/N]',   $s);
        $this->assertStringNotContainsString('[auto]',     $s);
    }

    // -----------------------------------------------------------------
    // Long-poll: consume only at delivery
    // -----------------------------------------------------------------

    // Names in the session dir, so a poll that touched anything (a `.lock`
    // created by a destructive read, a consumed hot slot) shows up as a diff.
    private function listing(string $dir): array
    {
        clearstatcache();
        $l = scandir($dir) ?: [];
        sort($l);
        return $l;
    }

    // While the slot is empty the long-poll must only look. Reading (and thus
    // unlinking) on every tick consumes the slot up to a whole LONGPOLL_MS
    // before the body is echoed, so a client that disconnected mid-wait takes
    // the payload with it -- no route serves the archive, so the command or
    // result is simply lost.
    public function testLongpollDoesNotTouchAnEmptySlot(): void
    {
        $k = $this->key('lp-empty');
        create_session($k);
        $dir = session_dir($k);
        // The layout a consumed push leaves behind: archive, no hot pointer.
        file_put_contents("$dir/cmd-20990101T000000Z", 'already delivered');
        $before = $this->listing($dir);
        $t0 = microtime(true);
        $this->assertNull(read_queue_longpoll($k, 'cmd', 400));
        $this->assertGreaterThan(0.3, microtime(true) - $t0, 'waited out the deadline');
        $this->assertSame($before, $this->listing($dir), 'nothing read, written or unlinked');
    }

    public function testLongpollDeliversAHotSlotThatAppearsMidWait(): void
    {
        $k = $this->key('lp-late');
        create_session($k);
        $dir = session_dir($k);
        $archive = "$dir/cmd-20990101T000000Z";
        file_put_contents($archive, 'late command');
        // The hot pointer appears only after the poll loop is already running.
        exec('( sleep 0.4; ln ' . escapeshellarg($archive) . ' ' . escapeshellarg("$dir/cmd")
             . ' ) >/dev/null 2>&1 &');
        $t0 = microtime(true);
        $body = read_queue_longpoll($k, 'cmd', 4000);
        $this->assertSame('late command', $body);
        $this->assertLessThan(3.0, microtime(true) - $t0, 'returned as soon as it appeared');
        clearstatcache();
        $this->assertFileDoesNotExist("$dir/cmd", 'hot slot consumed on delivery');
        $this->assertFileExists($archive, 'archive kept for audit');
    }

    // -----------------------------------------------------------------
    // Purge / GC races
    // -----------------------------------------------------------------

    // Losing a concurrent purge (two clients contacting an expired key at once,
    // a duplicate DELETE) must answer 410/204, not a bare 500. PHP's stat cache
    // is primed by the is_dir() at the top and a FAILED rename() does not clear
    // it, so the lost-race guard sees a directory that is already gone and the
    // traversal below it throws.
    public function testPurgeSurvivesTheSessionDirVanishingMidPurge(): void
    {
        $k = $this->key('purge-race');
        create_session($k);
        write_queue($k, 'cmd', 'hello');
        $dir = session_dir($k);
        $this->assertTrue(is_dir($dir));   // prime the stat cache, as purge does
        // An external mv: PHP's own rename() would clear the cache and hide it.
        exec('mv ' . escapeshellarg($dir) . ' ' . escapeshellarg($dir . '-gone'));
        purge_session($k);                 // must not throw
        clearstatcache();
        $this->assertDirectoryDoesNotExist($dir);
    }

    // Same race on the sweeper: the tombstone is stat'ed, a concurrent purge
    // finishes it off, and the traversal is left pointing at nothing.
    public function testGcSurvivesATombstoneVanishingMidSweep(): void
    {
        // Keep the data dir to itself so the sweep sees exactly one entry and
        // cannot evict the primed stat cache on some other name first.
        $data = $this->tmp . '/sessions';
        mkdir($data, 0700, true);
        putenv('DATA_DIR=' . $data);
        cfg(true);
        cfg();                             // create/stat the data dir up front
        $target = $this->tmp . '/tomb-body';
        mkdir($target, 0700, true);
        file_put_contents("$target/cmd", 'leftover');
        $path = $data . '/' . str_repeat('a', 32) . '.dead-0123456789ab';
        symlink($target, $path);
        $this->assertTrue(is_dir($path));  // prime the stat cache, as gc does
        // Outside the data dir, so the sweep still sees only the tombstone.
        exec('mv ' . escapeshellarg($target) . ' ' . escapeshellarg($this->tmp . '/tomb-gone'));
        gc_sessions();                     // must not throw
        $this->assertTrue(true, 'sweep completed');
    }

    // -----------------------------------------------------------------
    // Runner: transport failures and output truncation
    // -----------------------------------------------------------------

    // curl prints the -w value BEFORE exiting non-zero, so a network-level
    // failure sets CODE to "000", never "". Without the 000 arm every transport
    // fault fell through to the generic branch, which reprints the status and
    // discards $ERR -- the actual curl diagnostic the operator needs.
    public function testRunnerPollLoopHandlesCurlTransportFailure(): void
    {
        foreach (['auto', 'supervised'] as $mode) {
            $s = runner_script($this->key("curl000$mode"), $mode);
            $this->assertStringContainsString('""|000)', $s, "$mode: 000 shares the network-error arm");
        }
    }

    // `head -c` keeps the HEAD of the output, so anything appended before the
    // cut is what gets thrown away. The exit-status marker must therefore be
    // written last, or a failed command with oversize output reads as a
    // silent success.
    public function testRunnerTruncationKeepsTheExitStatusMarker(): void
    {
        $s = runner_script($this->key('trunc'), 'auto');
        $trunc = strpos($s, 'output truncated to');
        $exit  = strpos($s, 'exit status %d');
        $this->assertNotFalse($trunc, 'truncation notice present');
        $this->assertNotFalse($exit,  'exit-status marker present');
        $this->assertLessThan($exit, $trunc, 'truncation runs before the marker is appended');

        // Run the block itself with a tiny cap: the marker must survive the cut.
        $start = strpos($s, 'sz=$(wc -c');
        $end   = strpos($s, 'cat "$OUT_FILE"; echo', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $out = $this->tmp . '/trunc-out';
        file_put_contents($out, str_repeat('x', 100));
        $prog = "set -u\nOUT_FILE=" . escapeshellarg($out) . "\nMAX_OUT_BYTES=50\nran=1\nrc=7\n"
              . substr($s, $start, $end - $start);
        exec('bash -c ' . escapeshellarg($prog) . ' 2>&1', $lines, $rc);
        $this->assertSame(0, $rc, 'block runs clean: ' . implode("\n", $lines));
        $body = (string)file_get_contents($out);
        $this->assertStringContainsString('output truncated to 50 of 100 bytes', $body);
        $this->assertStringEndsWith("[remotify: exit status 7]\n", $body, 'marker survives truncation');
    }
}
