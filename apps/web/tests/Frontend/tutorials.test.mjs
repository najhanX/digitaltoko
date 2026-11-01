import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { tutorialForPath } from '../../resources/js/components/tutorialRoutes.ts';
const guides = JSON.parse(readFileSync(new URL('../../resources/js/tutorialCatalog.json', import.meta.url), 'utf8'));

test('listener guide covers development mode, RSA authorization and commands for all four terminals', () => {
    const guide=guides.find(item=>item.slug==='aktifkan-listener');
    assert.equal(guide.steps.length,12);
    const text=guide.steps.flatMap(step=>step.paragraphs).join(' ');
    assert.match(text,/7 kali/);assert.match(text,/unauthorized/);assert.match(text,/RSA/);
    const steps=guide.steps.filter(step=>step.commands);
    assert.equal(steps.length,4);
    for(const step of steps) assert.deepEqual(step.commands.map(command=>command.platform),['windows-cmd','windows-powershell','macos','linux']);
    assert.ok(steps[2].commands.every(command=>command.code.endsWith('shell cmd notification allow_listener id.hansdev.forwarder/.ForwardListener')));
    assert.ok(guides.find(item=>item.slug==='pembayaran-custom').related.includes('aktifkan-listener'));
});

test('each panel menu links to an existing relevant guide, including nested pages and staff tab', () => {
    const scenarios = {sources:'digiflazz',otomax:'otomax',payments:'midtrans',products:'katalog','catalog-structure':'katalog',stock:'stok',bot:'whatsapp',notifications:'notifikasi',articles:'artikel',vouchers:'voucher',subscription:'langganan',orders:'transaksi',customers:'pelanggan',closure:'penutupan'};
    for (const [menu, expected] of Object.entries(scenarios)) {
        assert.equal(tutorialForPath('/stores/demo/'+menu), expected);
        assert.ok(guides.some(item => item.slug === expected));
    }
    assert.equal(tutorialForPath('/owner/stores/demo/manage','staff'),'staff');
    assert.equal(tutorialForPath('/owner/stores/demo/manage','design'),'tampilan');
    assert.equal(tutorialForPath('/stores/demo/orders/ORDER'),'transaksi');
    assert.equal(tutorialForPath('/stores/demo/otomax/tester'),'otomax');
    assert.equal(tutorialForPath('/admin/plans'),'admin');
    assert.equal(tutorialForPath('/owner/profile'),'mulai');
});
