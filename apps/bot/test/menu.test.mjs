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
