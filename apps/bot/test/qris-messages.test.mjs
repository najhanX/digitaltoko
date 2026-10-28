import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,readFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {QrisMessages} from '../dist/qris-messages.js';
import {deliverNotices} from '../dist/commerce.js';
import {handleCustomerMessage} from '../dist/customer.js';
import {MenuTokens} from '../dist/menu.js';
const orderId='01a13857-4287-710b-ae1d-2e5d4497eadc',recordId='01a13857-4287-710b-ae1d-2e5d4497eadf',phone='6281234567890',jid=phone+'@s.whatsapp.net';
const key={id:'QRTEST1',remoteJid:jid,fromMe:true};

test('QR message registration survives a failed backend and worker restart, serializes new records and keeps only message references',async()=>{
 const directory=await mkdtemp(join(tmpdir(),'dt-qris-'));
 try{
  const pending=new QrisMessages(directory,async()=>{throw Error('Backend HTTP 503');});await pending.remember(orderId,phone,key);
  const disk=JSON.parse(await readFile(join(directory,'qris-message-outbox.json'),'utf8'));
  assert.deepEqual(disk,[{order_id:orderId,phone,key}]);
  const unconfirmed=new QrisMessages(directory,async()=>({status:'ERROR'}));await unconfirmed.flush();
  assert.deepEqual(JSON.parse(await readFile(join(directory,'qris-message-outbox.json'),'utf8')),disk,'HTTP success without a registration ID must not discard the key');
  const recorded=[];const restarted=new QrisMessages(directory,async(path,body)=>{recorded.push({path,body});return {data:{id:recordId}};});
  await restarted.flush();assert.equal(recorded.length,1);
  await Promise.all([restarted.remember(orderId,phone,{...key,id:'QRTEST2'}),restarted.remember(orderId,phone,{...key,id:'QRTEST3'})]);
  assert.equal(recorded.length,3);assert.deepEqual(JSON.parse(await readFile(join(directory,'qris-message-outbox.json'),'utf8')),[]);
 }finally{await rm(directory,{recursive:true,force:true});}
});

test('paid cleanup deletes the exact own QR key before the notice and acknowledges only successful deletion',async()=>{
 const sent=[],paths=[];const response={data:[{id:orderId,phone,message:'Pembayaran diterima'}],qris_revocations:[{id:recordId,phone,key}]};
 await deliverNotices({sendMessage:async(chat,body)=>{sent.push({chat,body});}},async(path)=>{paths.push(path);return response;});
 assert.deepEqual(sent[0],{chat:jid,body:{delete:key}});assert.equal(sent[1].body.text,'Pembayaran diterima');
 assert.ok(paths.includes('/api/internal/bot/qris-messages/'+recordId+'/revoked'));
 let acknowledged=false,noticeSent=false;
 await deliverNotices({sendMessage:async(_chat,body)=>{if(body.delete)throw Error('cannot revoke');noticeSent=true;}},async(path)=>{if(path.includes('/revoked'))acknowledged=true;return response;});
 assert.equal(acknowledged,false);assert.equal(noticeSent,true);
});

test('cleanup rejects incoming-message keys, malformed IDs and a different phone target',async()=>{
 const sent=[];await deliverNotices({sendMessage:async(...args)=>sent.push(args)},async()=>({data:[],qris_revocations:[{id:recordId,phone,key:{...key,fromMe:false}},{id:'bad',phone,key},{id:recordId,phone,key:{...key,remoteJid:'6280000000000@s.whatsapp.net'}}]}));
 assert.equal(sent.length,0);
});

test('order replies track receipt and native controls by revision while QR images remain independently revocable',async()=>{
 const remembered=[],tokens=new MenuTokens();
 const sock={user:{id:jid},sendMessage:async(_chat,body)=>({key:body.image?key:{...key,id:'RECEIPT'}}),relayMessage:async()=>{}};
 const order={id:orderId,number:'DTTEST1',status:'awaiting_payment',product:'Test',destination:phone,total:10000,amount:10000,fee:0,unique_code:0,url:'https://shop.example/orders/test',refund_amount:10000,qr_image:'data:image/png;base64,AA=='};
 let current=order;
 const api=async(path)=>({data:path.endsWith('/access')?{allowed:true,store_name:'Test'}:{orders:[current]}});
 const tracker={remember:async(...args)=>remembered.push(args),flush:async()=>{}};
 const message={key:{id:'A'.repeat(21),remoteJid:jid,fromMe:false},message:{conversation:'#status DTTEST1'}};
 await handleCustomerMessage(sock,message,tokens,api,tracker);
 assert.equal(remembered.length,3);assert.deepEqual(remembered[1],[orderId,phone,key]);
 assert.equal(remembered[0][3].kind,'order');assert.equal(remembered[2][3].revision,'none');
 assert.equal(remembered[2][2].fromMe,true);
 current={...order,status:'paid'};await handleCustomerMessage(sock,message,tokens,api,tracker);assert.equal(remembered.length,5);assert.equal(remembered[3][3].order_status,'paid');
});


test('latest notices retain their own message key and superseded notices are acknowledged without sending stale text',async()=>{
 const sent=[],saved=[],acks=[];
 await deliverNotices({sendMessage:async(chat,body)=>{sent.push(body);return {key};}},async(path)=>{
  if(path.endsWith('/notices'))return {data:[{id:recordId,phone,message:'old',superseded:true},{id:orderId,phone,message:'new',order_id:orderId,revision:orderId,order_status:'success',payment_status:'paid'}]};
  acks.push(path);return {};
 },{remember:async(...args)=>saved.push(args),flush:async()=>{}});
 assert.deepEqual(sent,[{text:'new'}]);assert.equal(acks.length,2);
 assert.deepEqual(saved,[[orderId,phone,key,{kind:'order',revision:orderId,order_status:'success',payment_status:'paid'}]]);
});


