FROM mcr.microsoft.com/playwright:v1.65.1-noble@sha256:1507cbfc550a63a6b7396791ca4f00df17beb56342d2447b3f106c306f525579

RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /work
COPY tests/Browser/package.json tests/Browser/package-lock.json ./
RUN npm ci --ignore-scripts --no-fund
COPY tests/Browser/ ./
RUN node tooling/check.js

ENV HOME=/tmp/browser-home
CMD ["npm", "test"]
