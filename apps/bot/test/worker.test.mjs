import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {setTimeout as wait} from 'node:timers/promises';

test('offline worker API rejects unauthenticated state access without starting WhatsApp',async()=>{
    const port=34000+Math.floor(Math.random()*10000),token='test-fixture-'.repeat(4);
    const child=spawn(process.execPath,['dist/index.js'],{cwd:process.cwd(),stdio:['ignore','ignore','pipe'],env:{...process.env,
        STORE_ID:'11111111-1111-1111-1111-111111111111',WORKER_TOKEN:token,PORT:String(port),WA_ENABLED:'false'}});
    let startupError='';
    child.stderr.on('data',chunk=>{startupError+=chunk.toString();});
    try {
        let ready=false;
        for(let i=0;i<150;i++){
            if(child.exitCode!==null) throw new Error(`Worker exited during startup: ${startupError}`);
            try{const r=await fetch(`http://127.0.0.1:${port}/health`);if(r.ok){ready=true;break;}}catch{}
            await wait(100);
        }
        assert.equal(ready,true,'worker should start in offline mode');
        const anonymous=await fetch(`http://127.0.0.1:${port}/state`);
        assert.equal(anonymous.status,401);
        const authorized=await fetch(`http://127.0.0.1:${port}/state`,{headers:{Authorization:`Bearer ${token}`}});
        assert.equal(authorized.status,200);
        assert.equal((await authorized.json()).status,'STOPPED');
    }finally{child.kill('SIGTERM');}
});