test('checkout text and native payment/confirmation buttons are scoped to the resulting order',async()=>{
 const saved=[],deleted=[],sent=[];let sequence=0;const tokens=new MenuTokens();
 const sock={user:{id:jid},sendMessage:async(_chat,body)=>{if(body.delete){deleted.push(body.delete);return;}const outgoing={...key,id:'TEXT'+(++sequence)};sent.push(outgoing);return {key:outgoing};},relayMessage:async()=>{}};
 const product={id:orderId,name:'Test',kind:'premium',price:10000,available:true};
 const api=async(path,body)=>({data:path.endsWith('/access')?{allowed:true,store_name:'Test'}:body.action==='quote'?{product}:{order:{id:orderId,number:'DTTEST2',status:'pending',payment_status:'paid',wa_revision:recordId,product:'Test',destination:phone,total:10000,amount:10000,url:'https://shop.example/order',refund_amount:10000}}});
 const tracker={remember:async(...args)=>saved.push(args),flush:async()=>{}};
 const run=text=>handleCustomerMessage(sock,{key:{id:'A'.repeat(21),remoteJid:jid,fromMe:false},message:{conversation:text}},tokens,api,tracker);
 await run('#beli TEST');
 const checkout=tokens.checkoutId(jid,jid),paymentKeys=[...tokens.checkoutMessages(jid,jid)];
 assert.equal(paymentKeys.length,2);
 await run('#pilihbayar saldo');assert.equal(deleted.length,2);
 const allKeys=[...tokens.checkoutMessages(jid,jid)];assert.equal(allKeys.length,4);
 await run('#konfirmasi');
 assert.equal(tokens.checkoutId(jid,jid),null);
 const old=saved.filter(entry=>entry[3]?.revision==='checkout');assert.equal(old.length,4);
 assert.deepEqual(old.map(entry=>entry[2]),allKeys);
 assert.ok(saved.every(entry=>entry[0]===orderId&&entry[1]===phone));
 assert.equal(saved.filter(entry=>entry[3]?.revision===recordId).length,2);
});


test('order revision and button message context survive worker restart before backend registration',async()=>{
 const directory=await mkdtemp(join(tmpdir(),'dt-order-messages-'));
 const context={kind:'order',revision:recordId,order_status:'pending',payment_status:'paid'};
 try{
  await new QrisMessages(directory,async()=>{throw Error('Backend HTTP 503');}).remember(orderId,phone,key,context);
  const registered=[];
  await new QrisMessages(directory,async(_path,body)=>{registered.push(body);return {data:{id:recordId}};}).flush();
  assert.deepEqual(registered,[{...context,order_id:orderId,phone,key}]);
 }finally{await rm(directory,{recursive:true,force:true});}
});


test('final commerce notifications have the same recipient-bound buttons as the main menu and web-only notifications do not',async()=>{
 const tokens=new MenuTokens(),relays=[],saved=[];
 const sock={user:{id:jid},sendMessage:async()=>({key}),relayMessage:async(_chat,body)=>relays.push(body)};
 const notice={id:recordId,phone,message:'Success',order_id:orderId,revision:recordId,order_status:'success',payment_status:'paid',commerce_enabled:true};
 let current=notice;
 await deliverNotices(sock,async()=>({data:[current]}),{remember:async(...args)=>saved.push(args),flush:async()=>{}},tokens);
 assert.equal(relays.length,1);assert.equal(saved.length,2);
 const buttons=relays[0].viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons;
 assert.deepEqual(buttons.map(button=>JSON.parse(button.buttonParamsJson).display_text),['Layanan & produk','Saldo pelanggan','Status pesanan']);
 const id=JSON.parse(buttons[0].buttonParamsJson).id;
 assert.equal(tokens.consume(id,'6280000000000@s.whatsapp.net','6280000000000@s.whatsapp.net'),null);
 assert.equal(tokens.consume(id,jid,jid),'services');
 for(const status of ['failed','expired']){current={...notice,order_status:status};await deliverNotices(sock,async()=>({data:[current]}),undefined,tokens);}
 assert.equal(relays.length,3);
 current={...notice,commerce_enabled:false};await deliverNotices(sock,async()=>({data:[current]}),undefined,tokens);assert.equal(relays.length,3);
 current={...notice,order_status:'pending'};await deliverNotices(sock,async()=>({data:[current]}),undefined,tokens);assert.equal(relays.length,3);
});


test('QR captions include the payment deadline and expired invoices never send a new QR image',async()=>{
 const images=[];const tokens=new MenuTokens();
 const sock={user:{id:jid},sendMessage:async(_chat,body)=>{if(body.image)images.push(body);return {key};},relayMessage:async()=>{}};
 let expiry='2099-10-27T09:15:00Z';
 const api=async(path)=>({data:path.endsWith('/access')?{allowed:true,store_name:'Test'}:{orders:[{id:orderId,number:'DTEXPIRY',status:'awaiting_payment',payment_status:'unpaid',expires_at:expiry,product:'Test',destination:phone,total:10000,amount:10000,fee:0,unique_code:0,url:'https://shop.example/order',refund_amount:10000,qr_image:'data:image/png;base64,AA=='}]}});
 const msg={key:{id:'A'.repeat(21),remoteJid:jid,fromMe:false},message:{conversation:'#status DTEXPIRY'}};
 await handleCustomerMessage(sock,msg,tokens,api);assert.equal(images.length,1);assert.match(images[0].caption,/Batas bayar: \*27\/10\/2099 16:15:00 WIB/);
 expiry='2000-10-27T09:15:00Z';await handleCustomerMessage(sock,msg,tokens,api);assert.equal(images.length,1);
});
