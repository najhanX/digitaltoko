import type { WAMessage,WASocket } from '@whiskeysockets/baileys';
import { MenuTokens,type MenuAction } from './menu.js';
import { readCustomerMessage } from './messages.js';
import { sendControls } from './interactive.js';
import { shopReply,inline } from './replies.js';
export type PurchaseAction={type:'purchase';productId:string;code:string;destination:string;price:number;payment:'wallet'|'gateway';inquiryId?:string;voucherCode?:string};
type Api=(path:string,body?:unknown)=>Promise<unknown>;
const money=(v:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(v);
const states:Record<string,string>={awaiting_payment:'Menunggu pembayaran',paid:'Lunas · antre diproses',processing:'Diproses',pending:'Pending pemasok',success:'Berhasil',failed:'Gagal',expired:'Kedaluwarsa',review:'Perlu pemeriksaan toko'};
export function commerceInput(text:string,action:MenuAction|null):boolean{return action==='balance'||action==='status'||typeof action==='object'&&action?.type==='purchase'||/^#(?:beli|pesan|topup)(?:\s|$)/i.test(text);}
function phone(message:WAMessage):string|null{return readCustomerMessage(message).scope.senderAliases?.find(jid=>/^62[1-9][0-9]{7,12}@s\.whatsapp\.net$/.test(jid))?.split('@')[0] ?? null;}
type Order={is_sandbox?:boolean;gateway?:string;id:string;number:string;status:string;product:string;destination:string;total:number;amount:number;fee:number;unique_code:number;url:string;result?:string|null;qr_image?:string|null;refunded_at?:string|null;refund_amount:number;voucher?:{code:string;discount:number}|null};
export function orderReply(name:string,o:Order):string{return shopReply(name,'PESANAN '+inline(o.number),[
 ...(o.is_sandbox?['*SANDBOX · PENGUJIAN*','Saldo dan hasil hanya untuk pengujian.','']:[]),
 '*Status* : '+(states[o.status]??'Sedang diperiksa'),'*Produk* : '+inline(o.product),'*Tujuan* : '+inline(o.destination),
 ...(o.voucher?['*Voucher* : '+inline(o.voucher.code)+' · −'+money(o.voucher.discount)]:[]),
 '*Harga* : '+money(o.amount),'*Biaya* : '+money(o.fee),'*Kode unik* : '+money(o.unique_code),'*Total* : '+money(o.total),
 ...(o.refunded_at?['*Refund saldo toko* : '+money(o.refund_amount)]:[]),
 ...(o.status==='success'&&o.result?['','*Hasil pesanan*',o.result]:[]),'','Cek manual : #status '+o.number,(o.gateway==='sandbox'?'Simulasikan pembayaran di halaman pesanan : ':'Bukti & pembayaran : ')+o.url]);}
export async function handleCommerce(sock:WASocket,message:WAMessage,tokens:MenuTokens,api:Api,name:string,action:MenuAction|null):Promise<void>{
 const chat=message.key.remoteJid!,number=phone(message),text=readCustomerMessage(message).text;
 if(!number){await sock.sendMessage(chat,{text:shopReply(name,'NOMOR BELUM DIKENALI',['Nomor WhatsApp belum tersedia dari sesi pesan.','Buka akun toko melalui web dan gunakan OTP WhatsApp.'])},{quoted:message});return;}
 async function call(data:Record<string,unknown>):Promise<Record<string,unknown>>{const response=await api('/api/internal/bot/commerce',{phone:number,...data}) as {data?:Record<string,unknown>};if(!response?.data)throw Error('Invalid commerce response');return response.data;}
 async function sendOrder(order:Order){await sock.sendMessage(chat,{text:orderReply(name,order)},{quoted:message});
  try{if(order.status==='awaiting_payment' && order.qr_image){
   if(/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(order.qr_image) && order.qr_image.length<200000)await sock.sendMessage(chat,{image:Buffer.from(order.qr_image.split(',')[1],'base64'),caption:'Bayar tepat '+money(order.total)+' · '+order.number},{quoted:message});
   else{const url=new URL(order.qr_image);if(url.protocol==='https:'&&['api.midtrans.com','api.sandbox.midtrans.com'].includes(url.hostname))await sock.sendMessage(chat,{image:{url:url.href},caption:'Bayar tepat '+money(order.total)+' · '+order.number},{quoted:message});}
  }}catch{/* Receipt link remains usable when image delivery fails. */}
 }
 try{
  if(action==='balance'){
   const data=await call({action:'balance'});if(!Number.isSafeInteger(data.balance)||Number(data.balance)<0)throw Error('Invalid balance');
   await sock.sendMessage(chat,{text:shopReply(name,'SALDO PELANGGAN',['Saldo : *'+money(Number(data.balance))+'*',data.sandbox?'SANDBOX · Saldo pengujian, terpisah dari saldo asli.':'Saldo berlaku khusus di toko ini.','','Topup : #topup 50000','Beli : #beli KODE TUJUAN'])},{quoted:message});
  }else if(action==='status'){
   const match=text.match(/^#(?:status|cektrx)\s+((?:DT|SB)[A-Z0-9]+)$/i),data=await call({action:'status',...(match?{number:match[1].toUpperCase()}:{})});
   const orders=data.orders as Order[];if(!Array.isArray(orders))throw Error('Invalid orders');
   if(match && orders.length)await sendOrder(orders[0]);else await sock.sendMessage(chat,{text:shopReply(name,'RIWAYAT PESANAN',orders.length?[...orders.flatMap(o=>[inline(o.number)+' · '+(states[o.status]??'Diproses'),inline(o.product)+' · '+money(o.total),'Cek : #status '+o.number,'']),'Hanya pesanan untuk nomor WhatsApp Anda yang ditampilkan.']:['Belum ada pesanan untuk nomor WhatsApp Anda.','Cek pesanan : #status NOMOR_PESANAN','Beli : #beli KODE TUJUAN'])},{quoted:message});
  }else if(typeof action==='object' && action?.type==='purchase'){
   if(!message.key.id)throw Error('No message reference');
   const data=await call({action:'buy',product_id:action.productId,destination:action.destination,payment:action.payment,expected_price:action.price,inquiry_id:action.inquiryId,voucher_code:action.voucherCode,idempotency_key:'bot:'+message.key.id});await sendOrder(data.order as Order);
  }else if(/^#topup(?:\s|$)/i.test(text)){
   const amount=text.slice(6).trim().replaceAll('.','');if(!/^[0-9]{4,9}$/.test(amount)||Number(amount)<5000||!message.key.id){await sock.sendMessage(chat,{text:shopReply(name,'FORMAT TOPUP',['Format : #topup 50000','Minimal Rp5.000. Saldo masuk setelah pembayaran sah.'])},{quoted:message});return;}
   const data=await call({action:'topup',amount:Number(amount),destination:number,payment:'gateway',idempotency_key:'bot:'+message.key.id});await sendOrder(data.order as Order);
  }else{
   const confirm=/^#pesan\s+([^|]+)\|([^|]+)\|(saldo|qris)\|([0-9]+)(?:\|([a-f0-9-]{36})?)?(?:\|([A-Z0-9_-]{3,40}))?$/i.exec(text),match=/^#beli\s+(\S+)\s+(.+)$/i.exec(text);
   if(!confirm && !match){await sock.sendMessage(chat,{text:shopReply(name,'FORMAT PEMESANAN',['Format : #beli KODE TUJUAN','Contoh : #beli TSEL10 081234567890','Dengan voucher : #beli KODE TUJUAN | KODEVOUCHER','Periksa harga dan tujuan, lalu konfirmasi pembayaran.'])},{quoted:message});return;}
   const code=(confirm?.[1]??match![1]).trim(),parts=(confirm?.[2]??match![2]).split('|'),destination=parts[0].trim(),voucherCode=(confirm?.[6]??parts[1]?.trim())?.toUpperCase();
   if(parts.length>2 || voucherCode && !/^[A-Z0-9_-]{3,40}$/.test(voucherCode))throw Error('Invalid voucher');
   const quote=await call({action:'quote',code}),product=quote.product as {id:string;name:string;price:number;available:boolean;kind:string};
   if(!product?.id || !Number.isSafeInteger(product.price))throw Error('Invalid product');
   if(confirm){if(!message.key.id)throw Error('No message reference');const data=await call({action:'buy',product_id:product.id,destination,payment:confirm[3].toLowerCase()==='saldo'?'wallet':'gateway',expected_price:Number(confirm[4]),inquiry_id:confirm[5]||undefined,voucher_code:voucherCode,idempotency_key:'bot:'+message.key.id});await sendOrder(data.order as Order);return;}
   if(!product.available){await sock.sendMessage(chat,{text:shopReply(name,'PRODUK BELUM DAPAT DIPESAN',[inline(product.name),product.kind==='postpaid'?'Produk tagihan memerlukan pemeriksaan nominal terlebih dahulu.':'Produk sedang tidak tersedia.'])},{quoted:message});return;}
   let price=product.price,inquiryId:string|undefined,billInfo:string[]=[];
   if(product.kind==='postpaid'){
    if(!message.key.id)throw Error('No message reference');
    const data=await call({action:'inquiry',product_id:product.id,destination,idempotency_key:'inquiry:'+message.key.id});
    const inquiry=data.inquiry as {id:string;amount:number;customer_name:string;period:string};
    if(!inquiry?.id||!Number.isSafeInteger(inquiry.amount))throw Error('Invalid inquiry');
    price=inquiry.amount;inquiryId=inquiry.id;billInfo=['Nama : '+inline(inquiry.customer_name),'Periode : '+inline(inquiry.period),'Konfirmasi tagihan dalam 5 menit.'];
   }
   let voucher:{code:string;discount:number;total:number}|undefined;
   if(voucherCode){const promotion=await call({action:'quote',code,voucher_code:voucherCode,inquiry_id:inquiryId});voucher=promotion.voucher as typeof voucher;}
   const purchase=(payment:'wallet'|'gateway'):PurchaseAction=>({type:'purchase',productId:product.id,code,destination,price,payment,...(inquiryId?{inquiryId}:{}),...(voucherCode?{voucherCode}:{})});
   const suffix=(inquiryId||voucherCode?'|'+(inquiryId??''):'')+(voucherCode?'|'+voucherCode:'');
   await sock.sendMessage(chat,{text:shopReply(name,'PERIKSA PESANAN',[...(quote.sandbox?['*SANDBOX · PENGUJIAN*']:[]),inline(product.name),'Tujuan : '+inline(destination),...billInfo,'Harga : *'+money(price)+'*',...(voucher?['Voucher : '+inline(voucher.code)+' · −'+money(voucher.discount),'Setelah diskon : *'+money(voucher.total)+'*']:[]),'',quote.sandbox?'Pembayaran uji tersedia melalui tautan pesanan.':'QRIS dapat memiliki fee / kode unik pada invoice.','Saldo : #pesan '+code+'|'+destination+'|saldo|'+price+suffix,'QRIS : #pesan '+code+'|'+destination+'|qris|'+price+suffix,'','Konfirmasi hanya jika produk dan tujuan sudah benar.'])},{quoted:message});
   await sendControls(sock,message,chat,chat,tokens,[[purchase('wallet'),'Bayar saldo'],[purchase('gateway'),'Bayar QRIS'],['menu','Batal / menu']],'Konfirmasi pemesanan');
  }
 }catch{await sock.sendMessage(chat,{text:shopReply(name,'PERMINTAAN BELUM SELESAI',['Periksa kode produk, tujuan, saldo dan gateway toko.','Jika baru mengonfirmasi pesanan, cek #status terlebih dahulu.','Jangan membuat pesanan baru saat status belum pasti.'])},{quoted:message});}
}
export async function deliverNotices(sock:WASocket,api:Api):Promise<void>{
 const response=await api('/api/internal/bot/notices') as {data?:{id:string;phone:string;message:string}[]};
 if(!Array.isArray(response.data))return;
 for(const notice of response.data.slice(0,10)){
  if(!/^[a-f0-9-]{36}$/i.test(notice.id)||!/^62[1-9][0-9]{7,12}$/.test(notice.phone)||typeof notice.message!=='string'||notice.message.length>40000)continue;
  await sock.sendMessage(notice.phone+'@s.whatsapp.net',{text:notice.message});await api('/api/internal/bot/notices/'+notice.id+'/delivered',{});
 }
}

export function createNoticePump(socket:()=>WASocket|undefined,api:Api,onError:()=>void){
 let running=false,requested=false;
 async function trigger():Promise<void>{
  if(running){requested=true;return;}
  if(!socket())return;
  running=true;
  try{do{requested=false;const current=socket();if(!current)break;await deliverNotices(current,api);}while(requested);}
  catch{onError();}finally{running=false;}
 }
 return {trigger};
}
