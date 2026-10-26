// Equivalent to npm run manager:dev without a shell/npm wrapper process under PM2.
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const compiler = fileURLToPath(new URL('../node_modules/typescript/bin/tsc', import.meta.url));
const result = spawnSync(process.execPath, [compiler], {
  cwd: fileURLToPath(new URL('..', import.meta.url)), stdio: 'inherit', windowsHide: true,
});
if (result.error || result.status !== 0) {
  console.error('Build bot gagal. Periksa log TypeScript sebelum menjalankan manager.');
  process.exit(result.status || 1);
}
await import('../dist/manager.js');
