import type { MenuAction } from './menu.js';
export type CatalogQuery={page:number;search:string};
export type CatalogPage={store_name:string;items:{code:string;name:string;category:string;service:string;price:number;available:boolean;quantity:number|null;price_label:string}[];pagination:{current_page:number;last_page:number;total:number}};
export function catalogQuery(text:string,action:MenuAction|null):CatalogQuery|null {
    if(action==='products')return {page:1,search:''};
    if(action && typeof action==='object' && action.type==='products')return {page:action.page,search:action.search};
    const match=/^#produk(?:\s+(.*))?$/i.exec(text.trim());
    if(!match)return null;
    const input=(match[1] ?? '').trim();
    const numbered=/^([1-9][0-9]{0,5})(?:\s+(.*))?$/.exec(input);
    return numbered?{page:Number(numbered[1]),search:(numbered[2] ?? '').slice(0,120)}:{page:1,search:input.slice(0,120)};
}
export function catalogPage(value:unknown):CatalogPage {
    const page=value as CatalogPage;
    if(!page || typeof page.store_name!=='string' || !Array.isArray(page.items) || page.items.length>50
        || !page.pagination || !Number.isInteger(page.pagination.current_page) || !Number.isInteger(page.pagination.last_page) || page.pagination.current_page<1 || page.pagination.last_page<page.pagination.current_page
        || !Number.isInteger(page.pagination.total) || page.pagination.total<0)throw new Error('Invalid catalog response');
    for(const item of page.items){
        if(!item || !['code','name','category','service','price_label'].every(key=>typeof item[key as keyof typeof item]==='string')
            || !Number.isSafeInteger(item.price) || item.price<0 || typeof item.available!=='boolean'
            || item.quantity!==null && (!Number.isInteger(item.quantity) || item.quantity<0))throw new Error('Invalid catalog product');
    }
    return page;
}
const inline=(value:string)=>value.replace(/[\r\n*_~`]/g,' ').replace(/\s+/g,' ').trim().slice(0,150);
export function catalogText(page:CatalogPage,query:CatalogQuery):string {
    const money=(price:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(price);
    const rows=page.items.map(item=>'*'+inline(item.name)+'*\nKode: '+inline(item.code)+'\n'+inline(item.service)+' / '+inline(item.category)+'\n'+inline(item.price_label)+': '+money(item.price)+'\n'+(item.available?'Tersedia':'Tidak tersedia')+(item.quantity!==null?' · '+item.quantity+' stok':''));
    return '*'+inline(page.store_name)+'*\nKatalog produk · '+page.pagination.total+' produk\nHalaman '+page.pagination.current_page+'/'+page.pagination.last_page+(query.search?'\nPencarian: '+inline(query.search):'')+'\n\n'+(rows.join('\n\n') || 'Produk belum ditemukan. Coba kata kunci lain atau hubungi toko.')+'\n\n#produk — semua produk\n#produk canva — cari produk\n#produk 2 — halaman berikutnya\n\nPowered by DigitalToko';
}
