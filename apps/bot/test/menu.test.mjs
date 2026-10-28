import {test} from 'node:test';
import assert from 'node:assert/strict';
import {MenuTokens} from '../dist/menu.js';
import {loadConfig} from '../dist/config.js';

test('button token is sender/chat-bound and consumed only once',()=>{
    const menu=new MenuTokens();const id=menu.issue('chat-a','sender-a','status');
    assert.equal(menu.consume(id,'chat-b','sender-a'),null);
    assert.equal(menu.consume(id,'chat-a','sender-b'),null);
    assert.equal(menu.consume('#buy malicious','chat-a','sender-a'),null);
    assert.equal(menu.consume(id,'chat-a','sender-a'),'status');
    assert.equal(menu.consume(id,'chat-a','sender-a'),null);
});
test('expired button cannot execute action',()=>{let time=0;const menu=new MenuTokens(()=>time);const id=menu.issue('a','b','balance');time=900000;assert.equal(menu.consume(id,'a','b'),null);});
test('worker rejects store path traversal and missing token',()=>{
    assert.throws(()=>loadConfig({STORE_ID:'../../store'}),/UUID/);
    assert.throws(()=>loadConfig({STORE_ID:'11111111-1111-1111-1111-111111111111'}),/TOKEN/);
    assert.throws(()=>loadConfig({STORE_ID:'11111111-1111-1111-1111-111111111111',WORKER_TOKEN:'x'.repeat(32),WEB_BASE_URL:'https://secret@host'}),/credential/);
});

test('destination conversations are sender-bound, PN/LID-aware, single-use and expire after five minutes',()=>{
 let time=0;const tokens=new MenuTokens(()=>time);
 tokens.waitForProduct('pn','pn','SKU',{chatAliases:['lid'],senderAliases:['lid']});
 assert.equal(tokens.takeProductInput('foreign','foreign'),null);
 assert.deepEqual(tokens.takeProductInput('lid','lid'),{code:'SKU',expired:false});
 assert.equal(tokens.takeProductInput('pn','pn'),null);
 tokens.waitForProduct('pn','pn','SKU');time=300000;
 assert.deepEqual(tokens.takeProductInput('pn','pn'),{code:'SKU',expired:true});
});

test('checkout requires method then confirmation, expires, and invalidates an older flow',()=>{
 let time=0;const tokens=new MenuTokens(()=>time),purchase={type:'purchase',productId:'product',code:'SKU',destination:'123',price:15000,payment:'wallet'};
 const first=tokens.beginCheckout('a','a',purchase,['Test']);assert.equal(tokens.confirmCheckout('a','a',first),null);
 assert.equal(tokens.choosePayment('b','b',first,'wallet'),null);
 const second=tokens.beginCheckout('a','a',purchase,['Test']);assert.equal(tokens.choosePayment('a','a',first,'wallet'),null);
 assert.ok(tokens.choosePayment('a','a',second,'gateway'));assert.equal(tokens.choosePayment('a','a',second,'wallet'),null);
 assert.equal(tokens.confirmCheckout('a','a',second).payment,'gateway');assert.equal(tokens.confirmCheckout('a','a',second),null);
 const third=tokens.beginCheckout('a','a',purchase,['Test']);time=300000;assert.equal(tokens.choosePayment('a','a',third,'wallet'),null);
});
