import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { deliverDirectMessage, sendDirectMessage } from '../dist/direct-message.js';
import { handleCustomerMessage } from '../dist/customer.js';
import { MenuTokens } from '../dist/menu.js';

const input={type:'store-message',id:'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',phone:'6281234567890',message:'Kode OTP Toko A: 123456'};
test('notification-only web worker can send OTP but refuses when connection entitlement is revoked',async()=>{
 const sent=[];const socket={sendMessage:async(...args)=>{sent.push(args);}};
 assert.equal(await deliverDirectMessage(input,socket,true),true);
 assert.deepEqual(sent,[[input.phone+'@s.whatsapp.net',{text:input.message}]]);
 assert.equal(await deliverDirectMessage(input,socket,false),false);
 assert.equal(await deliverDirectMessage(input,undefined,true),false);
 assert.equal(sent.length,1);
});
test('manager waits for acknowledgement from the selected worker and matching request id',async()=>{
 const child=new EventEmitter();child.connected=true;
 child.send=(message,callback)=>{callback(null);queueMicrotask(()=>{
  child.emit('message',{type:'store-message-result',id:'another-request',success:true});
  child.emit('message',{type:'store-message-result',id:message.id,success:true});
 });};
 await sendDirectMessage(child,input.phone,input.message);
 assert.equal(child.listenerCount('message'),0);
 child.send=(message,callback)=>{callback(null);queueMicrotask(()=>child.emit('message',{type:'store-message-result',id:message.id,success:false}));};
 await assert.rejects(sendDirectMessage(child,input.phone,input.message));
 assert.equal(child.listenerCount('exit'),0);
});
test('a web-only connection does not answer commerce or menu commands',async()=>{
 const socket={sendMessage:async()=>assert.fail('Notification-only worker must not respond to chat commands')};
 const api=async path=>{assert.equal(path,'/api/internal/bot/access');return {data:{allowed:false,can_connect:true}};};
 for(const text of ['#menu','#saldo','#beli TSEL10 081234567890']){
  await handleCustomerMessage(socket,{key:{remoteJid:input.phone+'@s.whatsapp.net',id:'a'},message:{conversation:text}},new MenuTokens(),api);
 }
});
