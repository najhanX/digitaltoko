// Laptop Node watch/build children must not create their own console windows.
// Loaded by NODE_OPTIONS before dependencies capture child_process methods.
if (process.platform === 'win32') {
  const cp = require('node:child_process');
  const marker = Symbol.for('digitaltoko.hiddenChildren');
  if (!cp[marker]) {
    cp[marker] = true;
    for (const method of ['spawn', 'spawnSync', 'fork']) {
      const original = cp[method];
      cp[method] = function (file, args, options) {
        if (Array.isArray(args)) return original.call(this, file, args, { ...options, windowsHide: true });
        return original.call(this, file, { ...args, windowsHide: true });
      };
    }
    require('node:module').syncBuiltinESMExports();
  }
}
