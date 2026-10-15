import { inline, divider, shopReply } from './replies.js';
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
export function catalogText(page:CatalogPage,query:CatalogQuery,navigation?:readonly string[]):string {
    const money=(price:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(price);
    const lines:string[]=[`Halaman ${page.pagination.current_page}/${page.pagination.last_page} · ${page.pagination.total} produk`,
        ...(query.search ? [`Pencarian : ${inline(query.search)}`] : []), ''];
    page.items.forEach((item,index)=>{
        if (index>0) lines.push(divider.slice(2));
        lines.push(`*${inline(item.name)}*`, `Kode : ${inline(item.code)}`, `Kategori : ${inline(item.service)} / ${inline(item.category)}`,
            `${inline(item.price_label)} : *${money(item.price)}*`,
            `Status : ${item.available?'✅ Tersedia':'❌ Tidak tersedia'}`+(item.quantity!==null?` · ${item.quantity} stok`:''));
    });
    if (!page.items.length) lines.push('Produk belum ditemukan.', 'Coba kata kunci lain atau hubungi admin toko.');
    if (navigation) lines.push('',...navigation);
    else {
        lines.push('', '*PANDUAN KATALOG*', 'Layanan : #list', 'Cari produk : #produk canva');
        const suffix=query.search ? ' '+inline(query.search) : '';
        if (page.pagination.current_page>1) lines.push(`Sebelumnya : #produk ${page.pagination.current_page-1}${suffix}`);
        if (page.pagination.current_page<page.pagination.last_page) lines.push(`Berikutnya : #produk ${page.pagination.current_page+1}${suffix}`);
        lines.push('Menu utama : #menu');
    }
    return shopReply(page.store_name,'KATALOG PRODUK',lines);
}
