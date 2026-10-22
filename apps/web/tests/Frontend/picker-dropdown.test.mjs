import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createElement} from 'react';
import {renderToString} from 'react-dom/server';
import ts from 'typescript';

const source=await readFile(new URL('../../resources/js/components/PickerDropdown.tsx',import.meta.url),'utf8');
const compiled=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.ESNext,jsx:ts.JsxEmit.ReactJSX,target:ts.ScriptTarget.ES2022}}).outputText
    .replace(/from (["'])(react(?:\/jsx-runtime)?|lucide-react)\1/g,(_,quote,name)=>'from '+JSON.stringify(import.meta.resolve(name)));
const {default:PickerDropdown}=await import('data:text/javascript;base64,'+Buffer.from(compiled).toString('base64'));

test('picker trigger is the native toggle invoker for its own auto-dismiss popover',()=>{
    const markup=renderToString(createElement(PickerDropdown,{label:'Pilih penangkap',summary:'Pending'},createElement('input',{type:'search'})));
    const target=markup.match(/popovertarget="([^"]+)"/i)?.[1];
    assert.ok(target);
    assert.ok(markup.includes('id="'+target+'" popover="auto"'));
    assert.ok(markup.includes('aria-controls="'+target+'"'));
    assert.match(markup,/popovertargetaction="toggle"/i);
    assert.match(markup,/aria-expanded="false"/);
});
