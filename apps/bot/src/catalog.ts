import { inline, shopReply } from './replies.js';
import type { MenuAction } from './menu.js';
export type CatalogQuery={page:number;search:string};
export type CatalogPage={store_name:string;items:{code:string;name:string;category:string;service:string;price:number;available:boolean;quantity:number|null;price_label:string;postpaid_fees?:{service_admin:number;store_admin:number}|null}[];pagination:{current_page:number;last_page:number;total:number}};
export function productChoices(page:CatalogPage):{title:string;description:string;action:MenuAction}[] {
    return page.items.filter(item=>item.available).slice(0,10).map(item=>({title:inline(item.name),description:new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(item.price)+' · '+inline(item.code),action:{type:'select-product',code:item.code}}));
}
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
export function catalogText(page:CatalogPage,query:CatalogQuery,context?:{service?:string;category?:string}):string {
    const money=(price:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(price);
    const lines:string[]=[...(context?.category?[inline(context.category)+(context.service?' · '+inline(context.service):'')]:[]),
        `Halaman ${page.pagination.current_page}/${page.pagination.last_page} · ${page.pagination.total} produk`,
        ...(query.search?[`Cari: ${inline(query.search)}`]:[]),''];
    page.items.forEach((item,index)=>{
        if(index>0)lines.push('');
        lines.push(`*${index+1}. ${inline(item.name)}*`,`${item.price_label==='Harga'?'':inline(item.price_label)+': '}${money(item.price)} · ${inline(item.code)}`,
            ...(item.postpaid_fees?['Admin layanan '+money(item.postpaid_fees.service_admin)+' · Admin toko '+money(item.postpaid_fees.store_admin)]:[]),
            !item.available?'Tidak tersedia':item.quantity!==null?`${item.quantity} stok`:'Tersedia');
    });
    if(!page.items.length)lines.push('Produk belum ditemukan.');
    return shopReply(page.store_name,'DAFTAR PRODUK',lines);
}
export function catalogNavigation(page:CatalogPage,query:CatalogQuery):string {
    const suffix=query.search?' '+inline(query.search):'';
    return ['Pilih produk melalui tombol daftar.',
        ...(page.pagination.current_page>1?[`Sebelumnya : #produk ${page.pagination.current_page-1}${suffix}`]:[]),
        ...(page.pagination.current_page<page.pagination.last_page?[`Berikutnya : #produk ${page.pagination.current_page+1}${suffix}`]:[]),
        'Ketik #beli KODE untuk mulai, atau #menu.'].join('\n');
}
