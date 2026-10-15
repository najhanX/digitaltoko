import { catalogPage, catalogText } from './catalog.js';
import { inline, shopReply } from './replies.js';
import type { BrowseQuery, MenuAction } from './menu.js';

export type BrowseItem={name:string;product_count:number;category_count?:number;service?:string};
export type BrowsePage={view:BrowseQuery['view'];store_name:string;service:string|null;category:string|null;items:BrowseItem[];pagination:{current_page:number;last_page:number;total:number}};
export const servicesQuery=():BrowseQuery=>({type:'browse',view:'services',page:1});
export function navigationQuery(text:string,action:MenuAction|null):BrowseQuery|'help'|null {
    if (action==='services' || action==='products') return servicesQuery();
    if (action && typeof action==='object' && action.type==='browse') return action;
    const match=/^#(list|stok|produk)(?:\s+(.*))?$/i.exec(text.trim());
    if (!match) return null;
    const command=match[1].toLowerCase(),input=(match[2] ?? '').trim();
    if (command==='produk') return input ? null : servicesQuery();
    const parts:{text:string;quoted:boolean}[]=[];
    try {
        for (const value of input.match(/"(?:\\.|[^"\\])*"|[^\s]+/g) ?? []) {
            parts.push({text:value.startsWith('"') ? JSON.parse(value) : value,quoted:value.startsWith('"')});
        }
    } catch {return 'help';}
    let page=1,service:string|undefined;
    const names:string[]=[];
    for (let i=0;i<parts.length;i++) {
        const part=parts[i];
        if (!part.quoted && part.text==='--halaman') {
            const value=parts[++i]?.text;
            if (!value || !/^[1-9][0-9]{0,5}$/.test(value)) return 'help';
            page=Number(value);
        } else if (!part.quoted && part.text==='--layanan' && command==='stok') {
            const rest:string[]=[];
            while (i+1<parts.length && (parts[i+1].quoted || !parts[i+1].text.startsWith('--'))) rest.push(parts[++i].text);
            service=rest.join(' ');
            if (!service || service.length>100) return 'help';
        } else if (!part.quoted && part.text.startsWith('--')) return 'help';
        else names.push(part.text);
    }
    const name=names.join(' ').trim();
    if (name.length>100 || /[\r\n]/.test(name)) return 'help';
    if (!name) return service ? 'help' : {...servicesQuery(),page};
    return command==='list' ? {type:'browse',view:'categories',service:name,page}
        : {type:'browse',view:'products',category:name,...(service?{service}:{}),page};
}
export function navigationCommand(query:BrowseQuery):string {
    const command=query.view==='services' ? '#list' : query.view==='categories'
        ? '#list '+JSON.stringify(query.service) : '#stok '+JSON.stringify(query.category)+(query.service?' --layanan '+JSON.stringify(query.service):'');
    return command+(query.page>1?' --halaman '+query.page:'');
}
export function navigationUrl(query:BrowseQuery):string {
    const params=new URLSearchParams({view:query.view,page:String(query.page)});
    if (query.service) params.set('service',query.service);
    if (query.category) params.set('category',query.category);
    return '/api/internal/bot/catalog?'+params;
}
export function navigationPage(value:unknown):BrowsePage {
    const page=value as BrowsePage;
    if (!page || !['services','categories','products'].includes(page.view) || typeof page.store_name!=='string'
        || ![page.service,page.category].every(value=>value===null || typeof value==='string' && value.length<=100)
        || !Array.isArray(page.items) || page.items.length>10 || !page.pagination
        || !Number.isInteger(page.pagination.current_page) || page.pagination.current_page<1
        || !Number.isInteger(page.pagination.last_page) || page.pagination.last_page<page.pagination.current_page
        || !Number.isInteger(page.pagination.total) || page.pagination.total<0) throw Error('Invalid navigation response');
    if (page.view==='products') catalogPage(page);
    else for (const item of page.items) {
        if (!item || typeof item.name!=='string' || !item.name || item.name.length>100 || !Number.isSafeInteger(item.product_count) || item.product_count<1
            || (page.view==='services' ? !Number.isSafeInteger(item.category_count) || item.category_count!<1 : typeof item.service!=='string' || !item.service || item.service.length>100)) throw Error('Invalid navigation item');
    }
    return page;
}
export function navigationChoices(page:BrowsePage):{title:string;description:string;action:BrowseQuery}[] {
    return page.items.map(item=>({title:item.name,description:page.view==='services'?`${item.category_count} kategori · ${item.product_count} produk`:`${item.service} · ${item.product_count} produk`,
        action:page.view==='services'?{type:'browse',view:'categories',service:item.name,page:1}:{type:'browse',view:'products',service:item.service!,category:item.name,page:1}}));
}
export function navigationText(page:BrowsePage,query:BrowseQuery):string {
    if (page.view==='products') {
        const productPage=catalogPage(page);
        const lines=['*NAVIGASI PRODUK*',...(page.pagination.current_page>1?['Sebelumnya : '+navigationCommand({...query,page:page.pagination.current_page-1})]:[]),
            ...(page.pagination.current_page<page.pagination.last_page?['Berikutnya : '+navigationCommand({...query,page:page.pagination.current_page+1})]:[]),
            ...(query.service?['Kategori utama : '+navigationCommand({type:'browse',view:'categories',service:query.service,page:1})]:[]),'Layanan : #list','Menu utama : #menu'];
        return catalogText(productPage,{page:page.pagination.current_page,search:''},lines);
    }
    const lines=[`Halaman ${page.pagination.current_page}/${page.pagination.last_page} · ${page.pagination.total} ${page.view==='services'?'layanan':'kategori'}`,
        ...(page.service?['Layanan : '+inline(page.service)]:[]),...(page.category && !page.service?['Kategori ini ada di beberapa layanan.','Pilih layanan yang Anda maksud.']:[]),''];
    for (const choice of navigationChoices(page)) lines.push('*'+inline(choice.title)+'*',inline(choice.description),'Ketik : '+navigationCommand(choice.action),'');
    if (!page.items.length) lines.push(page.view==='services'?'Belum ada layanan yang ditampilkan.':'Kategori belum ditemukan di layanan ini.','');
    if (page.pagination.current_page>1) lines.push('Sebelumnya : '+navigationCommand({...query,page:page.pagination.current_page-1}));
    if (page.pagination.current_page<page.pagination.last_page) lines.push('Berikutnya : '+navigationCommand({...query,page:page.pagination.current_page+1}));
    if (page.view==='categories') lines.push('Kembali ke layanan : #list');
    lines.push('Menu utama : #menu');
    return shopReply(page.store_name,page.view==='services'?'LIST LAYANAN':'LIST KATEGORI UTAMA',lines);
}
export function navigationHelp(storeName:string):string {
    return shopReply(storeName,'PANDUAN KATALOG',['Layanan : #list','Kategori utama : #list "Aplikasi premium"','Produk : #stok "Canva" --layanan "Aplikasi premium"','Halaman berikut : #list --halaman 2','Pencarian langsung : #produk canva']);
}
