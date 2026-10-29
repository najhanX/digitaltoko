import test from 'node:test';
import assert from 'node:assert/strict';
import {handleCustomerMessage} from '../dist/customer.js';
import {deliverNotices,createNoticePump} from '../dist/commerce.js';
import {MenuTokens} from '../dist/menu.js';
const pn='6281234567890@s.whatsapp.net';
const message=text=>({key:{id:'A'.repeat(21),remoteJid:pn,fromMe:false},message:{conversation:text}});
const product={id:'01a13857-4287-710b-ae1d-2e5d4497eadc',name:'Produk Dummy',price:15000,available:true,kind:'ppob'};
const order={id:product.id,number:'DTTEST1',status:'paid',product:product.name,destination:'08123456789',total:15000,amount:15000,fee:0,unique_code:0,url:'https://shop.example.test/orders/test',refund_amount:15000};
function harness(override){const calls=[],sent=[],relays=[];const tokens=new MenuTokens();const sock={user:{id:'6289999999999@s.whatsapp.net'},sendMessage:async(chat,body)=>{sent.push({chat,...body});return {key:{id:'reply'}};},relayMessage:async(chat,body)=>{relays.push(body);}};const api=async(path,body)=>{calls.push({path,body});if(path.endsWith('/access'))return {data:{allowed:true,store_name:'Toko Dummy'}};if(override)return override(path,body);if(body.action==='quote')return {data:{product}};if(body.action==='buy'||body.action==='topup')return {data:{order}};return {data:{balance:25000}};};return {calls,sent,relays,tokens,sock,api,run:m=>handleCustomerMessage(sock,m,tokens,api)};}
async function approve(h,index=0,key='CONFIRM-1'){
 const methods=h.relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons;
 const pick=message('');pick.key.id='P'.repeat(21);pick.message={buttonsResponseMessage:{selectedButtonId:JSON.parse(methods[index].buttonParamsJson).id}};
 const before=h.calls.filter(c=>c.body?.action==='buy').length;await h.run(pick);
 assert.match(h.sent.at(-1).text,/KONFIRMASI PEMBELIAN/);assert.equal(h.calls.filter(c=>c.body?.action==='buy').length,before);
 const buttons=h.relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons;
 const confirm=message('');confirm.key.id=key;confirm.message={buttonsResponseMessage:{selectedButtonId:JSON.parse(buttons[0].buttonParamsJson).id}};
 await h.run(confirm);return confirm;
}

