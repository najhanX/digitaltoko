import {spawnSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname,resolve} from 'node:path';

const cwd=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const version=JSON.parse(readFileSync(resolve(cwd,'node_modules/@whiskeysockets/baileys/package.json'),'utf8')).version;
if(version!=='7.0.0-rc14') throw new Error('Patch hanya untuk Baileys 7.0.0-rc14.');
const patch='patches/@whiskeysockets+baileys+7.0.0-rc14.patch';
const run=(args)=>spawnSync('git',['apply',...args,patch],{cwd,encoding:'utf8'});
const already=run(['--check','--reverse']);
if(already.error) throw new Error('Git wajib terpasang untuk patch Baileys.');
if(already.status===0){console.log('Patch Baileys sudah terpasang.');}
else {
    const check=run(['--check']);
    if(check.status!==0) throw new Error('Source Baileys tidak cocok dengan patch; install dihentikan.');
    const applied=run([]);
    if(applied.status!==0) throw new Error('Patch Baileys gagal diterapkan.');
    console.log('Patch Baileys diterapkan.');
}
