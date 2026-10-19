import {test} from 'node:test';
import assert from 'node:assert/strict';
import {handleModalCancel} from '../../resources/js/components/modalEvents.ts';

function cancellation(origin, dialog) {
    const result={closed:0,prevented:0,stopped:0};
    const event={target:origin,currentTarget:dialog,preventDefault:()=>result.prevented++,stopPropagation:()=>result.stopped++};
    return {event,result,close:()=>result.closed++};
}

test('canceling an upload picker leaves its parent modal and unsaved form state alone',()=>{
    const dialog=new EventTarget(),fileInput=new EventTarget();
    const form={title:'Banner promo',link:'https://contoh.test/promo',enabled:true};
    const expected={...form};
    const {event,result,close}=cancellation(fileInput,dialog);
    handleModalCancel(event,false,()=>{close();form.title='';form.link='';form.enabled=false;});
    assert.deepEqual(result,{closed:0,prevented:0,stopped:0});
    assert.deepEqual(form,expected);
});

test('a genuine modal cancel closes once and prevents native close from diverging from React state',()=>{
    const dialog=new EventTarget();
    const {event,result,close}=cancellation(dialog,dialog);
    handleModalCancel(event,false,close);
    assert.deepEqual(result,{closed:1,prevented:1,stopped:1});
});

test('a busy modal rejects its own cancel and a nested modal does not dismiss its parent',()=>{
    const dialog=new EventTarget();
    const busy=cancellation(dialog,dialog);
    handleModalCancel(busy.event,true,busy.close);
    assert.deepEqual(busy.result,{closed:0,prevented:1,stopped:1});
    const nested=cancellation(new EventTarget(),dialog);
    handleModalCancel(nested.event,false,nested.close);
    assert.equal(nested.result.closed,0);
});
