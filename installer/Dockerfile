FROM node:26-alpine
RUN apk add --no-cache docker-cli docker-cli-compose openssl bash
WORKDIR /opt/installer
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY src ./src
ENTRYPOINT ["node", "/opt/installer/src/main.js"]
