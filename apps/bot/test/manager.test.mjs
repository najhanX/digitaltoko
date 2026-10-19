import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {mkdtemp,readFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,sep} from 'node:path';
import {randomBytes,randomUUID} from 'node:crypto';
import {setTimeout as delay} from 'node:timers/promises';

async function availablePort() {
  const server=createServer();await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const port=server.address().port;await new Promise(resolve=>server.close(resolve));return port;
}

test('manager authenticates, isolates sessions, starts idempotently and resumes its private registry', {timeout:40000}, async()=>{
  const root=await mkdtemp(join(tmpdir(),'digitaltoko-manager-test-'));
  const managerPort=await availablePort(),token=randomBytes(32).toString('hex');
  const backend=createServer((_req,res)=>{
    res.setHeader('Content-Type','application/json');
    // Unpaid store: worker must not open any WhatsApp socket during this test.
    res.end(JSON.stringify({status:'SUCCESS',data:{can_connect:false,allowed:false}}));
  });
  await new Promise(resolve=>backend.listen(0,'127.0.0.1',resolve));
  let processHandle;
  const headers={Authorization:`Bearer ${token}`,'Content-Type':'application/json'};
  const base=`http://127.0.0.1:${managerPort}`;
  async function startManager() {
    processHandle=spawn(process.execPath,['dist/manager.js'],{cwd:resolve('.'),windowsHide:true,stdio:['ignore','pipe','pipe'],env:{...process.env,BOT_MANAGER_TOKEN:token,MANAGER_PORT:String(managerPort),SESSION_ROOT:root,WEB_BASE_URL:`http://127.0.0.1:${backend.address().port}`,LOG_LEVEL:'silent'}});
    let output='';processHandle.stdout.on('data',chunk=>{output+=chunk;});processHandle.stderr.on('data',chunk=>{output+=chunk;});
    for(let tries=0;tries<100;tries++){
      try{if((await fetch(base+'/health',{headers})).ok)return;}catch{}
      if(processHandle.exitCode!==null)throw Error('Manager failed: '+output);
      await delay(100);
    }
    throw Error('Manager did not start');
  }
  async function stopManager(){
    if(!processHandle || processHandle.exitCode!==null)return;
    const ended=new Promise(resolve=>processHandle.once('exit',resolve));processHandle.kill('SIGTERM');
    const timer=setTimeout(()=>processHandle.kill('SIGKILL'),8000);await ended;clearTimeout(timer);
  }
  const first={store_id:randomUUID(),connection_id:randomUUID(),phone:'6281234567890',method:'qr',worker_token:randomBytes(32).toString('hex')};
  const second={...first,store_id:randomUUID(),connection_id:randomUUID(),phone:'6282222222222',worker_token:randomBytes(32).toString('hex')};
  async function connect(entry){return fetch(base+`/stores/${entry.store_id}/connect`,{method:'POST',headers,body:JSON.stringify(entry)});}
  try{
    await startManager();
    assert.equal((await fetch(base+'/health')).status,401);
    assert.equal((await fetch(base+'/qr',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({payload:'000201010211TEST-ONLY'})})).status,401);
    assert.equal((await fetch(base+'/qr',{method:'POST',headers,body:JSON.stringify({payload:'short'})})).status,422);
    const encoded=await (await fetch(base+'/qr',{method:'POST',headers,body:JSON.stringify({payload:'000201010211TEST-ONLY'})})).json();assert.match(encoded.data,/^data:image\/png;base64,iVBOR/);
    assert.equal((await connect({...first,phone:'$(secret)'})).status,422);
    assert.equal((await fetch(base+'/stores/not-a-store/disconnect',{method:'POST',headers})).status,422);
    assert.equal((await fetch(base+`/stores/${first.store_id}/notifications`,{method:'POST',headers})).status,409);
    assert.equal((await connect(first)).status,200);
    assert.equal((await connect(first)).status,200);
    assert.equal((await fetch(base+`/stores/${first.store_id}/notifications`,{method:'POST',headers})).status,200);
    assert.equal((await fetch(base+`/stores/${first.store_id}/notifications`,{method:'POST'})).status,401);
    assert.equal((await (await fetch(base+'/health',{headers})).json()).workers,1);
    assert.equal((await connect(second)).status,200);
    const saved=JSON.parse(await readFile(join(root,'.manager.json'),'utf8'));
    assert.equal(saved.length,2);assert.notEqual(saved[0].worker_token,saved[1].worker_token);
    const publicHealth=await (await fetch(base+'/health',{headers})).text();
    assert.ok(!publicHealth.includes(first.worker_token));assert.ok(!publicHealth.includes(second.phone));
    await stopManager();await startManager();
    assert.equal((await (await fetch(base+'/health',{headers})).json()).workers,2);
    assert.equal((await fetch(base+`/stores/${first.store_id}/disconnect`,{method:'POST',headers})).status,200);
    const after=JSON.parse(await readFile(join(root,'.manager.json'),'utf8'));
    assert.deepEqual(after.map(entry=>entry.store_id),[second.store_id]);
    assert.equal((await (await fetch(base+'/health',{headers})).json()).workers,1);
    assert.equal((await fetch(base+`/stores/${second.store_id}/disconnect`,{method:'POST',headers})).status,200);
  }finally{
    await stopManager();await new Promise(resolve=>backend.close(resolve));
    const target=resolve(root);
    assert.ok(target.startsWith(resolve(tmpdir())+sep+'digitaltoko-manager-test-'));
    await rm(target,{recursive:true,force:true});
  }
});
