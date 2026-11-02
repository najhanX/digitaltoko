import test from 'node:test';
import assert from 'node:assert/strict';
import {restoreSidebarPosition} from '../../resources/js/components/sidebarScroll.ts';

function fixture(max=1000) {
    let value=0, next=0;
    const queue=new Map(), listeners=new Map();
    const element={
        get scrollTop(){return value;},set scrollTop(offset){value=Math.min(max,offset);},
        addEventListener(name,fn){listeners.set(name,fn);},removeEventListener(name){listeners.delete(name);},
    };
    const frames={request(fn){queue.set(++next,fn);return next;},cancel(id){queue.delete(id);}};
    return {element,frames,resize(height){max=height;},intent(name){listeners.get(name)?.();},
        frame(){const work=[...queue.values()];queue.clear();work.forEach(fn=>fn(0));},listenerCount(){return listeners.size;}};
}

test('saved position survives the initial short layout while accordions reopen',()=>{
    const f=fixture(0), states=[];
    restoreSidebarPosition(f.element,640,value=>states.push(value),f.frames);
    assert.equal(f.element.scrollTop,0);
    assert.deepEqual(states,[true]);
    f.resize(1000);f.frame();f.frame();
    assert.equal(f.element.scrollTop,640);
    assert.deepEqual(states,[true]);
    f.frame();assert.deepEqual(states,[true,false]);assert.equal(f.listenerCount(),0);
});

test('user scrolling cancels restoration instead of snapping back',()=>{
    const f=fixture(), states=[];
    restoreSidebarPosition(f.element,640,value=>states.push(value),f.frames);
    f.intent('wheel');f.element.scrollTop=210;f.frame();f.frame();
    assert.equal(f.element.scrollTop,210);assert.deepEqual(states,[true,false]);assert.equal(f.listenerCount(),0);
});

test('navigation cleanup cancels callbacks from the previous page',()=>{
    const f=fixture(), states=[];
    const stop=restoreSidebarPosition(f.element,640,value=>states.push(value),f.frames);
    stop();stop();f.element.scrollTop=120;f.frame();
    assert.equal(f.element.scrollTop,120);assert.deepEqual(states,[true,false]);
});

test('invalid storage values do not move the sidebar',()=>{
    for(const offset of [-1,NaN,Infinity]){
        const f=fixture();f.element.scrollTop=80;
        restoreSidebarPosition(f.element,offset,()=>assert.fail('must not enter restoration'),f.frames);
        f.frame();assert.equal(f.element.scrollTop,80);assert.equal(f.listenerCount(),0);
    }
});


test('default browser frame functions are not invoked with a foreign receiver',()=>{
    const originalRequest=globalThis.requestAnimationFrame, originalCancel=globalThis.cancelAnimationFrame;
    const f=fixture();
    globalThis.requestAnimationFrame=function(callback){assert.ok(this===undefined||this===globalThis);return f.frames.request(callback);};
    globalThis.cancelAnimationFrame=function(id){assert.ok(this===undefined||this===globalThis);f.frames.cancel(id);};
    try {
        restoreSidebarPosition(f.element,640,()=>{});
        f.frame();f.frame();f.frame();assert.equal(f.element.scrollTop,640);
    } finally {
        if(originalRequest===undefined)delete globalThis.requestAnimationFrame;else globalThis.requestAnimationFrame=originalRequest;
        if(originalCancel===undefined)delete globalThis.cancelAnimationFrame;else globalThis.cancelAnimationFrame=originalCancel;
    }
});
