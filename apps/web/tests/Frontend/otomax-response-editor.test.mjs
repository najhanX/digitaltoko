import {test} from 'node:test';
import assert from 'node:assert/strict';
import {responseRuleSelection,restoreResponseRule,newResponseRule,incompleteResponseRule,responseSegments,depositResponseExample} from '../../resources/js/components/otomaxResponseEditor.ts';
const success={name:'Sukses OtomaX',event:'callback',outcome:'success',active:true,template:'[refid] SUKSES [sn]'};
const pending={name:'Permintaan diterima',event:'transaction',outcome:'pending',active:true,template:'[refid] akan diproses'};

test('reopening the group keeps pending selected without removing or replacing the success catcher',()=>{
    const rules=[{...success},{...pending}];
    const saved=JSON.parse(JSON.stringify(rules));
    const selection=responseRuleSelection(rules,1);
    assert.equal(restoreResponseRule(saved,selection),1);
    assert.equal(saved[1].event,'transaction');
    assert.deepEqual(saved,[success,pending]);
    assert.equal(restoreResponseRule([pending,success],selection),0);
});

test('removed catchers and corrupt browser preferences safely fall back to an existing catcher',()=>{
    const selected=responseRuleSelection([success,pending],1);
    assert.equal(restoreResponseRule([success],selected),0);
    for(const malformed of [null,'broken','null','42','{}']) assert.equal(restoreResponseRule([success,pending],malformed),0);
    assert.equal(restoreResponseRule([],selected),0);
});

test('adding an example creates an independent catcher and retains its event for testing',()=>{
    const example=newResponseRule(pending);
    example.name='Pending format kedua';
    assert.equal(pending.name,'Permintaan diterima');
    assert.equal(example.event,'transaction');
    assert.equal(example.outcome,'pending');
    assert.equal(newResponseRule().template,'');
    assert.equal(responseRuleSelection([],0),null);
});

test('boundary and regex forms validate their own required inputs while legacy templates remain editable',()=>{
    const rule=newResponseRule();assert.equal(incompleteResponseRule(rule),true);
    const boundary={...rule,keyword:'SUKSES',captures:[{field:'sn',prefix:'SN:',suffix:'.',required:true}]};assert.equal(incompleteResponseRule(boundary),false);
    assert.equal(incompleteResponseRule({...boundary,captures:[...boundary.captures,...boundary.captures]}),true);
    assert.equal(incompleteResponseRule({...rule,matcher:'regex',regex:'(?<sn>.+)'}),false);
    assert.equal(incompleteResponseRule(success),false);
});

test('highlighting preserves Unicode, escapes through React text, and rejects overlapping or invalid spans',()=>{
    const text='😀 SN: 001/<b> END';
    const segments=responseSegments(text,[{field:'sn',value:'001/<b>',start:6,length:7},{field:'bad',value:'x',start:7,length:2},{field:'bad',value:'x',start:-1,length:1}]);
    assert.equal(segments.map(s=>s.text).join(''),text);assert.equal(segments.filter(s=>s.field).length,1);assert.equal(segments.find(s=>s.field).text,'001/<b>');
});

test('deposit starter is complete, pending and independent for each new catcher',()=>{
    const rule=newResponseRule(depositResponseExample());
    assert.equal(rule.event,'ticket');assert.equal(rule.outcome,'pending');
    assert.equal(incompleteResponseRule(rule),false);
    assert.deepEqual(rule.captures,[{field:'amount',prefix:'Silakan Transfer Rp. ',suffix:' (HARUS SAMA)',required:true}]);
    rule.captures[0].prefix='Changed';
    assert.equal(depositResponseExample().captures[0].prefix,'Silakan Transfer Rp. ');
});
