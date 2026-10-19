import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {mkdtemp, readFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join, resolve, dirname, basename} from 'node:path';
import {randomUUID, randomBytes} from 'node:crypto';
import {setTimeout as delay} from 'node:timers/promises';

for (const storageFailure of [false, true]) {
  test(storageFailure ? 'QR storage failure stops the worker before reconnecting with unsaved credentials'
    : 'worker completes QR pair-success -> durable credentials -> restart -> connected without a second QR', {timeout:15000}, async () => {
    const directory = await mkdtemp(join(tmpdir(),'digitaltoko-qr-login-'));
    const storeId = randomUUID(), connectionId = randomUUID(), token = randomBytes(32).toString('hex');
    const events = [];
    const backend = createServer(async (req,res) => {
      assert.equal(req.headers.authorization, 'Bearer '+token);
      res.setHeader('Content-Type','application/json');
      if (req.url === '/api/internal/bot/events') {
        let body='';for await (const chunk of req) body+=chunk;
        events.push(JSON.parse(body));
        res.end(JSON.stringify({status:'SUCCESS'}));
      } else if (req.url === '/api/internal/bot/notices') {
        res.end(JSON.stringify({data:[]}));
      } else {
        assert.equal(req.url, '/api/internal/bot/access');
        res.end(JSON.stringify({status:'SUCCESS',data:{can_connect:true,allowed:true,connection_id:connectionId}}));
      }
    });
    await new Promise(resolve => backend.listen(0,'127.0.0.1',resolve));
    const child = spawn(process.execPath,['--import','./test/fixtures/qr-login-loader.mjs','dist/index.js'], {
      cwd:resolve('.'),windowsHide:true,stdio:['ignore','pipe','pipe'],env:{...process.env,
        STORE_ID:storeId,CONNECTION_ID:connectionId,WORKER_TOKEN:token,WA_ENABLED:'true',PAIRING_METHOD:'qr',BOT_PHONE:'6281234567890',
        PORT:'0',WEB_BASE_URL:`http://127.0.0.1:${backend.address().port}`,SESSION_ROOT:directory,
        QR_TEST_STORAGE_FAILURE:String(storageFailure),LOG_LEVEL:'silent'},
    });
    let output='';child.stdout.on('data',chunk=>{output+=chunk;});child.stderr.on('data',chunk=>{output+=chunk;});
    try {
      for (let attempt=0;attempt<110;attempt++) {
        if (events.some(event=>event.status === (storageFailure?'ERROR':'CONNECTED'))) break;
        if (child.exitCode!==null) throw Error('Fixture worker exited: '+output);
        await delay(100);
      }
      assert.equal(events.filter(event=>event.status==='PAIRING').length,1,'only the first QR may be published');
      if (storageFailure) {
        assert.equal(events.at(-1)?.error,'CREDENTIAL_FAILED');
        assert.ok(!events.some(event=>event.status==='CONNECTED'));
      } else {
        assert.equal(events.at(-1)?.status,'CONNECTED',output);
        assert.equal(events.at(-1)?.phone,'6281234567890');
        const saved=JSON.parse(await readFile(join(directory,storeId,'creds.json'),'utf8'));
        assert.equal(saved.registered,false,'signed QR session does not need a registered=true override');
        assert.ok(saved.me && saved.account);
        assert.ok(!events.some(event=>event.status==='ERROR' || event.status==='EXPIRED'));
        const accepted = events.findIndex(event=>event.status==='PAIRING');
        assert.equal(events[accepted+1].status,'CONNECTING');
        assert.equal(events[accepted+1].qr_image,undefined);
      }
    } finally {
      const stopped = new Promise(resolve => child.once('exit',resolve));
      if (child.exitCode===null) {child.kill('SIGTERM');await stopped;}
      await new Promise(resolve=>backend.close(resolve));
      assert.equal(dirname(resolve(directory)),resolve(tmpdir()));
      assert.ok(basename(directory).startsWith('digitaltoko-qr-login-'));
      await rm(directory,{recursive:true,force:true});
    }
  });
}
