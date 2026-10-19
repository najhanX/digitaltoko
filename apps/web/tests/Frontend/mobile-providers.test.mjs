import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mobileNumber,mobileProvider,validMobileNumber} from '../../resources/js/components/mobileProviders.ts';
const providers=[{id:'telkomsel',name:'Telkomsel',prefixes:['0812','0851']},{id:'xl',name:'XL',prefixes:['0817','0878']},{id:'byu',name:'by.U',prefixes:[]}];
test('mobile detection normalizes local and international input without accepting letters',()=>{
    assert.equal(mobileNumber('+62 812-3456-7890'),'081234567890');
    assert.equal(mobileNumber('6281234567890'),'081234567890');
    assert.equal(mobileProvider('0812',providers)?.id,'telkomsel');
    assert.equal(mobileProvider('+62878',providers)?.id,'xl');
    assert.equal(mobileProvider('0851',providers)?.id,'telkomsel');
    assert.equal(mobileProvider('081',providers),null);
    assert.equal(mobileProvider('nomor 0812',providers),null);
});
test('continuation requires a complete mobile number while provider detection can run earlier',()=>{
    assert.equal(validMobileNumber('0812'),false);
    assert.equal(validMobileNumber('081234567890'),true);
    assert.equal(validMobileNumber('+62 878 1234 5678'),true);
    assert.equal(validMobileNumber('+12025550123'),false);
    assert.equal(validMobileNumber('0812<script>'),false);
});
