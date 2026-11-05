import {test} from 'node:test';
import assert from 'node:assert/strict';
import {storefrontViewport} from '../../resources/js/components/storefrontDesign.ts';

test('browser canvas follows storefront defaults and legacy template aliases',()=>{
    for(const template of ['nebula','promo']) assert.deepEqual(storefrontViewport(template),{background:'#0c101c',colorScheme:'dark'});
    for(const template of ['atlas','category']) assert.deepEqual(storefrontViewport(template),{background:'#f5f7fa',colorScheme:'light'});
    for(const template of ['aurora','minimal',undefined]) assert.deepEqual(storefrontViewport(template),{background:'#f8fafc',colorScheme:'light'});
});

test('custom palette determines native browser scheme independently of template or panel theme',()=>{
    assert.deepEqual(storefrontViewport('aurora','#0c101c'),{background:'#0c101c',colorScheme:'dark'});
    assert.deepEqual(storefrontViewport('nebula','#ffffff'),{background:'#ffffff',colorScheme:'light'});
    assert.deepEqual(storefrontViewport('atlas','#1b2440'),{background:'#1b2440',colorScheme:'dark'});
    for(const invalid of ['white','url(example.com)','', '#123', '#ffffffff']) assert.deepEqual(storefrontViewport('nebula',invalid),storefrontViewport('nebula'));
});
