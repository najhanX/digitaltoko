// Single manager per VPS; it starts isolated store workers on ephemeral loopback ports.
// Supply BOT_MANAGER_TOKEN, WEB_BASE_URL and SESSION_ROOT through the private .env.
module.exports = {
  apps: [{name:'digitaltoko-bot-manager',cwd:'/var/www/digitaltoko/apps/bot',script:'dist/manager.js',
    instances:1,exec_mode:'fork',autorestart:true,max_memory_restart:'300M',
    restart_delay:15000,env:{NODE_ENV:'production'}}],
};
