// Laptop development: all seven services, equivalent to the manual dev commands.
module.exports = require('./deploy/pm2-apps.cjs').createConfig({ development: true, includeHanshop: true });
