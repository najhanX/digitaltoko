import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readQrisImage } from '../../resources/js/components/readQrisImage.ts';

const requireBot = createRequire(new URL('../../../bot/package.json', import.meta.url));
const QRCode = requireBot('qrcode');

test('reads actual QR pixels locally and revokes image URL', async () => {
    const payload = '00020101021153033605802ID6304TEST';
    const qr = QRCode.create(payload).modules;
    const size = (qr.size + 8) * 8;
    const pixels = new Uint8ClampedArray(size * size * 4).fill(255);
    for (let y=0; y<qr.size; y++) for (let x=0; x<qr.size; x++) if (qr.get(y,x)) {
        for (let dy=0;dy<8;dy++) for (let dx=0;dx<8;dx++) {
            const i=(((y+4)*8+dy)*size+(x+4)*8+dx)*4;
            pixels[i]=pixels[i+1]=pixels[i+2]=0;
        }
    }
    const oldImage=globalThis.Image,oldDocument=globalThis.document,oldCreate=URL.createObjectURL,oldRevoke=URL.revokeObjectURL;
    let revoked=0;
    globalThis.Image=class {naturalWidth=size;naturalHeight=size;async decode(){}};
    globalThis.document={createElement:()=>({getContext:()=>({fillRect(){},drawImage(){},getImageData:()=>({data:pixels,width:size,height:size})})})};
    URL.createObjectURL=()=> 'blob:local-fixture';URL.revokeObjectURL=()=>{revoked++;};
    try {
        assert.equal(await readQrisImage(new File(['fixture'],'qris.png',{type:'image/png'})),payload);
        pixels.fill(255);
        await assert.rejects(readQrisImage(new File(['fixture'],'empty.jpg',{type:'image/jpeg'})),/tidak terbaca/);
        assert.equal(revoked,2);
    } finally {
        globalThis.Image=oldImage;globalThis.document=oldDocument;URL.createObjectURL=oldCreate;URL.revokeObjectURL=oldRevoke;
    }
});

test('rejects unsupported and oversized files before allocating image pixels', async () => {
    await assert.rejects(readQrisImage(new File(['<svg/>'],'bad.svg',{type:'image/svg+xml'})),/PNG/);
    await assert.rejects(readQrisImage(new File([new Uint8Array(5*1024*1024+1)],'big.png',{type:'image/png'})),/5 MB/);
});
