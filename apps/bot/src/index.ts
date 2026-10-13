import express from 'express';
import pino from 'pino';
import makeWASocket,{Browsers,DisconnectReason,useMultiFileAuthState} from '@whiskeysockets/baileys';
import { timingSafeEqual } from 'node:crypto';
import { mkdir,open,unlink } from 'node:fs/promises';
import { join } from 'node:path';
import { loadConfig } from './config.js';
import { MenuTokens,type MenuAction } from './menu.js';
import { sendMenu } from './interactive.js';
import { createAtomicCredentialWriter,createCredentialSaver,createCredentialUpdateHandler } from './utils/credentialPersistence.js';
import { resolveWaWebVersion,getRejectedConnectionDelay } from './utils/waVersion.js';

const config=loadConfig();
const logger=pino({level:process.env.LOG_LEVEL??'info',redact:['token','qr','creds','message']});
const tokens=new MenuTokens();
let state:{status:string;qr?:string}={status:'STOPPED'};
let socket:ReturnType<typeof makeWASocket>|undefined;
let reconnect:ReturnType<typeof setTimeout>|undefined;
let starting=false,stopping=false,failures=0;
let lockPath:string|undefined;

async function api(path:string,body?:unknown) {
    const response=await fetch(new URL(path,config.webBaseUrl),{method:body?'POST':'GET',headers:{Authorization:`Bearer ${config.token}`,Accept:'application/json',...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify(body)}:{}),signal:AbortSignal.timeout(5000)});
    if(!response.ok) throw new Error(`Backend HTTP ${response.status}`);
    return response.json();
}
async function report(next:typeof state) {
    state=next;
    try {await api('/api/internal/bot/events',next);} catch {logger.warn('Status belum diterima backend; credential tidak dicetak.');}
}
async function startSocket() {
    if(starting||stopping) return;
    starting=true;
    try {
        const access=await api('/api/internal/bot/access');
        if(!access.data.can_connect) {await report({status:'STOPPED'});return;}
        const {state:auth}=await useMultiFileAuthState(config.sessionPath);
        const version=await resolveWaWebVersion();
        const save=createCredentialSaver(createAtomicCredentialWriter(config.sessionPath,()=>auth.creds));
        const sock=makeWASocket({auth,logger:pino({level:'silent'}),browser:Browsers.macOS('Google Chrome'),...(version.version?{version:version.version}:{}),markOnlineOnConnect:false,syncFullHistory:false});
        socket=sock;
        sock.ev.on('creds.update',createCredentialUpdateHandler(save,()=>{stopping=true;clearTimeout(reconnect);sock.end(new Error('Credential persistence failed'));void report({status:'STOPPED'});}));
        sock.ev.on('connection.update',async update=>{
            if(update.qr) await report({status:'PAIRING',qr:update.qr});
            if(update.connection==='open'){failures=0;await report({status:'CONNECTED'});}
            if(update.connection==='close'){
                await report({status:'DISCONNECTED'});
                const code=(update.lastDisconnect?.error as {output?:{statusCode?:number}}|undefined)?.output?.statusCode;
                if(stopping||code===DisconnectReason.loggedOut) return;
                reconnect=setTimeout(()=>{void startSocket().catch(()=>logger.warn('Reconnect gagal.'));},getRejectedConnectionDelay(++failures));
            }
        });
        sock.ev.on('messages.upsert',async batch=>{
            if(batch.type!=='notify'||stopping) return;
            for(const message of batch.messages){
                const chat=message.key.remoteJid;
                if(!chat||message.key.fromMe||chat==='status@broadcast'||chat.endsWith('@g.us')) continue;
                const sender=message.key.participant??chat;
                try {
                    const access=await api('/api/internal/bot/access');
                    if(!access.data.allowed) continue;
                    const content=message.message;
                    const text=(content?.conversation??content?.extendedTextMessage?.text??'').trim().toLowerCase();
                    let action:MenuAction|null=null;
                    const params=content?.interactiveResponseMessage?.nativeFlowResponseMessage?.paramsJson;
                    if(params){try{const value=JSON.parse(params);if(typeof value.id==='string') action=tokens.consume(value.id,chat,sender);}catch{}}
                    if(text==='#menu'||text==='#start'){await sendMenu(sock,message,chat,sender,tokens,access.data.store_name);continue;}
                    action??=text==='#produk'?'products':text==='#status'?'status':text==='#saldo'?'balance':null;
                    if(action) await sock.sendMessage(chat,{text:'Layanan katalog, status pesanan, dan saldo belum diaktifkan untuk bot fondasi ini. Hubungi owner toko.'},{quoted:message});
                }catch{logger.warn('Pesan tidak diproses: backend/transport belum siap.');}
            }
        });
        await report({status:'CONNECTING'});
    } finally {starting=false;}
}

const app=express();
app.get('/health',(_req,res)=>res.json({status:state.status,store_id:config.storeId}));
app.get('/state',(req,res)=>{
    const actual=Buffer.from((req.headers.authorization??'').replace(/^Bearer /,''));
    const expected=Buffer.from(config.token);
    if(actual.length!==expected.length||!timingSafeEqual(actual,expected)){res.sendStatus(401);return;}
    res.json(state);
});
const server=app.listen(config.port,'127.0.0.1',async()=>{
    logger.info({store_id:config.storeId,port:config.port},'Worker API siap pada loopback.');
    if(!config.waEnabled) return;
    try {
        await mkdir(config.sessionPath,{recursive:true,mode:0o700});
        const candidate=join(config.sessionPath,'.worker.lock');
        const file=await open(candidate,'wx',0o600);
        lockPath=candidate;
        await file.writeFile(String(process.pid));await file.close();
        await startSocket();
    }catch{logger.error('Worker WA gagal start atau sesi dikunci proses lain.');await shutdown();process.exitCode=1;}
});
const heartbeat=setInterval(async()=>{
    if(!config.waEnabled||stopping||state.status==='STOPPED') return;
    try {
        const access=await api('/api/internal/bot/access');
        if(!access.data.can_connect){stopping=true;clearTimeout(reconnect);socket?.end(new Error('Store access ended'));await report({status:'STOPPED'});}
        else await report(state);
    }catch{logger.warn('Heartbeat backend belum tersedia.');}
},30_000);
async function shutdown(){stopping=true;clearTimeout(reconnect);clearInterval(heartbeat);socket?.end(new Error('Worker shutdown'));server.close();if(lockPath) await unlink(lockPath).catch(()=>{});}
process.on('SIGINT',()=>{void shutdown();});process.on('SIGTERM',()=>{void shutdown();});
