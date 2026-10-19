import {test} from 'node:test';
import assert from 'node:assert/strict';
import {paymentClock,paymentSecondsRemaining} from '../../resources/js/components/paymentTime.ts';

test('countdown follows the invoice deadline across refreshes and timezone offsets',()=>{
    const deadline='2026-10-16T23:10:00+07:00';
    assert.equal(paymentSecondsRemaining(deadline,Date.parse('2026-10-16T16:00:00Z')),600);
    assert.equal(paymentSecondsRemaining(deadline,Date.parse('2026-10-16T16:03:05Z')),415);
    assert.equal(paymentClock(415),'06:55');
});

test('last fractional second remains payable until deadline and expired clocks never go negative',()=>{
    const deadline='2026-10-16T16:10:00Z',end=Date.parse(deadline);
    assert.equal(paymentSecondsRemaining(deadline,end-1),1);
    assert.equal(paymentSecondsRemaining(deadline,end),0);
    assert.equal(paymentSecondsRemaining(deadline,end+60000),0);
    assert.equal(paymentClock(0),'00:00');
    assert.equal(paymentSecondsRemaining('invalid',end),null);
});

test('long invoices retain hours rather than wrapping at one hour',()=>{
    assert.equal(paymentClock(3661),'01:01:01');
    assert.equal(paymentClock(60),'01:00');
});
