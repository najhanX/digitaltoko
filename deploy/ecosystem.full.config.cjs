// Local development including the sibling API Hanshop and bot_wa_v2 projects.
module.exports = require('./pm2-apps.cjs').createConfig({ development: true, includeHanshop: true });
