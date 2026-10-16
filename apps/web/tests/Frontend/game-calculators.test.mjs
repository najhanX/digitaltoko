import {test} from 'node:test';
import assert from 'node:assert/strict';
import {winsNeeded,diamondEstimate} from '../../resources/js/components/gameCalculators.ts';

test('win rate counts additional wins and rounds up without treating 100 percent as reachable after losses',()=>{
    assert.equal(winsNeeded(100,50,60),25);
    assert.equal(winsNeeded(100,50,61),29);
    assert.equal(winsNeeded(100,60,60),0);
    assert.equal(winsNeeded(100,70,60),0);
    assert.equal(winsNeeded(100,100,100),0);
    assert.throws(()=>winsNeeded(100,99,100));
    for(const input of [[0,50,60],[-1,50,60],[1.2,50,60],[100,-1,60],[100,50,101],[100,NaN,60]])assert.throws(()=>winsNeeded(...input));
});

test('diamond estimates use five-spin bundles and single-spin remainder with explicit boundaries',()=>{
    assert.equal(diamondEstimate('magic-wheel',0),10800);
    assert.equal(diamondEstimate('magic-wheel',194),330);
    assert.equal(diamondEstimate('magic-wheel',199),60);
    assert.equal(diamondEstimate('magic-wheel',200),0);
    assert.equal(diamondEstimate('zodiac',0),1700);
    assert.equal(diamondEstimate('zodiac',99),20);
    assert.equal(diamondEstimate('zodiac',100),0);
    for(const points of [-1,201,1.5,NaN])assert.throws(()=>diamondEstimate('magic-wheel',points));
});
