const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const { createConfig } = require('./pm2-apps.cjs');

test('default laptop profile resolves all eight dev services with one manager and scheduler', () => {
  const { apps } = require('../ecosystem.config.js');
  assert.deepEqual(apps.map(app => app.name), [
    'digitaltoko-web', 'digitaltoko-vite', 'digitaltoko-queue', 'digitaltoko-scheduler', 'digitaltoko-bot-manager', 'api-hanshop', 'hanshop-bot', 'digitaltoko-backup-queue',
  ]);
  for (const app of apps) {
    assert.equal(path.isAbsolute(app.cwd), true);
    assert.equal(path.isAbsolute(app.script), true);
    assert.equal(app.instances, 1);
    assert.equal(app.exec_mode, 'fork');
    assert.equal(app.watch, false);
  }
  const queue = apps.find(app => app.name === 'digitaltoko-queue');
  assert.ok(queue.args.includes('--timeout=180'));
  assert.ok(queue.kill_timeout > 180000);
  const backup = apps.find(app=>app.name==='digitaltoko-backup-queue');
  assert.ok(backup.args.includes('--timeout=3600'));
  assert.ok(backup.args.includes('backups'));
  assert.ok(backup.kill_timeout>3600000);
  assert.ok(apps[0].args.includes('--tries=1'));
  assert.ok(apps[1].args.includes('--strictPort'));
  assert.equal(apps[4].script, path.resolve(__dirname, '../apps/bot/scripts/manager-dev.mjs'));
  assert.deepEqual(apps[5].args, ['watch', 'src/server.ts']);
  assert.deepEqual(apps[6].args, ['watch', 'src/index.ts']);
  assert.equal(apps[5].script, path.resolve(__dirname, '../../API Hanshop/node_modules/tsx/dist/cli.mjs'));
});

test('global launcher only redirects bare start and preserves explicit PM2 commands', () => {
  const { invocation } = require('./pm2-global.cjs');
  const settings = { root: path.resolve(__dirname, '..') };
  assert.deepEqual(invocation(['start'], settings), ['start', path.join(settings.root, 'ecosystem.config.js')]);
  for (const args of [['status'], ['logs'], ['restart', 'api-hanshop'], ['start', 'other.config.cjs'], ['start', 'index.js'], ['--version'], []]) {
    assert.deepEqual(invocation(args, settings), args);
  }
});

test('VPS profile excludes development servers and preserves the existing manager name', () => {
  const { apps } = require('./ecosystem.config.cjs');
  assert.deepEqual(apps.map(app => app.name), ['digitaltoko-queue', 'digitaltoko-scheduler', 'digitaltoko-bot-manager', 'digitaltoko-backup-queue']);
  assert.equal(apps[2].env.NODE_ENV, 'production');
  assert.equal(apps[2].script, path.resolve(__dirname, '../apps/bot/dist/manager.js'));
});

test('additional Hanshop services are opt-in and read their own working directories', () => {
  const { apps } = createConfig({ includeHanshop: true, env: {
    DIGITALTOKO_HANSHOP_API_DIR: path.resolve(__dirname, '../path with spaces/api'),
    DIGITALTOKO_HANSHOP_BOT_DIR: path.resolve(__dirname, '../path with spaces/bot'),
  } });
  assert.equal(apps.length, 6);
  assert.equal(apps[3].name, 'api-hanshop');
  assert.equal(apps[4].name, 'hanshop-bot');
  assert.equal(apps[3].cwd, path.resolve(__dirname, '../path with spaces/api'));
  assert.equal(apps[4].script, path.resolve(__dirname, '../path with spaces/bot/dist/index.js'));
  assert.equal(require('./ecosystem.full.config.cjs').apps.length, 8);
});

test('PHP executable and local server overrides remain separate argv values', () => {
  const { apps } = createConfig({ development: true, platform: 'linux', env: {
    DIGITALTOKO_PHP_BINARY: 'C:/Program Files/php/php.exe', DIGITALTOKO_WEB_HOST: '0.0.0.0',
    DIGITALTOKO_WEB_PORT: '8026', DIGITALTOKO_VITE_PORT: '5176',
  } });
  assert.equal(apps[0].interpreter, 'C:/Program Files/php/php.exe');
  assert.ok(apps[0].args.includes('--host=0.0.0.0'));
  assert.ok(apps[0].args.includes('--port=8026'));
  assert.ok(apps[1].args.includes('5176'));
  assert.throws(() => createConfig({ development: true, env: { DIGITALTOKO_WEB_PORT: '0' } }));
  assert.throws(() => createConfig({ development: true, env: { DIGITALTOKO_VITE_PORT: '70000' } }));
});

