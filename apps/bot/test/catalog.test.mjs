import {test} from 'node:test';
import assert from 'node:assert/strict';
import {catalogQuery,catalogPage,catalogText} from '../dist/catalog.js';
import {MenuTokens} from '../dist/menu.js';
test('catalog command supports paging/search and navigation is bound to its recipient',()=>{
    assert.deepEqual(catalogQuery('#produk',null),{page:1,search:''});
    assert.deepEqual(catalogQuery('#produk 2 canva pro',null),{page:2,search:'canva pro'});
    assert.deepEqual(catalogQuery('#produk canva',null),{page:1,search:'canva'});
    assert.equal(catalogQuery('#status',null),null);
    const tokens=new MenuTokens(),action={type:'products',page:3,search:'canva pro'};
    const id=tokens.issue('buyer-a','buyer-a',action);
    assert.equal(tokens.consume(id,'buyer-b','buyer-b'),null);
    assert.deepEqual(catalogQuery('',tokens.consume(id,'buyer-a','buyer-a')),{page:3,search:'canva pro'});
    assert.equal(tokens.consume(id,'buyer-a','buyer-a'),null);
});
test('catalog renders only public price, category and availability and rejects invalid data',()=>{
    const page=catalogPage({store_name:'Toko',items:[{code:'P1',name:'Canva',category:'Canva',service:'Premium',price:15000,available:true,quantity:2,price_label:'Harga',cost:4321,payload:'SECRET-NOT-FOR-CUSTOMER'}],pagination:{current_page:1,last_page:2,total:12}});
    const text=catalogText(page,{page:1,search:''});
    assert.match(text,/Canva/);assert.match(text,/15.000/);assert.match(text,/2 stok/);assert.match(text,/Halaman 1\/2/);
    assert.ok(!text.includes('SECRET-NOT-FOR-CUSTOMER'));assert.ok(!text.includes('4.321'));
    assert.throws(()=>catalogPage({...page,items:[{...page.items[0],price:-1}]}),/Invalid catalog/);
    assert.throws(()=>catalogPage({...page,pagination:{current_page:0,last_page:1,total:1}}),/Invalid catalog/);
});
