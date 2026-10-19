import {test} from 'node:test';
import assert from 'node:assert/strict';
import {categoryDestination} from '../../resources/js/components/categoryDestination.ts';

test('manual game fields preserve ID digits and agree with supplier formats',()=>{
    const profile={type:'game',columns:2,category_id:'category',format:'concat',label:'ID game'};
    for(const [format,expected] of [['auto','001234560123'],['concat','001234560123'],['parentheses','00123456(0123)'],['pipe','00123456|0123'],['space','00123456 0123'],['id','00123456']]){
        assert.equal(categoryDestination({...profile,format},'00123456','0123'),expected);
    }
    assert.equal(categoryDestination({...profile,columns:1},'243402956362890880','ignored'),'243402956362890880');
});
