// VPS: Nginx/Caddy + PHP-FPM serve HTTP; PM2 manages background services only.
// Do not run another cron scheduler or a second manager for the same sessions.
module.exports = require('./pm2-apps.cjs').createConfig();