test('beli only quotes; explicit native confirmation carries frozen price and a sender-bound single-use action',async()=>{
 const h=harness();await h.run(message('#beli SKU1 08123456789'));assert.equal(h.calls.at(-1).body.action,'quote');assert.match(h.sent[0].text,/PILIH PEMBAYARAN/);assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
 const click=await approve(h);
 assert.equal(h.calls.at(-1).body.action,'buy');assert.equal(h.calls.at(-1).body.phone,'6281234567890');assert.equal(h.calls.at(-1).body.expected_price,15000);assert.equal(h.calls.at(-1).body.idempotency_key,'bot:CONFIRM-1');assert.match(h.sent.at(-1).text,/DTTEST1/);
 const count=h.calls.length;await h.run(click);assert.equal(h.calls.length,count+1);assert.match(h.sent.at(-1).text,/MENU PERLU DIPERBARUI/);
});
test('typed confirmation and topup use the incoming message id and never guess a phone from LID',async()=>{
 const h=harness();await h.run(message('#pesan SKU1|08123456789|saldo|15000'));assert.equal(h.calls.at(-1).body.expected_price,15000);assert.equal(h.calls.at(-1).body.payment,'wallet');
 await h.run(message('#topup 50.000'));assert.equal(h.calls.at(-1).body.amount,50000);assert.equal(h.calls.at(-1).body.action,'topup');
 const lid=message('#saldo');lid.key.remoteJid='123456789012@lid';const count=h.calls.length;await h.run(lid);assert.equal(h.calls.length,count+1);assert.match(h.sent.at(-1).text,/NOMOR BELUM DIKENALI/);
});
test('postpaid inquiry supplies the actual bill and its immutable reference to confirmation',async()=>{
 const inquiryId='01a13857-4287-710b-ae1d-2e5d4497eadf';const h=harness(async(_path,body)=>({data:body.action==='quote'?{product:{...product,kind:'postpaid',price:3000}}:body.action==='inquiry'?{inquiry:{id:inquiryId,amount:52500,customer_name:'Dummy',period:'202610'}}:{order}}));
 await h.run(message('#beli PLN 12345678'));assert.equal(h.calls.at(-1).body.action,'inquiry');assert.match(h.sent[0].text,/52.500/);
 await approve(h,1);
 assert.equal(h.calls.at(-1).body.inquiry_id,inquiryId);assert.equal(h.calls.at(-1).body.expected_price,52500);
});
test('supplier errors are private and guide customers to check status before retrying',async()=>{const h=harness(async()=>{throw Error('SECRET-API-KEY');});await h.run(message('#topup 50000'));assert.match(h.sent.at(-1).text,/#status terlebih dahulu/);assert.doesNotMatch(h.sent.at(-1).text,/SECRET-API-KEY/);});
test('outbox sends only to the stored customer and acknowledges only after successful delivery',async()=>{
 const sent=[],calls=[];const notice={id:product.id,phone:'6281234567890',message:'Pesanan berhasil'};
 await deliverNotices({sendMessage:async(chat,body)=>{sent.push({chat,body});}},async(path)=>{calls.push(path);return {data:[notice,{...notice,phone:'../other'}]};});assert.equal(sent.length,1);assert.equal(sent[0].chat,pn);assert.equal(calls.at(-1),'/api/internal/bot/notices/'+notice.id+'/delivered');
 let acknowledged=false;await assert.rejects(()=>deliverNotices({sendMessage:async()=>{throw Error('offline');}},async(path)=>{if(path.endsWith('/delivered'))acknowledged=true;return {data:[notice]};}));assert.equal(acknowledged,false);
});

test('sandbox receipts and balances are labelled and SB references can be checked',async()=>{
 const h=harness(async(_path,body)=>({data:body.action==='balance'?{balance:50000,sandbox:true}:{orders:[{...order,number:'SBTEST123',is_sandbox:true,gateway:'sandbox'}]}}));
 await h.run(message('#saldo'));assert.match(h.sent.at(-1).text,/SANDBOX/);
 await h.run(message('#status SBTEST123'));assert.equal(h.calls.at(-1).body.number,'SBTEST123');assert.match(h.sent.at(-1).text,/SANDBOX/);assert.match(h.sent.at(-1).text,/Simulasikan pembayaran/);
});

test('notification wake coalesces concurrent requests and retries after a failure without waiting for heartbeat',async()=>{
 let release,calls=0,errors=0,active=0,max=0;const blocked=new Promise(resolve=>release=resolve);
 const pump=createNoticePump(()=>({}),async()=>{calls++;active++;max=Math.max(max,active);if(calls===1)await blocked;active--;return {data:[]};},()=>errors++);
 const first=pump.trigger();await pump.trigger();await pump.trigger();release();await first;assert.equal(calls,2);assert.equal(max,1);
 const retry=createNoticePump(()=>({}),async()=>{calls++;if(calls===3)throw Error('offline');return {data:[]};},()=>errors++);
 await retry.trigger();await retry.trigger();assert.equal(errors,1);assert.equal(calls,4);
 const paused=createNoticePump(()=>undefined,async()=>{throw Error('must not request');},()=>errors++);await paused.trigger();assert.equal(errors,1);
});

test('voucher is quoted before confirmation and preserved in native and typed purchase actions',async()=>{
 const h=harness(async(_path,body)=>({data:body.action==='quote'?{product,...(body.voucher_code?{voucher:{code:'HEMAT10',discount:1500,total:13500}}:{})}:{order}}));
 await h.run(message('#beli SKU1 08123456789 | HEMAT10'));assert.equal(h.calls.at(-1).body.voucher_code,'HEMAT10');assert.match(h.sent[0].text,/13.500/);assert.match(h.sent[0].text,/#pilihbayar saldo/);
 await approve(h,0,'VOUCHER-CONFIRM');assert.equal(h.calls.at(-1).body.voucher_code,'HEMAT10');assert.equal(h.calls.at(-1).body.expected_price,15000);
 await h.run(message('#pesan SKU1|08123456789|qris|15000||HEMAT10'));assert.equal(h.calls.at(-1).body.voucher_code,'HEMAT10');assert.equal(h.calls.at(-1).body.inquiry_id,undefined);
});

test('balance buttons open topup instructions without creating a payment or purchase',async()=>{
 const h=harness();await h.run(message('#saldo'));
 const buttons=h.relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons;
 const topup=buttons.find(b=>JSON.parse(b.buttonParamsJson).display_text==='Isi saldo');
 const click=message('');click.key.id='TOPUP-GUIDE';click.message={buttonsResponseMessage:{selectedButtonId:JSON.parse(topup.buttonParamsJson).id}};
 await h.run(click);assert.match(h.sent.at(-1).text,/ISI SALDO TOKO/);assert.match(h.sent.at(-1).text,/#topup 25000/);
 assert.equal(h.calls.filter(c=>c.body?.action==='balance').length,1);
 assert.ok(!h.calls.some(c=>['topup','buy'].includes(c.body?.action)));
 await h.run(message('#topup'));assert.match(h.sent.at(-1).text,/ISI SALDO TOKO/);
 assert.ok(!h.calls.some(c=>['topup','buy'].includes(c.body?.action)));
});

test('history selector and refresh button check the selected order only, without buying again',async()=>{
 const h=harness(async(_path,body)=>({data:{orders:body.number?[{...order,number:body.number}]:[{...order,number:'DTFIRST'},{...order,number:'DTSECOND'}]}}));
 await h.run(message('#status'));
 const list=h.relays.find(m=>m.viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons[0].name==='single_select');
 const row=JSON.parse(list.viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons[0].buttonParamsJson).sections[0].rows[1];
 const click=message('');click.key.id='B'.repeat(21);click.message={listResponseMessage:{singleSelectReply:{selectedRowId:row.id}}};
 await h.run(click);assert.equal(h.calls.at(-1).body.number,'DTSECOND');assert.match(h.sent.at(-1).text,/DTSECOND/);
 const refresh=JSON.parse(h.relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons[0].buttonParamsJson).id;
 const update=message('');update.key.id='C'.repeat(21);update.message={buttonsResponseMessage:{selectedButtonId:refresh}};
 await h.run(update);assert.equal(h.calls.at(-1).body.action,'status');assert.equal(h.calls.at(-1).body.number,'DTSECOND');
 const reads=h.calls.filter(c=>c.body?.action==='status').length;await h.run(update);
 assert.equal(h.calls.filter(c=>c.body?.action==='status').length,reads);assert.match(h.sent.at(-1).text,/MENU PERLU DIPERBARUI/);
 assert.ok(!h.calls.some(c=>['buy','topup'].includes(c.body?.action)));
});

test('negative customer balance is visible with settlement guidance rather than a generic error',async()=>{
 const h=harness(async()=>({data:{balance:-12500}}));await h.run(message('#saldo'));
 assert.match(h.sent.at(-1).text,/-Rp\s*12.500/);assert.match(h.sent.at(-1).text,/Saldo Anda minus/);
 assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
});

test('order result remains private until success and completed receipts do not ask for payment',async()=>{
 const {orderReply}=await import('../dist/commerce.js');
 const pending=orderReply('Toko Dummy',{...order,status:'pending',result:'PRIVATE-DELIVERY'});
 assert.doesNotMatch(pending,/PRIVATE-DELIVERY/);assert.match(pending,/Tidak perlu membuat pesanan baru/);
 const success=orderReply('Toko Dummy',{...order,status:'success',result:'PRIVATE-DELIVERY'});
 assert.match(success,/PRIVATE-DELIVERY/);assert.match(success,/Terima kasih/);assert.match(success,/Bukti pesanan/);
 assert.doesNotMatch(success,/Bukti & pembayaran/);
});

test('product list continues through destination and explicit confirmation using the latest price',async()=>{
 const item={code:'SKU1',name:'Pulsa Tes',service:'Pulsa',category:'Telkomsel',price:15000,available:true,quantity:null,price_label:'Harga'};
 const h=harness(async(path,body)=>({data:path.includes('/products?')?{store_name:'Toko Dummy',items:[item,{...item,code:'EMPTY',available:false}],pagination:{current_page:1,last_page:1,total:2}}:body.action==='quote'?{product:{...product,price:16000,kind:'ppob'},destination_profile:{type:'phone',columns:1,label:'Nomor HP tujuan'},...(body.destination_id?{destination:body.destination_id}:{})}:{order}}));
 await h.run(message('#produk pulsa'));
 const list=h.relays.find(m=>m.viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons[0].name==='single_select');
 const rows=JSON.parse(list.viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons[0].buttonParamsJson).sections[0].rows;
 assert.equal(rows.length,1,'unavailable products are not offered for selection');
 const pick=message('');pick.key.id='B'.repeat(21);pick.message={listResponseMessage:{singleSelectReply:{selectedRowId:rows[0].id}}};
 await h.run(pick);assert.match(h.sent.at(-1).text,/ISI TUJUAN/);assert.match(h.sent.at(-1).text,/16.000/);
 assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
 const destination=message('08123456789');destination.key.id='C'.repeat(21);await h.run(destination);
 assert.equal(h.calls.at(-1).body.destination_id,'08123456789');assert.match(h.sent.at(-1).text,/PILIH PEMBAYARAN/);
 assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
 await approve(h,0,'D'.repeat(21));
 assert.equal(h.calls.at(-1).body.action,'buy');assert.equal(h.calls.at(-1).body.expected_price,16000);assert.equal(h.calls.at(-1).body.destination,'08123456789');
});

test('premium product selection uses the customer phone and still requires payment confirmation',async()=>{
 const h=harness(async()=>({data:{product:{...product,kind:'premium'},destination_profile:{type:'premium',columns:1,label:'Tujuan'},destination:'6281234567890'}}));
 const token=h.tokens.issue(pn,pn,{type:'select-product',code:'SKU1'}),pick=message('');pick.message={buttonsResponseMessage:{selectedButtonId:token}};
 await h.run(pick);assert.match(h.sent.at(-1).text,/PILIH PEMBAYARAN/);assert.match(h.sent.at(-1).text,/Pengiriman : WhatsApp Anda/);assert.doesNotMatch(h.sent.at(-1).text,/Tujuan :/);
 assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
});

test('two-field destinations preserve supplier separators and invalid input can be retried',async()=>{
 const h=harness(async(_path,body)=>{
  if(body.destination_id==='wrong')throw Error('invalid input');
  return {data:{product:{...product,kind:'ppob'},destination_profile:{type:'game',columns:2,label:'ID game',format:'pipe'},...(body.destination_id?{destination:body.destination_id+'|'+body.destination_server}:{})}};
 });
 const token=h.tokens.issue(pn,pn,{type:'select-product',code:'SKU1'}),pick=message('');pick.message={buttonsResponseMessage:{selectedButtonId:token}};
 await h.run(pick);assert.match(h.sent.at(-1).text,/ID ; ZONA/);
 await h.run(message('wrong'));assert.match(h.sent.at(-1).text,/TUJUAN BELUM DAPAT DIPERIKSA/);
 await h.run(message('12345678 ; 1234'));assert.equal(h.calls.at(-1).body.destination_server,'1234');assert.match(h.sent.at(-1).text,/12345678\|1234/);
 const id=JSON.parse(h.relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons[0].buttonParamsJson).id;
 const action=h.tokens.consume(id,pn,pn);assert.equal(h.tokens.choosePayment(pn,pn,action.checkoutId,action.payment).purchase.destination,'12345678|1234');assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
});

test('cancel leaves no destination conversation behind and plain messages do not create orders',async()=>{
 const h=harness();h.tokens.waitForProduct(pn,pn,'SKU1');await h.run(message('#batal'));
 const count=h.calls.length;await h.run(message('08123456789'));assert.equal(h.calls.length,count);
 assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
});

test('premium typed order skips destination, chooses payment and requires a separate final confirmation',async()=>{
 const h=harness(async(_path,body)=>({data:body.action==='quote'?{product:{...product,kind:'premium'},destination_profile:{type:'phone',columns:1,label:'Nomor tujuan'}}:{order}}));
 await h.run(message('#beli SKU1'));assert.match(h.sent.at(-1).text,/PILIH PEMBAYARAN/);assert.doesNotMatch(h.sent.at(-1).text,/ISI TUJUAN|Tujuan :/);
 await h.run(message('#pilihbayar qris'));assert.match(h.sent.at(-1).text,/KONFIRMASI PEMBELIAN/);assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
 const confirm=message('#konfirmasi');confirm.key.id='FINAL-PREMIUM';await h.run(confirm);
 assert.equal(h.calls.at(-1).body.action,'buy');assert.equal(h.calls.at(-1).body.payment,'gateway');assert.equal(h.calls.at(-1).body.destination,'6281234567890');
 const buys=h.calls.filter(c=>c.body?.action==='buy').length;await h.run(message('#konfirmasi'));assert.equal(h.calls.filter(c=>c.body?.action==='buy').length,buys);
});

test('non-premium missing destination is always prompted and premium extra destination is rejected',async()=>{
 const regular=harness();await regular.run(message('#beli SKU1'));assert.match(regular.sent.at(-1).text,/ISI TUJUAN/);assert.ok(!regular.calls.some(c=>c.body?.action==='buy'));
 const premium=harness(async()=>({data:{product:{...product,kind:'premium'}}}));await premium.run(message('#beli SKU1 08123456789'));assert.match(premium.sent.at(-1).text,/APK PREMIUM TANPA TUJUAN/);assert.ok(!premium.calls.some(c=>c.body?.action==='buy'));
});

test('alternative payment button cannot change a selected method or create a second checkout',async()=>{
 const h=harness();await h.run(message('#beli SKU1 08123456789'));
 const buttons=h.relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons;
 for(const index of [0,1]){const pick=message('');pick.key.id=(index?'Y':'X').repeat(21);pick.message={buttonsResponseMessage:{selectedButtonId:JSON.parse(buttons[index].buttonParamsJson).id}};await h.run(pick);}
 assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
 await h.run(message('#konfirmasi'));assert.equal(h.calls.at(-1).body.payment,'wallet');assert.equal(h.calls.filter(c=>c.body?.action==='buy').length,1);
});


test('payment instructions show the actual expiry in WIB and the remaining time at send time only',async()=>{
 const {orderReply}=await import('../dist/commerce.js');
 const now=Date.parse('2026-10-27T09:00:00Z');
 const awaiting={...order,status:'awaiting_payment',payment_status:'unpaid',expires_at:'2026-10-27T09:15:00Z'};
 const text=orderReply('Toko Dummy',awaiting,now);
 assert.match(text,/Batas bayar: \*27\/10\/2026 16:15:00 WIB\*/);
 assert.match(text,/Sisa saat pesan dikirim: 15 menit/);
 const elapsed=orderReply('Toko Dummy',awaiting,now+900001);
 assert.match(elapsed,/Batas pembayaran sudah lewat/);assert.doesNotMatch(elapsed,/Sisa saat/);
 for(const updated of [{...awaiting,status:'success'},{...awaiting,payment_status:'paid'},{...awaiting,expires_at:null},{...awaiting,expires_at:'invalid'}]){
  assert.doesNotMatch(orderReply('Toko Dummy',updated,now),/Batas bayar:|Sisa saat|Invalid Date/);
 }
});


test('pending postpaid check offers a scoped check-result button and never offers payment early',async()=>{
 let ready=false;
 const inquiryId='01a13857-4287-710b-ae1d-2e5d4497eadf';
 const h=harness(async(_path,body)=>({data:body.action==='quote'?{product:{...product,kind:'postpaid',price:3000},destination:body.destination_id??'12345678',destination_profile:{columns:1,label:'ID pelanggan'}}:body.action==='inquiry'?{inquiry:{id:inquiryId,status:ready?'ready':'pending',amount:ready?104000:0,customer_name:'Dummy',period:'202610'}}:{order}}));
 await h.run(message('#beli PLN 12345678'));
 assert.match(h.sent.at(-1).text,/Tagihan sedang diperiksa/);
 assert.equal(h.calls.filter(call=>call.body?.action==='buy').length,0);
 const buttons=h.relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons;
 const click=message('');click.key.id='CHECK-RESULT';click.message={buttonsResponseMessage:{selectedButtonId:JSON.parse(buttons[0].buttonParamsJson).id}};
 ready=true;await h.run(click);
 assert.match(h.sent.at(-1).text,/104.000/);
 assert.equal(h.calls.filter(call=>call.body?.action==='buy').length,0);
});


test('failed postpaid inquiry shows safe API reason and never starts payment',async()=>{
 const h=harness(async(_path,body)=>({data:body.action==='quote'?{product:{...product,kind:'postpaid',price:3000}}:{inquiry:{id:product.id,status:'failed',amount:0,message:'Nomor tujuan salah atau tidak ditemukan.'}}}));
 await h.run(message('#beli PLN 12345678'));
 assert.match(h.sent.at(-1).text,/CEK TAGIHAN GAGAL/);
 assert.match(h.sent.at(-1).text,/Nomor tujuan salah/);
 assert.ok(!h.calls.some(call=>call.body?.action==='buy'));
 assert.match(h.sent.at(-1).text,/Pembayaran belum dibuat/);
});


test('postpaid inquiry displays captured supplier description beyond predefined failure messages',async()=>{
 const h=harness(async(_path,body)=>({data:body.action==='quote'?{product:{...product,kind:'postpaid',price:3000}}:{inquiry:{id:product.id,status:'failed',amount:0,message:'Pemeriksaan tagihan gagal.',description:'TAGIHAN MASIH DALAM PENYESUAIAN'}}}));
 await h.run(message('#beli PLN 12345678'));
 assert.match(h.sent.at(-1).text,/TAGIHAN MASIH DALAM PENYESUAIAN/);
 assert.ok(!h.calls.some(call=>call.body?.action==='buy'));
});


test('order status shows captured description separately from premium credentials',async()=>{
 const {orderReply}=await import('../dist/commerce.js');
 for(const status of ['pending','failed','success']){
  const text=orderReply('Toko Dummy',{...order,status,description:'PROSES DARI PEMASOK',result:status==='success'?'ACCOUNT-SECRET':null});
  assert.match(text,/Keterangan : PROSES DARI PEMASOK/);
  assert.equal(text.includes('ACCOUNT-SECRET'),status==='success');
 }
});


test('postpaid receipt separates service admin and store admin from principal and payment total',async()=>{
 const {orderReply}=await import('../dist/commerce.js');
 const text=orderReply('Toko Dummy',{...order,status:'success',amount:102100,total:102100,postpaid_breakdown:{bill:100000,service_admin:2000,store_admin:100}});
 assert.match(text,/Tagihan pokok : Rp.?100.000/);
 assert.match(text,/Admin layanan : Rp.?2.000/);
 assert.match(text,/Admin toko : Rp.?100/);
 assert.doesNotMatch(text,/Admin layanan : Rp.?2.100/);
});
