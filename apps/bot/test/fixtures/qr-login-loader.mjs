import {registerHooks} from 'node:module';

const fixture = new URL('./qr-login-transport.mjs', import.meta.url).href;
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (context.parentURL?.endsWith('/dist/index.js') && [
      '@whiskeysockets/baileys', './utils/credentialPersistence.js', './utils/waVersion.js',
    ].includes(specifier)) return {url: fixture, shortCircuit: true};
    return nextResolve(specifier, context);
  },
});
