import type { WAMessage,WASocket } from '@whiskeysockets/baileys';
import { MenuTokens,type MenuAction } from './menu.js';
import { readCustomerMessage } from './messages.js';
import { sendControls,sendChoices,sendNoticeControls } from './interactive.js';
import { shopReply,inline,topupText,expiredMenuText } from './replies.js';
import {validQrisKey,type QrisTracker,type MessageContext} from './qris-messages.js';
export type PurchaseAction={type:'purchase';productId:string;code:string;destination:string;price:number;payment:'wallet'|'gateway';inquiryId?:string;voucherCode?:string};
type Api=(path:string,body?:unknown)=>Promise<unknown>;
const money=(v:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(v);
const states:Record<string,string>={awaiting_payment:'Menunggu pembayaran',paid:'Lunas · antre diproses',processing:'Diproses',pending:'Pending pemasok',success:'Berhasil',failed:'Gagal',expired:'Kedaluwarsa',review:'Perlu pemeriksaan toko'};
export function commerceInput(text:string,action:MenuAction|null):boolean{return action==='balance'||action==='status'||action==='topup'||typeof action==='object'&&(action?.type==='purchase'||action?.type==='order-status'||action?.type==='select-product'||action?.type==='product-input'||action?.type==='payment-method'||action?.type==='confirm-checkout')||/^#(?:beli|pesan|topup)(?:\s|$)/i.test(text);}
function phone(message:WAMessage):string|null{return readCustomerMessage(message).scope.senderAliases?.find(jid=>/^62[1-9][0-9]{7,12}@s\.whatsapp\.net$/.test(jid))?.split('@')[0] ?? null;}
type Order={expires_at?:string|null;wa_revision?:string;payment_status?:string;is_sandbox?:boolean;gateway?:string;id:string;number:string;description?:string|null;postpaid_breakdown?:{bill:number;service_admin:number;store_admin:number}|null;status:string;product:string;destination:string;total:number;amount:number;fee:number;unique_code:number;url:string;result?:string|null;qr_image?:string|null;refunded_at?:string|null;refund_amount:number;voucher?:{code:string;discount:number}|null};
function paymentDeadline(o:Order,now:number):string[]{
 if(o.status!=='awaiting_payment'||o.payment_status==='paid'||!o.expires_at)return [];
 const expiry=new Date(o.expires_at),remaining=expiry.getTime()-now;
 if(!Number.isFinite(remaining))return [];
 const parts=new Intl.DateTimeFormat('id-ID',{timeZone:'Asia/Jakarta',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(expiry);
 const part=(type:string)=>parts.find(item=>item.type===type)?.value??'';
 const deadline=part('day')+'/'+part('month')+'/'+part('year')+' '+part('hour')+':'+part('minute')+':'+part('second')+' WIB';
 if(remaining<=0)return ['Batas bayar: *'+deadline+'*','Batas pembayaran sudah lewat. Jangan gunakan QRIS ini.'];
 const seconds=Math.ceil(remaining/1000),hours=Math.floor(seconds/3600),minutes=Math.floor(seconds%3600/60);
 const duration=hours?hours+' jam'+(minutes?' '+minutes+' menit':''):minutes?minutes+' menit'+(seconds%60?' '+seconds%60+' detik':''):seconds+' detik';
 return ['Batas bayar: *'+deadline+'*','Sisa saat pesan dikirim: '+duration+'.'];
}
export function orderReply(name:string,o:Order,now=Date.now()):string{return shopReply(name,({success:'✅',failed:'❌',expired:'⌛',awaiting_payment:'💳'}[o.status]??'⏳')+' PESANAN '+inline(o.number),[
 ...(o.is_sandbox?['*SANDBOX · PENGUJIAN*','Saldo dan hasil hanya untuk pengujian.','']:[]),
 '*Status* : '+(states[o.status]??'Sedang diperiksa'),...(o.description?['Keterangan : '+inline(o.description,500)]:[]),'','*Rincian pesanan*','Produk : '+inline(o.product),'Tujuan : '+inline(o.destination),
 ...(o.voucher?['*Voucher* : '+inline(o.voucher.code)+' · −'+money(o.voucher.discount)]:[]),
 ...(o.postpaid_breakdown?['Tagihan pokok : '+money(o.postpaid_breakdown.bill),'Admin layanan : '+money(o.postpaid_breakdown.service_admin),'Admin toko : '+money(o.postpaid_breakdown.store_admin)]:[]),
 '','*Pembayaran*','Harga : '+money(o.amount),...(o.fee?['Biaya : '+money(o.fee)]:[]),...(o.unique_code?['Kode unik : '+money(o.unique_code)]:[]),'Total : *'+money(o.total)+'*',...paymentDeadline(o,now),
 ...(o.refunded_at?['*Refund saldo toko* : '+money(o.refund_amount)]:[]),
 ...(o.status==='success'&&o.result?['','*Hasil pesanan*',o.result]:[]),'',o.status==='success'?'Terima kasih sudah berbelanja! Simpan bukti pesanan ini.':o.status==='awaiting_payment'?'Selesaikan pembayaran sesuai nominal tagihan.':['paid','processing','pending'].includes(o.status)?'Pesanan sedang ditangani. Tidak perlu membuat pesanan baru.':o.status==='failed'?'Periksa rincian dan pengembalian pada bukti pesanan.':'Lihat informasi terbaru pada halaman pesanan.','', 'Cek manual : #status '+o.number,(o.gateway==='sandbox'?'Simulasikan pembayaran di halaman pesanan : ':o.status==='awaiting_payment'?'Bukti & pembayaran : ':'Bukti pesanan : ')+o.url]);}
export async function handleCommerce(sock:WASocket,message:WAMessage,tokens:MenuTokens,api:Api,name:string,action:MenuAction|null,qris?:QrisTracker):Promise<void>{
 const chat=message.key.remoteJid!,number=phone(message),text=readCustomerMessage(message).text;
 if(!number){await sock.sendMessage(chat,{text:shopReply(name,'NOMOR BELUM DIKENALI',['Nomor WhatsApp belum tersedia dari sesi pesan.','Buka akun toko melalui web dan gunakan OTP WhatsApp.'])},{quoted:message});return;}
 async function call(data:Record<string,unknown>):Promise<Record<string,unknown>>{const response=await api('/api/internal/bot/commerce',{phone:number,...data}) as {data?:Record<string,unknown>};if(!response?.data)throw Error('Invalid commerce response');return response.data;}
 let checkoutKeys:unknown[]=[];
 const scope=readCustomerMessage(message).scope;
 async function remember(order:Order,key:unknown,context:MessageContext){try{await qris?.remember(order.id,number!,key,context);}catch{/* Delivery must remain independent of registration retries. */}}
 async function sendOrder(order:Order){
  const context:MessageContext={kind:'order',revision:order.wa_revision??'none',order_status:order.status,payment_status:order.payment_status??'unpaid'};
  for(const key of checkoutKeys)await remember(order,key,{...context,revision:'checkout'});
  const receipt=await sock.sendMessage(chat,{text:orderReply(name,order)},{quoted:message});
  if(receipt?.key)await remember(order,receipt.key,context);
  const caption=['Bayar tepat '+money(order.total)+' · '+order.number,...paymentDeadline(order,Date.now())].join('\n');
  const expired=order.expires_at && Number.isFinite(Date.parse(order.expires_at)) && Date.parse(order.expires_at)<=Date.now();
  try{if(order.status==='awaiting_payment' && order.payment_status!=='paid' && !expired && order.qr_image){
   let sent:WAMessage|undefined;
   if(/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(order.qr_image) && order.qr_image.length<200000)sent=await sock.sendMessage(chat,{image:Buffer.from(order.qr_image.split(',')[1],'base64'),caption},{quoted:message});
   else{const url=new URL(order.qr_image);if(url.protocol==='https:'&&['api.midtrans.com','api.sandbox.midtrans.com'].includes(url.hostname))sent=await sock.sendMessage(chat,{image:{url:url.href},caption},{quoted:message});}
   if(sent?.key&&qris)await qris.remember(order.id,number!,sent.key);
  }}catch{/* Receipt link remains usable when image delivery fails. */}
  const controls=await sendControls(sock,message,chat,chat,tokens,[[{type:'order-status',number:order.number},'Cek status pesanan'],['services','Belanja lagi'],['menu','Menu utama']],'Pilih langkah berikutnya');
  if(controls)await remember(order,controls,context);
 }
 try{
  if(typeof action==='object'&&(action?.type==='payment-method'||action?.type==='confirm-checkout')){
   const scope=readCustomerMessage(message).scope;
   if(action.type==='payment-method'){
    const checkout=tokens.choosePayment(chat,chat,action.checkoutId,action.payment,scope);
    if(!checkout){await sock.sendMessage(chat,{text:expiredMenuText(name)},{quoted:message});return;}
    for(const key of checkout.messages)if(validQrisKey(key))try{await sock.sendMessage(key.remoteJid,{delete:key});}catch{}
    const confirmation=await sock.sendMessage(chat,{text:shopReply(name,'✅ KONFIRMASI PEMBELIAN',[...checkout.summary,'','Pembayaran : *'+(action.payment==='wallet'?'Saldo toko':'QRIS')+'*',...(action.payment==='gateway'?['Biaya/kode unik mengikuti invoice.']:[]),'','#konfirmasi untuk memesan · #batal untuk membatalkan.'])},{quoted:message});
    tokens.rememberCheckoutMessage(chat,chat,confirmation?.key,scope);
    const confirmationControls=await sendControls(sock,message,chat,chat,tokens,[[{type:'confirm-checkout',checkoutId:checkout.id},'Konfirmasi pesanan'],['menu','Batal / menu']],'Lanjutkan hanya jika rincian sudah benar');
    tokens.rememberCheckoutMessage(chat,chat,confirmationControls,scope);return;
   }
   checkoutKeys=[...tokens.checkoutMessages(chat,chat,scope)];
   const purchase=tokens.confirmCheckout(chat,chat,action.checkoutId,scope);
   if(!purchase){await sock.sendMessage(chat,{text:expiredMenuText(name)},{quoted:message});return;}
   action=purchase;
  }
  if(action==='balance'){
   const data=await call({action:'balance'});if(!Number.isSafeInteger(data.balance))throw Error('Invalid balance');
   await sock.sendMessage(chat,{text:shopReply(name,'💰 SALDO PELANGGAN',['*'+money(Number(data.balance))+'*',data.sandbox?'SANDBOX · Saldo pengujian, terpisah dari saldo asli.':'Saldo berlaku khusus di toko ini.',...(Number(data.balance)<0?['','Saldo Anda minus. Isi saldo untuk melunasinya sebelum bertransaksi.']:[]),'','Topup : #topup 50000'])},{quoted:message});
   await sendControls(sock,message,chat,chat,tokens,[['topup','Isi saldo'],['services','Lihat produk'],['status','Status pesanan']],'Kelola saldo toko Anda');
  }else if(action==='topup'){
   await sock.sendMessage(chat,{text:topupText(name)},{quoted:message});
   await sendControls(sock,message,chat,chat,tokens,[['balance','Saldo pelanggan'],['menu','Menu utama']],'Ketik nominal topup sesuai contoh');
  }else if(action==='status'||typeof action==='object'&&action?.type==='order-status'){
   const match=text.match(/^#(?:status|cektrx)\s+((?:DT|SB)[A-Z0-9]+)$/i),requested=typeof action==='object'?action.number:match?.[1].toUpperCase();
   if(requested&&!/^(?:DT|SB)[A-Z0-9]{1,80}$/i.test(requested))throw Error('Invalid order number');
   const data=await call({action:'status',...(requested?{number:requested}:{})});
   const orders=data.orders as Order[];if(!Array.isArray(orders))throw Error('Invalid orders');
   if(requested && orders.length)await sendOrder(orders[0]);else {
    await sock.sendMessage(chat,{text:shopReply(name,'🧾 RIWAYAT PESANAN',orders.length?[...orders.flatMap(o=>['*'+inline(o.product)+'*',inline(o.number)+' · '+(states[o.status]??'Diproses'),'Total : '+money(o.total),'Cek : #status '+o.number,'']),'Hanya pesanan untuk nomor WhatsApp Anda yang ditampilkan.']:['Belum ada pesanan untuk nomor WhatsApp Anda.','Pilih produk untuk mulai berbelanja.'])},{quoted:message});
    await sendChoices(sock,message,chat,chat,tokens,orders.slice(0,10).map(o=>({title:inline(o.number),description:inline(o.product)+' · '+(states[o.status]??'Diproses'),action:{type:'order-status',number:o.number}})),'Pilih pesanan');
    await sendControls(sock,message,chat,chat,tokens,[['services','Lihat produk'],['balance','Saldo pelanggan'],['menu','Menu utama']],'Mau lanjut ke mana?');
   }
  }else if(typeof action==='object' && action?.type==='purchase'){
   if(!message.key.id)throw Error('No message reference');
   const data=await call({action:'buy',product_id:action.productId,destination:action.destination,payment:action.payment,expected_price:action.price,inquiry_id:action.inquiryId,voucher_code:action.voucherCode,idempotency_key:'bot:'+message.key.id});await sendOrder(data.order as Order);
  }else if(/^#topup(?:\s|$)/i.test(text)){
   const amount=text.slice(6).trim().replaceAll('.','');if(!/^[0-9]{4,9}$/.test(amount)||Number(amount)<5000||!message.key.id){await sock.sendMessage(chat,{text:topupText(name)},{quoted:message});return;}
   const data=await call({action:'topup',amount:Number(amount),destination:number,payment:'gateway',idempotency_key:'bot:'+message.key.id});await sendOrder(data.order as Order);
  }else{
   const guided=typeof action==='object'&&(action?.type==='select-product'||action?.type==='product-input')?action:null;
   const confirm=/^#pesan\s+([^|]+)\|([^|]+)\|(saldo|qris)\|([0-9]+)(?:\|([a-f0-9-]{36})?)?(?:\|([A-Z0-9_-]{3,40}))?$/i.exec(text),match=/^#beli\s+(\S+)(?:\s+(.+))?$/i.exec(text);
   if(!guided&&!confirm && !match){await sock.sendMessage(chat,{text:shopReply(name,'FORMAT PEMESANAN',['Format : #beli KODE TUJUAN','Contoh : #beli TSEL10 081234567890','Dengan voucher : #beli KODE TUJUAN | KODEVOUCHER','Periksa harga dan tujuan, lalu konfirmasi pembayaran.'])},{quoted:message});return;}
   const code=(guided?.code??confirm?.[1]??match![1]).trim(),parts=(guided?number:(confirm?.[2]??match?.[2]??'')).split('|'),voucherCode=(confirm?.[6]??parts[1]?.trim())?.toUpperCase();
   let destination=parts[0].trim();
   if(confirm&&/%[0-9a-f]{2}/i.test(destination))destination=decodeURIComponent(destination);
   if(parts.length>2 || voucherCode && !/^[A-Z0-9_-]{3,40}$/.test(voucherCode))throw Error('Invalid voucher');
   const destinationInput=guided?.type==='product-input'?guided.input:null;
   const fields=destinationInput===null?null:destinationInput.split(';').map(value=>value.trim());
   if(fields&&(fields.length>2||!fields[0]||destinationInput!.length>255||fields.some(value=>/[\r\n]/.test(value))))throw Error('Invalid destination');
   const quote=await call({action:'quote',code,...(fields?{destination_id:fields[0],destination_server:fields[1]??''}:{})}),product=quote.product as {id:string;name:string;price:number;available:boolean;kind:string;postpaid_fees?:{service_admin:number;store_admin:number}|null};
   if(!product?.id || !Number.isSafeInteger(product.price))throw Error('Invalid product');
   if(confirm){if(!message.key.id)throw Error('No message reference');const data=await call({action:'buy',product_id:product.id,destination,payment:confirm[3].toLowerCase()==='saldo'?'wallet':'gateway',expected_price:Number(confirm[4]),inquiry_id:confirm[5]||undefined,voucher_code:voucherCode,idempotency_key:'bot:'+message.key.id});await sendOrder(data.order as Order);return;}
   if(!product.available){await sock.sendMessage(chat,{text:shopReply(name,'PRODUK BELUM DAPAT DIPESAN',[inline(product.name),product.kind==='postpaid'?'Produk tagihan memerlukan pemeriksaan nominal terlebih dahulu.':'Produk sedang tidak tersedia.','Pilih produk lain dari katalog toko.'])},{quoted:message});await sendControls(sock,message,chat,chat,tokens,[['services','Pilih produk lain'],['menu','Menu utama']],'Lanjutkan belanja');return;}
   if(product.kind==='premium'){
    if(match?.[2]?.trim()&&!confirm){
     await sock.sendMessage(chat,{text:shopReply(name,'📦 APK PREMIUM TANPA TUJUAN',['Produk ini dikirim ke WhatsApp Anda.','Cukup ketik #beli '+inline(code)+' atau klik Lanjutkan.'])},{quoted:message});
     await sendControls(sock,message,chat,chat,tokens,[[{type:'select-product',code},'Lanjutkan'],['menu','Batal / menu']],'Tidak perlu memasukkan nomor atau ID tujuan');return;
    }
    destination=number;
   }
   const needsPrompt=product.kind!=='premium'&&(guided?.type==='select-product'||!guided&&!confirm&&!destination);
   if(guided||needsPrompt){
    const profile=quote.destination_profile as {type:string;columns:number;label:string;server_label?:string;server_options?:string[]}|undefined;
    if(needsPrompt){
     tokens.waitForProduct(chat,chat,code,readCustomerMessage(message).scope);
     await sock.sendMessage(chat,{text:shopReply(name,'🎯 ISI TUJUAN PESANAN',['*'+inline(product.name)+'*',(product.kind==='postpaid'?'Total biaya admin':'Harga')+' : *'+money(product.price)+'*',...(product.postpaid_fees?['Admin layanan : '+money(product.postpaid_fees.service_admin),'Admin toko : '+money(product.postpaid_fees.store_admin)]:[]),'',profile?.columns===2?'Balas dengan *ID ; ZONA/SERVER* dalam satu pesan.':'Balas pesan ini dengan *'+inline(profile?.label??'Tujuan / ID pelanggan')+'*.',...(profile?.columns===2?['Contoh: 12345678 ; 1234']:[]),...(profile?.server_options?.length?['Pilihan server : '+profile.server_options.map(inline).join(', ')]:[]),'','#batal untuk membatalkan.'])},{quoted:message});
     await sendControls(sock,message,chat,chat,tokens,[['services','Ganti produk'],['menu','Batal / menu']],'Balas tujuan untuk melanjutkan');return;
    }
    if(product.kind!=='premium'){
     if(typeof quote.destination!=='string'||!quote.destination||profile?.columns===1&&fields&&fields.length>1)throw Error('Destination not prepared');
     destination=quote.destination;
    }
   }
   let price=product.price,inquiryId:string|undefined,billInfo:string[]=[];
   if(product.kind==='postpaid'){
    if(!message.key.id)throw Error('No message reference');
    const data=await call({action:'inquiry',product_id:product.id,destination,idempotency_key:'inquiry:'+message.key.id});
    const inquiry=data.inquiry as {id:string;amount:number;customer_name:string;period:string;description?:string;message?:string;billing?:{bill:number;service_admin:number;store_admin:number}|null;status?:string};
    if(inquiry?.status==='pending'){
     await sock.sendMessage(chat,{text:shopReply(name,'CEK TAGIHAN',[inquiry.description || inquiry.message || 'Tagihan sedang diperiksa supplier.','Tekan cek hasil untuk melihat pembaruan. Pembayaran belum dibuat.'])},{quoted:message});
     await sendControls(sock,message,chat,chat,tokens,[[{type:'product-input',code,input:destination},'Cek hasil tagihan'],['menu','Menu utama']],'Periksa hasil tagihan');return;
    }
    if(inquiry?.status==='failed'){
     await sock.sendMessage(chat,{text:shopReply(name,'CEK TAGIHAN GAGAL',[inline(inquiry.description || inquiry.message || 'Pemeriksaan tagihan ditolak supplier.'),'Periksa nomor pelanggan, lalu pilih produk untuk mencoba kembali.','Pembayaran belum dibuat.'])},{quoted:message});
     await sendControls(sock,message,chat,chat,tokens,[['services','Pilih produk'],['menu','Menu utama']],'Kembali ke toko');return;
    }
    if(!inquiry?.id||!Number.isSafeInteger(inquiry.amount))throw Error('Invalid inquiry');
    price=inquiry.amount;inquiryId=inquiry.id;billInfo=[...(inquiry.description?['Keterangan : '+inline(inquiry.description)]:[]),'Nama : '+inline(inquiry.customer_name),'Periode : '+inline(inquiry.period),...(inquiry.billing?['Tagihan pokok : '+money(inquiry.billing.bill),'Admin layanan : '+money(inquiry.billing.service_admin),'Admin toko : '+money(inquiry.billing.store_admin)]:[]),'Konfirmasi tagihan dalam 5 menit.'];
   }
   let voucher:{code:string;discount:number;total:number}|undefined;
   if(voucherCode){const promotion=await call({action:'quote',code,voucher_code:voucherCode,inquiry_id:inquiryId});voucher=promotion.voucher as typeof voucher;}
   const purchase=(payment:'wallet'|'gateway'):PurchaseAction=>({type:'purchase',productId:product.id,code,destination,price,payment,...(inquiryId?{inquiryId}:{}),...(voucherCode?{voucherCode}:{})});
   const summary=[...(quote.sandbox?['*SANDBOX · PENGUJIAN*']:[]),'*'+inline(product.name)+'*','Kode : '+inline(code),...(product.kind==='premium'?['Pengiriman : WhatsApp Anda']:['Tujuan : '+inline(destination)]),...billInfo,'Harga : *'+money(price)+'*',...(voucher?['Voucher : '+inline(voucher.code)+' · −'+money(voucher.discount),'Setelah diskon : *'+money(voucher.total)+'*']:[])];
   const checkoutId=tokens.beginCheckout(chat,chat,purchase('wallet'),summary,readCustomerMessage(message).scope);
   const paymentPrompt=await sock.sendMessage(chat,{text:shopReply(name,'💳 PILIH PEMBAYARAN',[...summary,'','Pilih metode melalui tombol di bawah.','#pilihbayar saldo · #pilihbayar qris','#batal untuk membatalkan.'])},{quoted:message});
   tokens.rememberCheckoutMessage(chat,chat,paymentPrompt?.key,scope);
   const paymentControls=await sendControls(sock,message,chat,chat,tokens,[[{type:'payment-method',checkoutId,payment:'wallet'},'Saldo toko'],[{type:'payment-method',checkoutId,payment:'gateway'},'QRIS'],['menu','Batal / menu']],'Pilih metode pembayaran');
   tokens.rememberCheckoutMessage(chat,chat,paymentControls,scope);
  }
 }catch{
  if(typeof action==='object'&&(action?.type==='product-input'||action?.type==='select-product')){
   tokens.waitForProduct(chat,chat,action.code,readCustomerMessage(message).scope);
   await sock.sendMessage(chat,{text:shopReply(name,'🎯 TUJUAN BELUM DAPAT DIPERIKSA',['Periksa format tujuan dan ketersediaan produk.','Balas ulang tujuan Anda, atau pilih produk kembali untuk melihat petunjuknya.','Untuk dua isian gunakan ID ; ZONA/SERVER.','','Pesanan belum dibuat. #batal untuk kembali ke menu.'])},{quoted:message});
   await sendControls(sock,message,chat,chat,tokens,[[{type:'select-product',code:action.code},'Petunjuk tujuan'],['services','Ganti produk'],['menu','Batal / menu']],'Lanjutkan pesanan Anda');return;
  }
  await sock.sendMessage(chat,{text:shopReply(name,'⏳ PERMINTAAN BELUM SELESAI',['Periksa kode produk, tujuan, saldo dan gateway toko.','Jika baru mengonfirmasi pesanan, cek #status terlebih dahulu.','Jangan membuat pesanan baru saat status belum pasti.'])},{quoted:message});await sendControls(sock,message,chat,chat,tokens,[['status','Status pesanan'],['menu','Menu utama']],'Periksa pesanan sebelum mencoba lagi');
 }
}
export async function deliverNotices(sock:WASocket,api:Api,qris?:QrisTracker,tokens?:MenuTokens):Promise<void>{
 const response=await api('/api/internal/bot/notices') as {data?:{id:string;phone:string;message:string;order_id?:string;revision?:string;order_status?:string;payment_status?:string;superseded?:boolean;commerce_enabled?:boolean}[];qris_revocations?:{id:string;phone:string;key:unknown}[]};
 if(!Array.isArray(response.data))return;
 if(Array.isArray(response.qris_revocations))for(const item of response.qris_revocations.slice(0,25)){
  if(!item||!/^[a-f0-9-]{36}$/i.test(item.id)||!/^62[1-9][0-9]{7,12}$/.test(item.phone)||!validQrisKey(item.key)||(!item.key.remoteJid.endsWith('@lid')&&item.key.remoteJid!==item.phone+'@s.whatsapp.net'))continue;
  try{await sock.sendMessage(item.key.remoteJid,{delete:item.key});await api('/api/internal/bot/qris-messages/'+item.id+'/revoked',{});}catch{/* Retry deletion separately; never block the payment notice. */}
 }
 for(const notice of response.data.slice(0,10)){
  if(!/^[a-f0-9-]{36}$/i.test(notice.id)||!/^62[1-9][0-9]{7,12}$/.test(notice.phone)||typeof notice.message!=='string'||notice.message.length>40000)continue;
  if(!notice.superseded){
   const sent=await sock.sendMessage(notice.phone+'@s.whatsapp.net',{text:notice.message});
   const remember=async(key:unknown)=>{if(key&&notice.order_id&&notice.revision&&notice.order_status&&notice.payment_status){
    try{await qris?.remember(notice.order_id,notice.phone,key,{kind:'order',revision:notice.revision,order_status:notice.order_status,payment_status:notice.payment_status});}catch{/* Retry registration independently of notice delivery. */}
   }};
   await remember(sent?.key);
   if(notice.commerce_enabled===true&&['success','failed','expired'].includes(notice.order_status??'')&&tokens){
    try{await remember(await sendNoticeControls(sock,notice.phone+'@s.whatsapp.net',tokens));}catch{/* A menu failure must not redeliver the final transaction notice. */}
   }
  }
  await api('/api/internal/bot/notices/'+notice.id+'/delivered',{});
 }
}

export function createNoticePump(socket:()=>WASocket|undefined,api:Api,onError:()=>void,qris?:QrisTracker,tokens?:MenuTokens){
 let running=false,requested=false;
 async function trigger():Promise<void>{
  if(running){requested=true;return;}
  if(!socket())return;
  running=true;
  try{do{requested=false;const current=socket();if(!current)break;try{await qris?.flush();}catch{onError();}await deliverNotices(current,api,qris,tokens);}while(requested);}
  catch{onError();}finally{running=false;}
 }
 return {trigger};
}
