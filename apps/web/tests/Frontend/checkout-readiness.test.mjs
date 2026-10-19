import {test} from 'node:test';
import assert from 'node:assert/strict';
import {checkoutBlockers} from '../../resources/js/components/checkoutReadiness.ts';

const ready={product:{available:true,kind:'ppob'},phone:'081234567890',destination:'081234567890',premium:false,columns:1,server:'',requiresGameVerification:false,gameVerification:'',hasInquiry:false,payment:'gateway',gateway:'midtrans',loggedIn:false};

test('complete checkout permits configured QRIS and explains missing gateway instead of silently blocking',()=>{
    assert.deepEqual(checkoutBlockers(ready),[]);
    const reasons=checkoutBlockers({...ready,gateway:null});
    assert.equal(reasons.length,1);
    assert.match(reasons[0],/belum mengaktifkan pembayaran QRIS/);
    assert.deepEqual(checkoutBlockers({...ready,gateway:null,payment:'wallet',loggedIn:true}),[]);
    assert.match(checkoutBlockers({...ready,payment:'wallet'})[0],/Masuk/);
});

test('game and premium targets preserve their distinct checkout requirements',()=>{
    assert.match(checkoutBlockers({...ready,columns:2})[0],/zona/);
    assert.deepEqual(checkoutBlockers({...ready,columns:2,server:'1234'}),[]);
    assert.match(checkoutBlockers({...ready,requiresGameVerification:true})[0],/nickname/);
    assert.deepEqual(checkoutBlockers({...ready,columns:2,requiresGameVerification:true,gameVerification:'proof'}),[]);
    assert.deepEqual(checkoutBlockers({...ready,premium:true,columns:2,product:{available:true,kind:'premium'}}),[]);
});

test('product availability, recipient, and postpaid inquiry still block incomplete orders',()=>{
    assert.match(checkoutBlockers({...ready,product:null})[0],/Pilih produk/);
    assert.match(checkoutBlockers({...ready,product:{available:false,kind:'ppob'}})[0],/tidak tersedia/);
    assert.match(checkoutBlockers({...ready,phone:''})[0],/WhatsApp/);
    assert.match(checkoutBlockers({...ready,destination:''})[0],/tujuan/);
    assert.match(checkoutBlockers({...ready,product:{available:true,kind:'postpaid'}})[0],/tagihan/);
    assert.deepEqual(checkoutBlockers({...ready,product:{available:true,kind:'postpaid'},hasInquiry:true}),[]);
});
