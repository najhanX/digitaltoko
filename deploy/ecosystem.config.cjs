// Each production worker gets its own working directory/config and port.
// No token is committed here. Supply STORE_ID, WORKER_TOKEN, WEB_BASE_URL,
// SESSION_ROOT, PORT and WA_ENABLED through the process environment/.env.
module.exports = {
  apps: [{name:'digitaltoko-bot-store',cwd:'/var/www/digitaltoko/apps/bot',script:'dist/index.js',
    instances:1,exec_mode:'fork',autorestart:true,max_memory_restart:'300M',
    restart_delay:15000,env:{NODE_ENV:'production'}}],
};
