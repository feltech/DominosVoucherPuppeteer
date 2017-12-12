FROM node:8-slim
LABEL name "dominos_voucher_puppet"

RUN apt-get update && \
	apt-get install -yq gconf-service libasound2 libatk1.0-0 libc6 libcairo2 libcups2 libdbus-1-3 \
	libexpat1 libfontconfig1 libgcc1 libgconf-2-4 libgdk-pixbuf2.0-0 libglib2.0-0 libgtk-3-0 libnspr4 \
	libpango-1.0-0 libpangocairo-1.0-0 libstdc++6 libx11-6 libx11-xcb1 libxcb1 libxcomposite1 \
	libxcursor1 libxdamage1 libxext6 libxfixes3 libxi6 libxrandr2 libxrender1 libxss1 libxtst6 \
	ca-certificates fonts-liberation libappindicator1 libnss3 lsb-release xdg-utils wget git && \
	apt-get clean && apt-get autoremove -y && rm -rf /var/lib/apt/lists/*

RUN yarn global add puppeteer && yarn cache clean

ENV NODE_PATH="/usr/local/share/.config/yarn/global/node_modules:${NODE_PATH}"

ADD package.json /tmp/package.json
RUN cd /tmp && npm install
RUN mkdir -p /app && cp -a /tmp/node_modules /app/

WORKDIR /app

EXPOSE 3000

ADD ./index.js /app/

#CMD ["node", "--inspect=0.0.0.0:9229", "index.js"]
CMD ["node", "index.js"]