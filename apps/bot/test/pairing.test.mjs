import test from 'node:test';
import assert from 'node:assert/strict';
import {getCompanionPlatformId} from '@whiskeysockets/baileys';
import {createPairing, linkedPhone, pairingFailure, pairingBrowser, clearIncompletePairingState} from '../dist/utils/pairing.js';

test('code uses canonical Chrome companion identity and QR keeps its existing identity',async()=>{
  const codeBrowser=pairingBrowser('code'), qrBrowser=pairingBrowser('qr');
  assert.equal(codeBrowser[0],'Mac OS');assert.equal(codeBrowser[1],'Chrome');
  assert.equal(qrBrowser[0],'Mac OS');assert.equal(qrBrowser[1],'Google Chrome');
  assert.equal(getCompanionPlatformId(codeBrowser),'1');
  assert.equal(getCompanionPlatformId(qrBrowser),'9');
  const states=[];
  const pairing=createPairing({method:'code',phone:'6281234567890',requestCode:async()=>{
    if(getCompanionPlatformId(codeBrowser)!=='1')throw {output:{statusCode:400}};
    return 'ABCDEFGH';
  },publish:async state=>{states.push(state);},fail:async()=>{throw Error('Companion identity rejected');}});
  await pairing.update({qr:'server-ready'},false);
  assert.equal(states[0].pairing_code,'ABCDEFGH');
});

test('unfinished pairing metadata is cleared without deleting identity or signal keys',()=>{
  const identity={private:Buffer.from('private-key')}, signalKeys={key:'saved-signal-key'};
  const credentials={registered:false,me:{id:'6281234567890@s.whatsapp.net'},pairingCode:'ABCDEFGH',noiseKey:identity,signalKeys};
  assert.equal(clearIncompletePairingState(credentials),true);
  assert.equal(credentials.me,undefined);assert.equal(credentials.pairingCode,undefined);
  assert.equal(credentials.noiseKey,identity);assert.equal(credentials.signalKeys,signalKeys);
  assert.equal(clearIncompletePairingState(credentials),false);
});

test('registered WhatsApp credentials are preserved during startup recovery',()=>{
  const credentials={registered:true,me:{id:'6281234567890@s.whatsapp.net'},pairingCode:'ABCDEFGH'};
  const before=structuredClone(credentials);
  assert.equal(clearIncompletePairingState(credentials),false);
  assert.deepEqual(credentials,before);
});

test('QR is encoded locally as PNG with a finite expiry; code transport is unused', async()=>{
  const states=[];
  const pairing=createPairing({method:'qr',now:()=>1000,requestCode:async()=>{throw Error('unexpected');},publish:async state=>{states.push(state);},fail:async()=>{throw Error('unexpected failure');}});
  await pairing.update({qr:'test-pairing-payload'},false);
  assert.equal(states.length,1);
  assert.match(states[0].qr_image,/^data:image\/png;base64,/);
  const png=Buffer.from(states[0].qr_image.split(',')[1],'base64');
  assert.equal(png.subarray(0,8).toString('hex'),'89504e470d0a1a0a');
  assert.equal(states[0].expires_at,new Date(61000).toISOString());
  assert.equal(states[0].pairing_code,undefined);
});

test('code waits for server QR readiness and requests once even during concurrent updates',async()=>{
  let requests=0,finish;const states=[];
  const pairing=createPairing({method:'code',phone:'6281234567890',now:()=>1000,requestCode:async phone=>{requests++;assert.equal(phone,'6281234567890');return await new Promise(resolve=>{finish=resolve;});},publish:async state=>{states.push(state);},fail:async()=>{throw Error('unexpected');}});
  await pairing.update({},false);assert.equal(requests,0);
  await pairing.update({connection:'connecting'},false);
  assert.equal(requests,0);
  const first=pairing.update({qr:'server-pairing-ref'},false);
  await pairing.update({qr:'hidden'},false);
  assert.equal(requests,1);finish('ABCDEFGH');await first;
  assert.equal(states[0].pairing_code,'ABCDEFGH');
  assert.equal(states[0].qr_image,undefined);
  assert.equal(states[0].expires_at,new Date(121000).toISOString());
});

test('early connecting event cannot fail pairing on a transport that has not opened yet',async()=>{
  let ready=false,requests=0;const states=[],failures=[];
  const pairing=createPairing({method:'code',phone:'6281234567890',requestCode:async()=>{
    requests++;
    if(!ready)throw {output:{statusCode:428},message:'Connection Closed'};
    return 'ABCDEFGH';
  },publish:async state=>{states.push(state);},fail:async failure=>{failures.push(failure);}});
  await pairing.update({connection:'connecting'},false);
  await pairing.update({},false);
  assert.equal(requests,0);assert.deepEqual(failures,[]);
  ready=true;
  await pairing.update({qr:'server-ref-after-handshake'},false);
  assert.equal(requests,1);assert.equal(states[0].pairing_code,'ABCDEFGH');assert.deepEqual(failures,[]);
});

test('late code cannot appear after socket closed or connected',async()=>{
  let finish;const states=[];
  const pairing=createPairing({method:'code',phone:'6281234567890',requestCode:()=>new Promise(resolve=>{finish=resolve;}),publish:async state=>{states.push(state);},fail:async()=>{throw Error('unexpected');}});
  const pending=pairing.update({qr:'trigger'},false);pairing.close();finish('ABCDEFGH');await pending;
  assert.deepEqual(states,[]);
});

test('registered sessions do not request new pairing and failures do not expose transport errors',async()=>{
  let requests=0,failures=0;const states=[];
  const pairing=createPairing({method:'code',phone:'6281234567890',requestCode:async()=>{requests++;throw Error('secret');},publish:async state=>{states.push(state);},fail:async()=>{failures++;}});
  await pairing.update({qr:'hidden'},true);assert.equal(requests,0);
  await pairing.update({qr:'hidden'},false);await pairing.update({qr:'again'},false);
  assert.equal(requests,1);assert.equal(failures,1);assert.deepEqual(states,[]);
});

test('linked phone strips device ID and rejects LID as proof of phone',()=>{
  assert.equal(linkedPhone({id:'6281234567890:2@s.whatsapp.net'}),'6281234567890');
  assert.equal(linkedPhone({id:'999@lid',phoneNumber:'6281234567890@s.whatsapp.net'}),'6281234567890');
  assert.equal(linkedPhone({id:'999@lid'}),undefined);
});

test('failure diagnostics retain only safe reason/status and never include secrets',async()=>{
  const error={output:{statusCode:428},message:'private code ABCDEFGH phone 6281234567890',data:{qr:'private-qr'},stack:'private-stack'};
  assert.deepEqual(pairingFailure(error),{reason:'CONNECTION_CLOSED',status_code:428});
  assert.deepEqual(pairingFailure({name:'OperationTimeoutError',message:'private'}),{reason:'TIMEOUT'});
  assert.deepEqual(pairingFailure({output:{statusCode:400}}),{reason:'REQUEST_REJECTED',status_code:400});
  assert.deepEqual(pairingFailure(null),{reason:'UNKNOWN'});
  const failures=[];
  const pairing=createPairing({method:'code',phone:'6281234567890',requestCode:async()=>{throw error;},publish:async()=>{throw Error('unexpected');},fail:async diagnostic=>{failures.push(diagnostic);}});
  await pairing.update({qr:'server-ready'},false);
  assert.deepEqual(failures,[{reason:'CONNECTION_CLOSED',status_code:428}]);
});
