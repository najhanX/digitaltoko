// Run as hans, the same PM2 user that owns the existing Hanshop processes.
// DigitalToko is resolved relative to this file, so its checkout can be moved.
const path = require('node:path');
const { createConfig } = require('./pm2-apps.cjs');

const digitaltoko = createConfig().apps.map(app => ({
  ...app,
  env: { ...app.env, APP_ENV: 'production', NODE_ENV: 'production' },
}));
const hanshopRoot = process.env.HANSHOP_PRODUCTION_ROOT || '/home/hans/hanshop';
const legacy = (name, folder, port, memory, delay) => ({
  name,
  cwd: path.posix.join(hanshopRoot, folder),
  script: 'npm', args: 'start', exec_mode: 'fork', instances: 1,
  autorestart: true, watch: false, restart_delay: delay,
  max_restarts: 10, min_uptime: '10s', max_memory_restart: memory,
  kill_timeout: 15000, time: true,
  env: { NODE_ENV: 'production', PORT: port },
});

module.exports = {
  apps: [
    legacy('api-hanshop', 'api_hanshop', 4000, '500M', 3000),
    legacy('bot-wa', 'bot_wa_hanshop_v2', 3000, '600M', 5000),
    legacy('bot-tele', 'bot_tele_hanshop', 3100, '600M', 5000),
    ...digitaltoko,
  ],
};
