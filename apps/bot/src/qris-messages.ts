import {mkdir,readFile,writeFile,rename,unlink} from 'node:fs/promises';
import {join} from 'node:path';
import {randomUUID} from 'node:crypto';
export type QrisKey={id:string;remoteJid:string;fromMe:true};
export type MessageContext={kind:'order';revision:string;order_status:string;payment_status:string};
type RecordEntry={order_id:string;phone:string;key:QrisKey}&Partial<MessageContext>;
type Api=(path:string,body?:unknown)=>Promise<unknown>;
export interface QrisTracker {remember(orderId:string,phone:string,key:unknown,context?:MessageContext):Promise<void>;flush():Promise<void>}
export function validQrisKey(value:unknown):value is QrisKey {
 const key=value as QrisKey;
 return !!key&&key.fromMe===true&&typeof key.id==='string'&&/^[A-Za-z0-9_-]{1,200}$/.test(key.id)&&typeof key.remoteJid==='string'&&/^[1-9][0-9]{5,24}@(s\.whatsapp\.net|lid)$/.test(key.remoteJid);
}
function validEntry(value:unknown):value is RecordEntry {
 const item=value as RecordEntry;
 return !!item&&typeof item.order_id==='string'&&/^[a-f0-9-]{36}$/i.test(item.order_id)&&typeof item.phone==='string'&&/^62[1-9][0-9]{7,12}$/.test(item.phone)&&validQrisKey(item.key)
  &&(item.key.remoteJid.endsWith('@lid')||item.key.remoteJid===item.phone+'@s.whatsapp.net');
}
/** Durable registration outbox; backend retains message keys after registration. */
export class QrisMessages implements QrisTracker {
 private operation:Promise<unknown>=Promise.resolve();
 private readonly path:string;
 constructor(private directory:string,private api:Api,private onError:()=>void=()=>{}){this.path=join(directory,'qris-message-outbox.json');}
 private serial<T>(work:()=>Promise<T>):Promise<T>{const result=this.operation.then(work,work);this.operation=result.then(()=>{},()=>{});return result;}
 private async read():Promise<RecordEntry[]>{
  let text:string;
  try{text=await readFile(this.path,'utf8');}catch(error){if((error as NodeJS.ErrnoException).code==='ENOENT')return [];throw error;}
  const entries:unknown=JSON.parse(text);
  if(!Array.isArray(entries)||entries.length>5000||!entries.every(validEntry))throw Error('Invalid QRIS registration outbox');
  return entries;
 }
 private async save(entries:RecordEntry[]){
  await mkdir(this.directory,{recursive:true,mode:0o700});
  const temporary=this.path+'.'+randomUUID()+'.tmp';
  try{await writeFile(temporary,JSON.stringify(entries),{mode:0o600});await rename(temporary,this.path);}
  finally{await unlink(temporary).catch(()=>{});}
 }
 async remember(orderId:string,phone:string,key:unknown,context?:MessageContext):Promise<void>{
  const entry={order_id:orderId,phone,key,...context};
  if(!validEntry(entry))return;
  await this.serial(async()=>{
   const entries=await this.read();
   if(!entries.some(item=>item.order_id===orderId&&item.key.id===entry.key.id&&item.key.remoteJid===entry.key.remoteJid)){
    if(entries.length>=5000)throw Error('QRIS registration outbox is full');
    entries.push({...context,order_id:orderId,phone,key:{id:entry.key.id,remoteJid:entry.key.remoteJid,fromMe:true}});await this.save(entries);
   }
  });
  await this.flush();
 }
 async flush():Promise<void>{
  await this.serial(async()=>{
   const entries=await this.read();
   while(entries.length){
    try{
     const response=await this.api('/api/internal/bot/qris-messages',entries[0]) as {data?:{id?:string}};
     if(typeof response?.data?.id!=='string'||!/^[a-f0-9-]{36}$/i.test(response.data.id))throw Error('QRIS registration not acknowledged');
    }
    catch(error){
     this.onError();
     if(!/^Backend HTTP (404|422)$/.test(error instanceof Error?error.message:''))break;
    }
    entries.shift();await this.save(entries);
   }
  });
 }
}
