import {EventEmitter} from 'node:events';
import {setTimeout as delay} from 'node:timers/promises';
import {DisconnectReason, useMultiFileAuthState} from '@whiskeysockets/baileys';
import * as persistence from '../../dist/utils/credentialPersistence.js';
import {getConnectionRestartDelay} from '../../dist/utils/waVersion.js';
import {hasPairedIdentity} from '../../dist/utils/pairing.js';

export {DisconnectReason, useMultiFileAuthState, getConnectionRestartDelay};
export const {createCredentialSaver, createCredentialUpdateHandler} = persistence;
export const resolveWaWebVersion = async () => ({source:'PACKAGE_DEFAULT', isLatest:false});
export function createAtomicCredentialWriter(...args) {
  const write = persistence.createAtomicCredentialWriter(...args);
  return async () => {
    if (process.env.QR_TEST_STORAGE_FAILURE === 'true') throw Object.assign(new Error('fixture storage failure'), {code:'EIO'});
    await delay(1500);
    await write();
  };
}

let sockets = 0;
export default function makeSocket({auth}) {
  const current = ++sockets;
  const ev = new EventEmitter();
  const emitClose = code => ev.emit('connection.update', {connection:'close',lastDisconnect:{error:{output:{statusCode:code}}}});
  ev.on('creds.update', update => Object.assign(auth.creds, update));
  const sock = {ev, authState:auth, user:auth.creds.me, end:()=>emitClose(428), logout:async()=>emitClose(401), sendMessage:async()=>({key:{id:'fixture-message'}})};
  queueMicrotask(() => {
    if (current === 1) {
      ev.emit('connection.update', {qr:'simulation-qr-not-a-real-session'});
      setTimeout(() => {
        ev.emit('creds.update', {registered:false,me:{id:'6281234567890:7@s.whatsapp.net'},
          account:{accountSignatureKey:Buffer.alloc(32,1),accountSignature:Buffer.alloc(64,2),deviceSignature:Buffer.alloc(64,3)}});
        ev.emit('connection.update', {isNewLogin:true});
        emitClose(515);
      }, 100);
    } else {
      if (!hasPairedIdentity(auth.creds) || !auth.creds.me || auth.creds.registered) {
        throw Error('QR identity was not preserved and flushed before restart');
      }
      sock.user = auth.creds.me;
      ev.emit('connection.update', {connection:'open'});
    }
  });
  return sock;
}
