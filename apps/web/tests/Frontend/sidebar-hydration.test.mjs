import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createElement} from 'react';
import {renderToString} from 'react-dom/server';
import {Package} from 'lucide-react';
import ts from 'typescript';

const source=await readFile(new URL('../../resources/js/components/SidebarSection.tsx',import.meta.url),'utf8');
const compiled=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.ESNext,jsx:ts.JsxEmit.ReactJSX,target:ts.ScriptTarget.ES2022}}).outputText
    .replace(/from (["'])(react(?:\/jsx-runtime)?|lucide-react)\1/g,(_,quote,name)=>'from '+JSON.stringify(import.meta.resolve(name)));
const {default:SidebarSection}=await import('data:text/javascript;base64,'+Buffer.from(compiled).toString('base64'));
const base={label:'Produk & harga',icon:Package,links:[{href:'/products',active:false}],storageKey:'test-sidebar',collapsed:false,onExpand:()=>{}};
function html(props={}){return renderToString(createElement(SidebarSection,{...base,...props},createElement('a',{href:'/products'},'Produk')));}

test('initial server and browser markup agree even with a saved open accordion',()=>{
    const previous=Object.getOwnPropertyDescriptor(globalThis,'sessionStorage');
    try{
        delete globalThis.sessionStorage;
        const server=html();
        let reads=0;
        Object.defineProperty(globalThis,'sessionStorage',{configurable:true,value:{getItem(){reads++;return 'open';}}});
        const browserInitial=html();
        assert.equal(browserInitial,server);
        assert.equal(reads,0,'browser preferences must be restored after hydration');
        assert.match(server,/aria-expanded="false"/);
        assert.match(server,/hidden=""/);
    }finally{if(previous)Object.defineProperty(globalThis,'sessionStorage',previous);else delete globalThis.sessionStorage;}
});

test('active and collapsed navigation have deterministic accessible attributes',()=>{
    const active=html({links:[{href:'/products',active:true}]});
    assert.match(active,/aria-expanded="true"/);
    assert.doesNotMatch(active,/hidden=""/);
    const collapsed=html({links:[{href:'/products',active:true}],collapsed:true});
    assert.match(collapsed,/aria-expanded="false"/);
    assert.match(collapsed,/hidden=""/);
});
