import test from 'node:test';
import assert from 'node:assert/strict';
import {MenuTokens} from '../dist/menu.js';
import {readCustomerMessage} from '../dist/messages.js';
import {handleCustomerMessage} from '../dist/customer.js';
import {catalogErrorText, featureText, menuText} from '../dist/replies.js';

const pn='628000000001@s.whatsapp.net',lid='100000000001@lid';
const incoming=message=>({key:{remoteJid:pn,id:'A'.repeat(21),fromMe:false},message});
function harness(options={}) {
    const sent=[],relays=[],paths=[];
    const tokens=new MenuTokens(options.now);
    const sock={user:{id:'628000000099@s.whatsapp.net'},sendMessage:async(chat,body)=>{sent.push({chat,...body});return {key:{id:'fake'}};},relayMessage:async(chat,message)=>{relays.push({chat,message});}};
    const api=async path=>{
        paths.push(path);
        if(path==='/api/internal/bot/access')return {data:{allowed:options.allowed ?? true,store_name:'Toko Contoh'}};
        if(options.failure)throw Error('SUPPLIER_SECRET_ERROR');
        return {data:{store_name:'Toko Contoh',items:[],pagination:{current_page:1,last_page:2,total:12}}};
    };
    return {sent,relays,paths,tokens,sock,run:message=>handleCustomerMessage(sock,message,tokens,api)};
}
const response=id=>({interactiveResponseMessage:{nativeFlowResponseMessage:{paramsJson:JSON.stringify({id})}}});

test('normalizes ephemeral/view-once replies, legacy buttons, lists and selected_row_id',()=>{
    const shapes=[response('dt:test'),{ephemeralMessage:{message:response('dt:test')}},
        {viewOnceMessage:{message:{viewOnceMessageV2:{message:response('dt:test')}}}},
        {buttonsResponseMessage:{selectedButtonId:'dt:test'}},{templateButtonReplyMessage:{selectedId:'dt:test'}},
        {listResponseMessage:{singleSelectReply:{selectedRowId:'dt:test'}}},
        {interactiveResponseMessage:{nativeFlowResponseMessage:{paramsJson:JSON.stringify({selected_row_id:'dt:test'})}}}];
    for(const shape of shapes)assert.equal(readCustomerMessage(incoming(shape)).selection,'dt:test');
    assert.equal(readCustomerMessage(incoming({ephemeralMessage:{message:{conversation:'#saldo'}}})).text,'#saldo');
    for(const paramsJson of ['{','null','[]',JSON.stringify({id:3}),'x'.repeat(4097)]) {
        assert.equal(readCustomerMessage(incoming({interactiveResponseMessage:{nativeFlowResponseMessage:{paramsJson}}})).selection,null);
    }
});

test('real generated balance button routes to the same reply as typed saldo, including a wrapped PN/LID reply',async()=>{
    const h=harness();
    const menu=incoming({conversation:'#menu'});menu.key.remoteJidAlt=lid;
    await h.run(menu);
    assert.equal(h.sent[0].text,menuText('Toko Contoh'));
    assert.equal(h.relays.length,1,'mobile receives native controls after usable text');
    const buttons=h.relays[0].message.viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons;
    const id=JSON.parse(buttons.find(button=>JSON.parse(button.buttonParamsJson).display_text==='Saldo pelanggan').buttonParamsJson).id;
    const click=incoming({ephemeralMessage:{message:response(id)}});click.key.remoteJid=lid;click.key.remoteJidAlt=pn;
    await h.run(click);
    assert.equal(h.sent.at(-1).chat,lid);
    assert.equal(h.sent.at(-1).text,featureText('Toko Contoh','balance'));
    const typed=harness();await typed.run(incoming({conversation:'#saldo'}));
    assert.equal(h.sent.at(-1).text,typed.sent[0].text);
    assert.deepEqual(h.paths,['/api/internal/bot/access','/api/internal/bot/access']);
});

