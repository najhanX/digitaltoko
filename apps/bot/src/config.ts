import 'dotenv/config';
import { resolve } from 'node:path';

export function loadConfig(env: NodeJS.ProcessEnv = process.env) {
    const storeId=env.STORE_ID??'';
    if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(storeId)) throw new Error('STORE_ID harus UUID toko.');
    const token=env.WORKER_TOKEN??'';
    if (token.length<32) throw new Error('WORKER_TOKEN minimal 32 karakter.');
    const web=new URL(env.WEB_BASE_URL??'http://localhost:8000');
    if (!['http:','https:'].includes(web.protocol) || web.username || web.password) throw new Error('WEB_BASE_URL harus HTTP(S) tanpa credential URL.');
    const port=Number(env.PORT??3100);
    if (!Number.isInteger(port)||port<(env.CONNECTION_ID?0:1)||port>65535) throw new Error('PORT tidak valid.');
    const connectionId=env.CONNECTION_ID;
    if(connectionId && !/^[0-9a-f-]{36}$/i.test(connectionId)) throw new Error('CONNECTION_ID tidak valid.');
    const phone=env.BOT_PHONE;
    if(phone && !/^[1-9][0-9]{7,14}$/.test(phone)) throw new Error('BOT_PHONE tidak valid.');
    const method=env.PAIRING_METHOD??'qr';
    if(!['qr','code'].includes(method) || (method==='code'&&!phone)) throw new Error('Metode pairing tidak valid.');
    return {storeId,token,port,webBaseUrl:web.href,sessionPath:resolve(env.SESSION_ROOT??'sessions',storeId),waEnabled:env.WA_ENABLED==='true',connectionId,phone,method:method as 'qr'|'code'};
}
