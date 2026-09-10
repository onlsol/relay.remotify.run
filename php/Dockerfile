FROM php:8.3-fpm-alpine

RUN mkdir -p /var/data/sessions && chown -R www-data:www-data /var/data

# Every queue body is read verbatim from php://input; nothing uses $_POST.
# enable_post_data_reading=Off stops PHP from parsing a POST body as a form at
# request startup: a gzip-compressed result that curl sends with its default
# form content type is otherwise split on '&' and, past max_input_vars, emits a
# startup warning. display_errors=Off keeps that class of warning out of the
# response body, where it forced "headers already sent" and turned the 201
# acknowledgement into a 200 the runner could not accept. Errors still reach
# the container log via log_errors.
RUN { \
    echo 'post_max_size = 30M';              \
    echo 'upload_max_filesize = 30M';        \
    echo 'memory_limit = 256M';              \
    echo 'enable_post_data_reading = Off';   \
    echo 'display_errors = Off';             \
    echo 'log_errors = On';                  \
    } > /usr/local/etc/php/conf.d/99-remotify.ini

COPY docker-entrypoint.sh /usr/local/bin/remotify-entrypoint.sh
RUN chmod +x /usr/local/bin/remotify-entrypoint.sh

WORKDIR /var/www/html
COPY app/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html

ENTRYPOINT ["/usr/local/bin/remotify-entrypoint.sh"]
CMD ["php-fpm"]
