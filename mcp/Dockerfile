FROM node:22-alpine
WORKDIR /app
COPY package.json server.js ./
RUN npm install --omit=dev
ENTRYPOINT ["node", "server.js"]
