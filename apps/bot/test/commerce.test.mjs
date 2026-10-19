import test from 'node:test';
import assert from 'node:assert/strict';
import {handleCustomerMessage} from '../dist/customer.js';
import {deliverNotices,createNoticePump} from '../dist/commerce.js';
import {MenuTokens} from '../dist/menu.js';
const pn='6281234567890@s.whatsapp.net';
const message=text=>({key:{id:'A'.repeat(21),remoteJid:pn,fromMe:false},message:{conversation:text}});
const product={id:'01a13857-4287-710b-ae1d-2e5d4497eadc',name:'Produk Dummy',price:15000,available:true,kind:'premium'};
const order={id:product.id,number:'DTTEST1',status:'paid',product:product.name,destination:'08123456789',total:15000,amount:15000,fee:0,unique_code:0,url:'https://shop.example.test/orders/test',refund_amount:15000};
function harness(override){const calls=[],sent=[],relays=[];const tokens=new MenuTokens();const sock={user:{id:'6289999999999@s.whatsapp.net'},sendMessage:async(chat,body)=>{sent.push({chat,...body});return {key:{id:'reply'}};},relayMessage:async(chat,body)=>{relays.push(body);}};const api=async(path,body)=>{calls.push({path,body});if(path.endsWith('/access'))return {data:{allowed:true,store_name:'Toko Dummy'}};if(override)return override(path,body);if(body.action==='quote')return {data:{product}};if(body.action==='buy'||body.action==='topup')return {data:{order}};return {data:{balance:25000}};};return {calls,sent,relays,tokens,sock,api,run:m=>handleCustomerMessage(sock,m,tokens,api)};}
test('beli only quotes; explicit native confirmation carries frozen price and a sender-bound single-use action',async()=>{
 const h=harness();await h.run(message('#beli SKU1 08123456789'));assert.equal(h.calls.at(-1).body.action,'quote');assert.match(h.sent[0].text,/PERIKSA PESANAN/);assert.ok(!h.calls.some(c=>c.body?.action==='buy'));
 const buttons=h.relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons;
 const id=JSON.parse(buttons[0].buttonParamsJson).id;const click=message('');click.key.id='CONFIRM-1';click.message={buttonsResponseMessage:{selectedButtonId:id}};await h.run(click);
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
 const buttons=h.relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons,id=JSON.parse(buttons[1].buttonParamsJson).id;const m=message('');m.message={buttonsResponseMessage:{selectedButtonId:id}};await h.run(m);
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
