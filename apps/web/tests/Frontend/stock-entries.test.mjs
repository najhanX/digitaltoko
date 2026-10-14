import {test} from 'node:test';
import assert from 'node:assert/strict';
import {parseStockEntries} from '../../resources/js/components/stockEntries.ts';
test('multiline accounts separated by a delimiter become independent stock rows',()=>{
    assert.deepEqual(parseStockEntries('email: first\npassword: secret\n---\nemail: second\npassword: other'),['email: first\npassword: secret','email: second\npassword: other']);
    assert.deepEqual(parseStockEntries('first\r\n---\r\nsecond'),['first','second']);
});
test('credential whitespace and embedded hyphens are preserved',()=>{
    assert.deepEqual(parseStockEntries(' password with spaces \n---\nsecret---inside'),[' password with spaces ','secret---inside']);
    assert.deepEqual(parseStockEntries(' \n---\n\n---\n'),[]);
});
