import {test} from 'node:test';
import assert from 'node:assert/strict';
import {bannerHref, colorInk, customerServiceUrl, designStyle, validBannerUrl, themePresets, themePresetGroups} from '../../resources/js/components/storefrontDesign.ts';

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

test('all 24 palettes provide complete colors and readable text on their own backgrounds',()=>{
    const luminance=color=>{
        const rgb=[1,3,5].map(index=>parseInt(color.slice(index,index+2),16)/255).map(value=>value<=.04045?value/12.92:((value+.055)/1.055)**2.4);
        return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;
    };
    const contrast=(a,b)=>{const left=luminance(a),right=luminance(b);return (Math.max(left,right)+.05)/(Math.min(left,right)+.05);};
    const presets=Object.values(themePresets);
    assert.equal(presets.length,24);
    for(const group of Object.keys(themePresetGroups))assert.equal(presets.filter(preset=>preset.group===group).length,8,group);
    assert.equal(new Set(presets.map(preset=>preset.name)).size,24);
    for(const preset of presets){
        assert.equal(Object.keys(preset.colors).length,6,preset.name);
        for(const color of [preset.accent,...Object.values(preset.colors)])assert.match(color,/^#[0-9a-f]{6}$/i,preset.name);
        for(const surface of [preset.colors.background,preset.colors.surface]){
            assert.ok(contrast(preset.colors.text,surface)>=4.5,preset.name+' main text');
            assert.ok(contrast(preset.colors.muted,surface)>=4.5,preset.name+' supporting text');
            assert.ok(contrast(preset.accent,surface)>=4.5,preset.name+' accent text');
        }
        assert.ok(contrast(colorInk(preset.accent),preset.accent)>=4.5,preset.name+' button text');
        const style=designStyle({design:preset});
        assert.equal(style['--sf-bg'],preset.colors.background);
        assert.equal(style['--sf-accent'],preset.accent);
    }
});