test('each legacy selection dispatches an opaque status action rather than the label or raw command',async()=>{
    for(const make of [id=>({buttonsResponseMessage:{selectedButtonId:id}}),id=>({templateButtonReplyMessage:{selectedId:id}}),id=>({listResponseMessage:{singleSelectReply:{selectedRowId:id}}})]){
        const h=harness(),id=h.tokens.issue(pn,pn,'status');
        await h.run(incoming(make(id)));
        assert.equal(h.sent[0].text,featureText('Toko Contoh','status'));
    }
    const h=harness();await h.run(incoming(response('#saldo')));
    assert.match(h.sent[0].text,/MENU PERLU DIPERBARUI/);
    assert.doesNotMatch(h.sent[0].text,/SALDO PELANGGAN/);
});

test('expired, consumed, restarted or foreign tokens receive recovery guidance and cannot run actions',async()=>{
    let time=0;const h=harness({now:()=>time});
    const foreign=h.tokens.issue('other','other','balance');
    await h.run(incoming(response(foreign)));assert.match(h.sent.at(-1).text,/MENU PERLU DIPERBARUI/);
    const id=h.tokens.issue(pn,pn,'balance');time=900000;
    await h.run(incoming(response(id)));assert.match(h.sent.at(-1).text,/ketik #menu/);
    const fresh=h.tokens.issue(pn,pn,'status');await h.run(incoming(response(fresh)));
    await h.run(incoming(response(fresh)));assert.match(h.sent.at(-1).text,/sudah dipakai/);
    const restarted=harness();await restarted.run(incoming(response(fresh)));assert.match(restarted.sent[0].text,/MENU PERLU DIPERBARUI/);
});

test('catalogue button keeps the original search and page, errors do not expose suppliers, and suspended shops stay silent',async()=>{
    const h=harness(),id=h.tokens.issue(pn,pn,{type:'products',page:2,search:'Canva Pro'});
    await h.run(incoming({viewOnceMessage:{message:response(id)}}));
    assert.equal(h.paths[1],'/api/internal/bot/products?page=2&q=Canva+Pro');
    const error=harness({failure:true});await error.run(incoming({conversation:'#produk'}));
    assert.equal(error.sent[0].text,catalogErrorText('Toko Contoh'));
    assert.ok(!error.sent[0].text.includes('SUPPLIER_SECRET_ERROR'));
    const blocked=harness({allowed:false});const blockedId=blocked.tokens.issue(pn,pn,'balance');await blocked.run(incoming(response(blockedId)));
    assert.equal(blocked.sent.length,0);assert.equal(blocked.relays.length,0);
});

test('groups, outgoing messages, unrelated chat and forged content aliases never run a customer command',async()=>{
    const h=harness();
    await h.run({...incoming({conversation:'#menu'}),key:{remoteJid:'123@g.us'}});
    await h.run({...incoming({conversation:'#menu'}),key:{remoteJid:pn,fromMe:true}});
    await h.run(incoming({conversation:'Halo'}));assert.equal(h.paths.length,0);
    const id=h.tokens.issue('other','other','balance');
    await h.run(incoming({...response(id),remoteJidAlt:'other'}));
    assert.match(h.sent[0].text,/MENU PERLU DIPERBARUI/);
});

test('text-only clients keep usable commands, aliases and readable tenant branding without fabricated balances',async()=>{
    const h=harness(),message=incoming({conversation:'#menu'});message.key.id='desktop-message';await h.run(message);
    assert.equal(h.relays.length,0);assert.match(h.sent[0].text,/Format : #list/);
    const branded=menuText('*Nama*\n#saldo');assert.ok(!branded.includes('*Nama*\n#saldo'));
    assert.match(featureText('Toko Contoh','balance'),/belum diaktifkan/);
    assert.doesNotMatch(featureText('Toko Contoh','balance'),/Rp0|Hanshop|Digiflazz/);
    await h.run(incoming({conversation:'#list canva'}));assert.equal(h.paths.at(-1),'/api/internal/bot/catalog?view=categories&page=1&service=canva');
    await h.run(incoming({conversation:'#stok canva'}));assert.equal(h.paths.at(-1),'/api/internal/bot/catalog?view=products&page=1&category=canva');
});
