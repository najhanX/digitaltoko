import {test} from 'node:test';
import assert from 'node:assert/strict';
import {gameDestination,gameFieldsReady} from '../../resources/js/components/gameIdentity.ts';
const ml={id_type:'numeric',server_label:'Zone ID',server_options:[],destination_format:'concat'};

test('split game inputs preserve digits and produce the supplier destination explicitly',()=>{
    assert.equal(gameDestination(ml,'00123456','13486'),'0012345613486');
    assert.equal(gameDestination({...ml,destination_format:'parentheses'},'123456','13486'),'123456(13486)');
    assert.equal(gameDestination({...ml,destination_format:'pipe'},'123456','13486'),'123456|13486');
    assert.equal(gameDestination({...ml,destination_format:'id'},'123456','13486'),'123456');
    assert.equal(gameDestination({...ml,server_label:null},'243402956362890880',''),'243402956362890880');
});

test('automatic lookup waits for both required inputs and accepts supported named servers and Riot tags',()=>{
    assert.equal(gameFieldsReady(ml,'123456',''),false);
    assert.equal(gameFieldsReady(ml,'123456','13'),false);
    assert.equal(gameFieldsReady(ml,'123456','13486'),true);
    assert.equal(gameFieldsReady({...ml,server_options:['AP','EU','NA']},'123456','AP'),true);
    assert.equal(gameFieldsReady({...ml,server_options:['AP','EU','NA']},'123456','unknown'),false);
    assert.equal(gameFieldsReady({...ml,id_type:'riot',server_label:null},'Player Name#123',''),true);
    assert.equal(gameFieldsReady({...ml,id_type:'riot',server_label:null},'Player Name',''),false);
});
