const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

function invocation(args, settings) {
  // Explicit targets/options retain normal PM2 behavior, including other projects.
  return args.length === 1 && args[0] === 'start'
    ? ['start', path.join(settings.root, 'ecosystem.config.js')]
    : args;
}

function main(args) {
  if (args[0] !== '--settings' || !args[1]) {
    throw new Error('Launcher belum dipasang. Jalankan deploy/install-pm2-laptop.ps1.');
  }
  const settings = JSON.parse(fs.readFileSync(args[1], 'utf8').replace(/^\uFEFF/, ''));
  if (!fs.existsSync(settings.pm2Cli) || !fs.existsSync(path.join(settings.root, 'ecosystem.config.js'))) {
    throw new Error('Lokasi PM2/proyek berubah. Pasang ulang launcher dari lokasi proyek terbaru.');
  }
  const forwarded = invocation(args.slice(2), settings);
  if (process.env.DIGITALTOKO_PM2_DRY_RUN === '1') {
    console.log(JSON.stringify({ executable: process.execPath, args: [settings.pm2Cli, ...forwarded] }));
    return 0;
  }
  const result = spawnSync(process.execPath, [settings.pm2Cli, ...forwarded], {
    stdio: 'inherit', windowsHide: true,
    env: { ...process.env, DIGITALTOKO_PHP_BINARY: process.env.DIGITALTOKO_PHP_BINARY || settings.phpBinary },
  });
  if (result.error) {
    console.error('PM2 belum dapat dijalankan: '+result.error.message);
  }
  return result.status ?? 1;
}

module.exports = { invocation };
if (require.main === module) {
  try { process.exitCode = main(process.argv.slice(2)); }
  catch (error) { console.error(error.message); process.exitCode = 1; }
}
