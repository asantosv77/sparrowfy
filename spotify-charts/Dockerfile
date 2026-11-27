FROM node:20-bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      chromium \
      xvfb \
      x11vnc \
      novnc \
      websockify \
      fonts-liberation \
      ca-certificates \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json /app/package.json
RUN npm install --omit=dev && npm cache clean --force
COPY server.js /app/server.js
COPY entrypoint.sh /app/entrypoint.sh
COPY healthcheck.js /app/healthcheck.js
RUN chmod 755 /app/entrypoint.sh \
 && mkdir -p /data/profile \
 && chmod 700 /data /data/profile

EXPOSE 8099 6080
ENTRYPOINT ["/app/entrypoint.sh"]
