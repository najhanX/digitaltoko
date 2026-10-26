const path = require('node:path');

function port(value, fallback, name) {
  const number = value === undefined ? fallback : Number(value);
  if (!Number.isInteger(number) || number < 1 || number > 65535) {
    throw new Error(`${name} harus berupa port 1–65535.`);
  }
  return number;
}

function createConfig({ development = false, includeHanshop = false, env = process.env, platform = process.platform } = {}) {
  const root = path.resolve(__dirname, '..');
  const web = path.join(root, 'apps', 'web');
  const bot = path.join(root, 'apps', 'bot');
  const php = env.DIGITALTOKO_PHP_BINARY || 'php';
  const common = {
    namespace: 'digitaltoko', instances: 1, exec_mode: 'fork', watch: false,
    autorestart: true, min_uptime: 5000, max_restarts: 5, restart_delay: 5000, windowsHide: true,
    time: true, merge_logs: true,
  };
  const artisan = (name, args, killTimeout) => ({
    ...common, name, cwd: web, script: path.join(web, 'artisan'), interpreter: php,
    args: [...args, '--no-interaction', '--no-ansi'], kill_timeout: killTimeout,
  });
  const apps = [];
  if (development) {
    apps.push(artisan('digitaltoko-web', [
      'serve', '--host=' + (env.DIGITALTOKO_WEB_HOST || '127.0.0.1'),
      '--port=' + port(env.DIGITALTOKO_WEB_PORT, 8000, 'DIGITALTOKO_WEB_PORT'),
      '--tries=1', '--no-reload',
    ], 10000));
    apps.push({
      ...common, name: 'digitaltoko-vite', cwd: web,
      script: path.join(web, 'node_modules', 'vite', 'bin', 'vite.js'), interpreter: process.execPath,
      args: ['--host', env.DIGITALTOKO_VITE_HOST || '127.0.0.1', '--port',
        String(port(env.DIGITALTOKO_VITE_PORT, 5173, 'DIGITALTOKO_VITE_PORT')), '--strictPort'],
      kill_timeout: 10000, env: { NODE_ENV: 'development' },
    });
  }
  apps.push(artisan('digitaltoko-queue', [
    'queue:work', '--sleep=1', '--timeout=180', '--tries=3', '--backoff=5', '--memory=256', '--max-time=3600',
  ], 200000));
  apps.push(artisan('digitaltoko-scheduler', ['schedule:work'], 70000));
  apps.push({
    ...common, name: 'digitaltoko-bot-manager', cwd: bot,
    script: path.join(bot, ...(development ? ['scripts', 'manager-dev.mjs'] : ['dist', 'manager.js'])), interpreter: process.execPath,
    max_memory_restart: '300M', restart_delay: 15000, kill_timeout: 15000,
    env: { NODE_ENV: development ? 'development' : 'production' },
  });
  if (includeHanshop) {
    const api = path.resolve(env.DIGITALTOKO_HANSHOP_API_DIR || path.join(root, '..', 'API Hanshop'));
    const hanshopBot = path.resolve(env.DIGITALTOKO_HANSHOP_BOT_DIR || path.join(root, '..', 'bot_wa_v2'));
    apps.push({
      ...common, name: 'api-hanshop', cwd: api, script: path.join(api, ...(development ? ['node_modules', 'tsx', 'dist', 'cli.mjs'] : ['dist', 'server.js'])),
      ...(development ? { args: ['watch', 'src/server.ts'] } : {}),
      interpreter: process.execPath, max_memory_restart: '750M', kill_timeout: 12000,
      env: { NODE_ENV: development ? 'development' : 'production' },
    });
    apps.push({
      ...common, name: 'hanshop-bot', cwd: hanshopBot, script: path.join(hanshopBot, ...(development ? ['node_modules', 'tsx', 'dist', 'cli.mjs'] : ['dist', 'index.js'])),
      ...(development ? { args: ['watch', 'src/index.ts'] } : {}),
      interpreter: process.execPath, kill_timeout: 15000,
      env: { NODE_ENV: development ? 'development' : 'production' },
    });
  }
  if (development && platform === 'win32') {
    const runner = env.DIGITALTOKO_WINDOWS_RUNNER || path.join(env.USERPROFILE || require('node:os').homedir(), '.digitaltoko', 'bin', 'hidden-process.exe');
    const preload = path.join(root, 'deploy', 'hide-child-windows.cjs').replace(/\\/g, '/');
    for (const app of apps) {
      if (app.interpreter === php) {
        app.args = [php, app.script, ...app.args];
        app.script = runner;
        app.interpreter = 'none';
      } else {
        app.env.NODE_OPTIONS = ((env.NODE_OPTIONS || '') + ' --require=' + JSON.stringify(preload)).trim();
      }
    }
  }
  return { apps };
}

module.exports = { createConfig };
