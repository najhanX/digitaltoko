import test from 'node:test';
import assert from 'node:assert/strict';
import {navigationQuery,navigationCommand,navigationUrl,navigationPage,navigationText} from '../dist/navigation.js';
import {MenuTokens} from '../dist/menu.js';
import {handleCustomerMessage} from '../dist/customer.js';

const root={type:'browse',view:'services',page:1};
const page=(view,items,scope={})=>({view,store_name:'Toko',service:null,category:null,items,pagination:{current_page:1,last_page:1,total:items.length},...scope});

test('hierarchy commands keep names with spaces quotes unicode and scoped paging without confusing direct search',()=>{
    assert.deepEqual(navigationQuery('#list',null),root);
    assert.deepEqual(navigationQuery('#produk',null),root);
    assert.equal(navigationQuery('#produk Canva',null),null);
    assert.deepEqual(navigationQuery('#list Aplikasi Premium',null),{type:'browse',view:'categories',service:'Aplikasi Premium',page:1});
    for(const query of [root,{...root,page:2},{type:'browse',view:'categories',page:3,service:'Aplikasi "Kreatif" & Premium'},
        {type:'browse',view:'products',service:'Aplikasi Premium',category:'Canva Pro',page:4},
        {type:'browse',view:'products',category:'Voucher 日本',page:2}]) {
        assert.deepEqual(navigationQuery(navigationCommand(query),null),query);
    }
    assert.equal(navigationQuery('#list --halaman nope',null),'help');
    assert.equal(navigationQuery('#stok "Broken',null),'help');
    assert.match(navigationUrl({type:'browse',view:'products',service:'A&B',category:'Canva Pro',page:2}),/service=A%26B&category=Canva\+Pro/);
});

test('navigation response validates modes counts and public product shape',()=>{
    const valid=page('services',[{name:'Premium',category_count:1,product_count:2}]);
    assert.deepEqual(navigationPage(valid),valid);
    for(const bad of [{...valid,view:'private'},{...valid,items:[{name:'Premium',product_count:-1,category_count:1}]},
        page('categories',[{name:'Canva',product_count:1}]),{...valid,pagination:{current_page:0,last_page:1,total:1}}]) {
        assert.throws(()=>navigationPage(bad),/Invalid navigation/);
    }
});

test('product fallback retains category and service on next page and provides parent navigation',()=>{
    const query={type:'browse',view:'products',service:'Aplikasi Premium',category:'Canva Pro',page:1};
    const data=page('products',[],{service:query.service,category:query.category,pagination:{current_page:1,last_page:2,total:12}});
    const text=navigationText(navigationPage(data),query);
    assert.match(text,/#stok "Canva Pro" --layanan "Aplikasi Premium" --halaman 2/);
    assert.match(text,/Kategori utama : #list "Aplikasi Premium"/);
    assert.doesNotMatch(text,/#produk 2/);
    const ambiguous=page('categories',[{name:'Canva Pro',service:'Premium',product_count:2},{name:'Canva Pro',service:'Langganan',product_count:1}],{category:'Canva Pro'});
    assert.match(navigationText(navigationPage(ambiguous),{...query,service:undefined}),/beberapa layanan/);
});

test('actual native list selection goes service -> category -> products with scoped next and back buttons',async()=>{
    const chat='628000000001@s.whatsapp.net';
    const messages=[],relays=[],requests=[],tokens=new MenuTokens();
    const sock={user:{id:'628000000099@s.whatsapp.net'},sendMessage:async(_,body)=>messages.push(body),relayMessage:async(_,message)=>relays.push(message)};
    const message=content=>({key:{remoteJid:chat,id:'A'.repeat(21),fromMe:false},message:content});
    const api=async path=>{
        requests.push(path);
        if(path==='/api/internal/bot/access') return {data:{allowed:true,store_name:'Toko'}};
        const q=new URL(path,'http://fixture.test').searchParams;
        const view=q.get('view'),service=q.get('service'),category=q.get('category');
        if(view==='services')return {data:page(view,[{name:'Aplikasi Premium',product_count:12,category_count:1}])};
        if(view==='categories') {assert.equal(service,'Aplikasi Premium');return {data:page(view,[{name:'Canva Pro',service,product_count:12}],{service})};}
        assert.equal(service,'Aplikasi Premium');assert.equal(category,'Canva Pro');
        return {data:page('products',[{name:'Canva 1 Bulan',code:'CV1',service,category,price:15000,price_label:'Harga',available:true,quantity:2}],{service,category,pagination:{current_page:Number(q.get('page')),last_page:2,total:12}})};
    };
    const click=async id=>handleCustomerMessage(sock,message({ephemeralMessage:{message:{interactiveResponseMessage:{nativeFlowResponseMessage:{paramsJson:JSON.stringify({selected_row_id:id})}}}}}),tokens,api);
    const rowId=()=>{
        const native=relays.findLast(message=>message.viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons[0].name==='single_select');
        return JSON.parse(native.viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons[0].buttonParamsJson).sections[0].rows[0].id;
    };
    const buttonId=label=>JSON.parse(relays.at(-1).viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons.find(button=>JSON.parse(button.buttonParamsJson).display_text===label).buttonParamsJson).id;
    await handleCustomerMessage(sock,message({conversation:'#list'}),tokens,api);
    assert.match(messages.at(-1).text,/LIST LAYANAN/);
    await click(rowId());assert.match(messages.at(-1).text,/LIST KATEGORI UTAMA/);
    await click(rowId());assert.match(messages.at(-1).text,/Canva 1 Bulan/);assert.match(messages.at(-1).text,/15.000/);
    await click(buttonId('Berikutnya'));assert.match(requests.at(-1),/view=products&page=2&service=Aplikasi\+Premium&category=Canva\+Pro/);
    await click(buttonId('Kategori utama'));assert.match(requests.at(-1),/view=categories&page=1&service=Aplikasi\+Premium/);
    assert.match(messages.at(-1).text,/LIST KATEGORI UTAMA/);
    const foreign=message({interactiveResponseMessage:{nativeFlowResponseMessage:{paramsJson:JSON.stringify({id:rowId()})}}});foreign.key.remoteJid='628000000002@s.whatsapp.net';
    await handleCustomerMessage(sock,foreign,tokens,api);assert.match(messages.at(-1).text,/MENU PERLU DIPERBARUI/);
});

test('a failed browse retries its exact scope rather than starting the catalogue over',async()=>{
    const chat='628000000001@s.whatsapp.net',tokens=new MenuTokens(),sent=[],relayed=[];
    const query={type:'browse',view:'products',page:3,service:'Premium',category:'Canva'};
    const id=tokens.issue(chat,chat,query);
    await handleCustomerMessage({user:{id:chat},sendMessage:async(_,body)=>sent.push(body),relayMessage:async(_,body)=>relayed.push(body)},
        {key:{remoteJid:chat,id:'A'.repeat(21)},message:{buttonsResponseMessage:{selectedButtonId:id}}},tokens,
        async path=>{if(path==='/api/internal/bot/access')return {data:{allowed:true,store_name:'Toko'}};throw Error('private failure');});
    assert.match(sent[0].text,/KATALOG BELUM TERSEDIA/);
    const retry=JSON.parse(relayed[0].viewOnceMessage.message.interactiveMessage.nativeFlowMessage.buttons[0].buttonParamsJson).id;
    assert.deepEqual(tokens.consume(retry,chat,chat),query);
});