test('Windows laptop gives PHP a hidden console and preloads hidden Node children only in development', () => {
  const { apps } = createConfig({ development: true, includeHanshop: true, platform: 'win32', env: { NODE_OPTIONS: '--trace-warnings' } });
  for (const app of apps) {
    assert.equal(app.windowsHide, true);
    if (app.name === 'digitaltoko-web' || app.name === 'digitaltoko-queue' || app.name === 'digitaltoko-scheduler' || app.name === 'digitaltoko-backup-queue') {
      assert.equal(app.interpreter, 'none');
      assert.equal(path.basename(app.script), 'hidden-process.exe');
      assert.equal(app.args[1], path.resolve(__dirname, '../apps/web/artisan'));
    } else {
      assert.ok(app.env.NODE_OPTIONS.startsWith('--trace-warnings --require='));
      assert.ok(app.env.NODE_OPTIONS.includes('hide-child-windows.cjs'));
    }
  }
  const production = createConfig({ platform: 'win32', env: {} }).apps;
  assert.equal(production[0].interpreter, 'php');
  assert.equal(production[2].env.NODE_OPTIONS, undefined);
});

for (const buildFails of [false, true]) {
  test(buildFails ? 'manager dev never opens sessions after compilation fails' : 'manager dev compiles before starting the manager', (context) => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'digitaltoko-manager-dev-'));
    context.after(() => {
      assert.equal(path.dirname(path.resolve(root)), path.resolve(os.tmpdir()));
      assert.ok(path.basename(root).startsWith('digitaltoko-manager-dev-'));
      fs.rmSync(root, { recursive: true, force: true });
    });
    for (const dir of ['scripts', 'dist', 'node_modules/typescript/bin']) {
      fs.mkdirSync(path.join(root, dir), { recursive: true });
    }
    fs.writeFileSync(path.join(root, 'package.json'), '{"type":"module"}');
    fs.copyFileSync(path.resolve(__dirname, '../apps/bot/scripts/manager-dev.mjs'), path.join(root, 'scripts/manager-dev.mjs'));
    fs.writeFileSync(path.join(root, 'node_modules/typescript/bin/tsc'), buildFails
      ? 'process.exit(2);'
      : 'require("node:fs").writeFileSync("compiled", "yes");');
    fs.writeFileSync(path.join(root, 'dist/manager.js'), 'import fs from "node:fs"; if (!fs.existsSync("compiled")) throw new Error("not compiled"); console.log("FAKE_MANAGER_STARTED");');
    const result = spawnSync(process.execPath, [path.join(root, 'scripts/manager-dev.mjs')], { cwd: root, encoding: 'utf8', windowsHide: true, timeout: 10000 });
    assert.equal(result.error, undefined);
    assert.equal(result.status, buildFails ? 2 : 0, result.stderr);
    assert.equal(result.stdout.includes('FAKE_MANAGER_STARTED'), !buildFails);
  });
}

const hiddenHost = path.join(os.homedir(), '.digitaltoko', 'bin', 'hidden-process.exe');
test('installed Windows hidden host preserves argv, streams and exit status', { skip: process.platform !== 'win32' || !fs.existsSync(hiddenHost) }, () => {
  const args = ['with spaces', 'literal"quote', 'trailing\\', 'a&b%PATH%^`'];
  const code = 'let data="";process.stdin.on("data",chunk=>data+=chunk);process.stdin.on("end",()=>{console.log(JSON.stringify({args:process.argv.slice(1),data}));console.error("ERR_PROBE");process.exitCode=7;});';
  const result = spawnSync(hiddenHost, [process.execPath, '-e', code, ...args], { encoding: 'utf8', input: 'stdin-probe', windowsHide: true, timeout: 10000 });
  assert.equal(result.error, undefined);
  assert.equal(result.status, 7, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), { args, data: 'stdin-probe' });
  assert.equal(result.stderr.trim(), 'ERR_PROBE');
});

test('installed Windows hidden host supports PHP proc_open descendants', { skip: process.platform !== 'win32' || !fs.existsSync(hiddenHost) }, () => {
  const settings = JSON.parse(fs.readFileSync(path.join(os.homedir(), '.digitaltoko', 'bin', 'launcher.json'), 'utf8'));
  const code = '$p=proc_open([PHP_BINARY,"-r","echo \\"NESTED_OK\\"; exit(9);"],[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]],$pipes);fclose($pipes[0]);echo stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);exit(proc_close($p));';
  const result = spawnSync(hiddenHost, [settings.phpBinary, '-r', code], { encoding: 'utf8', windowsHide: true, timeout: 10000 });
  assert.equal(result.error, undefined);
  assert.equal(result.status, 9, result.stderr);
  assert.equal(result.stdout, 'NESTED_OK');
});

test('Windows Node preload works through NODE_OPTIONS with the actual workspace path', { skip: process.platform !== 'win32' }, () => {
  const app = createConfig({ development: true, env: {}, platform: 'win32' }).apps.find(app => app.name === 'digitaltoko-vite');
  const code = 'const cp=require("node:child_process");const child=cp.spawn(process.execPath,["-e","process.stdout.write(\\"HIDDEN_CHILD_OK\\")"]);child.stdout.pipe(process.stdout);child.stderr.pipe(process.stderr);child.on("exit",code=>process.exitCode=code);';
  const result = spawnSync(process.execPath, ['-e', code], { encoding: 'utf8', windowsHide: true, timeout: 10000, env: { ...process.env, NODE_OPTIONS: app.env.NODE_OPTIONS } });
  assert.equal(result.error, undefined);
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stdout, 'HIDDEN_CHILD_OK');
});
