import {test} from 'node:test';
import assert from 'node:assert/strict';
import {bannerHref, colorInk, customerServiceUrl, designStyle, validBannerUrl, themePresets} from '../../resources/js/components/storefrontDesign.ts';

test('banner links support category filters, HTTPS, local pages, and no destination',()=>{
    assert.equal(bannerHref({link_type:'category',category:'Games & Voucher'}),'/?category=Games%20%26%20Voucher#catalog');
    assert.equal(bannerHref({link_type:'none',href:'https://example.com'}),null);
    assert.equal(bannerHref({href:'#catalog'}),'#catalog');
    assert.equal(bannerHref({href:'https://example.com/promo?q=game'}),'https://example.com/promo?q=game');
    for(const url of ['javascript:alert(1)','//example.com','/\\example.com','https://user:pass@example.com','http://example.com','https://example.com/\npath'])assert.equal(validBannerUrl(url),false,url);
});

test('theme variables map all palette colors and select readable primary button text',()=>{
    const style=designStyle({design:themePresets.forest});
    assert.equal(style['--sf-bg'],'#f3f8f5');
    assert.equal(style['--sf-card'],'#ffffff');
    assert.equal(style['--sf-secondary'],'#b66a17');
    assert.equal(style['--sf-line'],'#d5e3dc');
    assert.equal(colorInk('#ffffff'),'#101523');
    assert.equal(colorInk('#000000'),'#ffffff');
    assert.deepEqual(designStyle(null),{});
    assert.equal(designStyle({design:{accent:'red;background:url(evil)',colors:{background:'bad'}}})['--sf-bg'],undefined);
});

test('customer service chat normalizes Indonesian numbers and encodes the opening message',()=>{
    const settings={enabled:true,phone:'0812 3456-7890',message:'Halo & selamat pagi'};
    assert.equal(customerServiceUrl(settings),'https://wa.me/6281234567890?text=Halo%20%26%20selamat%20pagi');
    assert.equal(customerServiceUrl({...settings,phone:'+6281234567890'}),customerServiceUrl(settings));
    assert.equal(customerServiceUrl({...settings,enabled:false}),null);
    assert.equal(customerServiceUrl({...settings,phone:'javascript:alert(1)'}),null);
});
