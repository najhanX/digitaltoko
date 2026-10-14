export function parseStockEntries(input:string):string[] {
    return input.split(/^[ \t]*---[ \t]*\r?$/m)
        .map(value=>value.replace(/^(?:\r?\n)+|(?:\r?\n)+$/g,''))
        .filter(value=>value.trim()!=='');
}
