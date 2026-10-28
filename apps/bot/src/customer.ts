import type { WAMessage, WASocket } from '@whiskeysockets/baileys';
import type { QrisTracker } from './qris-messages.js';
import { commerceInput,handleCommerce } from './commerce.js';
import { MenuTokens, type MenuAction } from './menu.js';
import { readCustomerMessage } from './messages.js';
import { sendMenu, sendControls, sendChoices } from './interactive.js';
import { catalogPage, catalogQuery, catalogText,catalogNavigation,productChoices } from './catalog.js';
import { navigationQuery, navigationPage, navigationText,navigationControls, navigationUrl, navigationChoices, servicesQuery, navigationHelp } from './navigation.js';
import { catalogErrorText, expiredMenuText,unknownCommandText } from './replies.js';

export async function handleCustomerMessage(sock:WASocket,message:WAMessage,tokens:MenuTokens,api:(path:string,body?:unknown)=>Promise<unknown>,qris?:QrisTracker):Promise<void> {
    const chat=message.key.remoteJid;
    if (!chat || message.key.fromMe || chat==='status@broadcast' || chat.endsWith('@g.us') || chat.endsWith('@broadcast') || chat.endsWith('@newsletter')) return;
    const sender=chat;
    const input=readCustomerMessage(message);
    const command=input.text.toLowerCase();
    let action:MenuAction|null=input.selection!==null ? tokens.consume(input.selection,chat,sender,input.scope) : null;
    let expired=input.selection!==null && action===null;
    if(input.selection===null&&!input.text.startsWith('#')&&input.text) {
        const pending=tokens.takeProductInput(chat,sender,input.scope);
        if(pending){expired=pending.expired;action=expired?null:{type:'product-input',code:pending.code,input:input.text};}
    }
    if (input.selection===null) {
        if (['#menu','#start','#help','#bantuan','#format','#batal'].includes(command)) action='menu';
        else if (command==='#saldo') action='balance';
        else if (/^#(?:status|cektrx)(?:\s|$)/.test(command)) action='status';
        else if(command==='#konfirmasi'||/^#pilihbayar(?:\s|$)/.test(command)){
            const id=tokens.checkoutId(chat,sender,input.scope),method=/^#pilihbayar\s+(saldo|qris|direct)$/.exec(command);
            if(id&&(method||command==='#konfirmasi'))action=method?{type:'payment-method',checkoutId:id,payment:method[1]==='saldo'?'wallet':'gateway'}:{type:'confirm-checkout',checkoutId:id};
            else expired=true;
        }
    }
    const checkoutAction=typeof action==='object'&&(action?.type==='payment-method'||action?.type==='confirm-checkout');
    const browse=expired ? null : navigationQuery(input.selection!==null ? '' : input.text,action);
    const products=expired || browse ? null : catalogQuery(input.selection!==null ? '' : input.text,action);
    const commerce=!expired && commerceInput(input.text,action);
    const unknown=!action&&!browse&&!products&&!expired&&!commerce&&input.selection===null&&input.text.startsWith('#');
    if (!action && !browse && !products && !expired && !commerce && !unknown) return;
    if(!unknown){
        if(input.selection!==null||input.text.startsWith('#'))tokens.clearProductInput(chat,sender,input.scope);
        if(!checkoutAction&&(input.selection!==null||input.text.startsWith('#')))tokens.clearCheckout(chat,sender,input.scope);
    }
    const access=await api('/api/internal/bot/access') as {data?:{allowed?:boolean;store_name?:string}};
    if (access?.data?.allowed!==true) return;
    const storeName=typeof access.data.store_name==='string' ? access.data.store_name : 'Toko Digital';
    if(unknown){
        await sock.sendMessage(chat,{text:unknownCommandText(storeName)},{quoted:message});
        await sendControls(sock,message,chat,sender,tokens,[['menu','Menu utama'],['services','Layanan & produk']],'Pilih menu yang tersedia');
    } else if (expired) {
        await sock.sendMessage(chat,{text:expiredMenuText(storeName)},{quoted:message});
        await sendControls(sock,message,chat,sender,tokens,[['menu','Buka menu baru']],'Buka kembali menu toko');
    } else if(commerce){
        await handleCommerce(sock,message,tokens,api,storeName,action,qris);
    } else if (action==='menu') {
        await sendMenu(sock,message,chat,sender,tokens,storeName);
    } else if (browse) {
        if (browse==='help') {
            await sock.sendMessage(chat,{text:navigationHelp(storeName)},{quoted:message});
            await sendControls(sock,message,chat,sender,tokens,[['services','Lihat layanan'],['menu','Menu utama']],'Pilih menu toko');
            return;
        }
        let data;
        try {
            const response=await api(navigationUrl(browse)) as {data?:unknown};
            data=navigationPage(response.data);
        } catch {
            await sock.sendMessage(chat,{text:catalogErrorText(storeName)},{quoted:message});
            await sendControls(sock,message,chat,sender,tokens,[[browse,'Coba lagi'],['menu','Menu utama']],'Silakan coba kembali');
            return;
        }
        const context={...browse,...(data.service?{service:data.service}:{})};
        await sock.sendMessage(chat,{text:navigationText(data,context)},{quoted:message});
        if (data.view!=='products') await sendChoices(sock,message,chat,sender,tokens,navigationChoices(data),data.view==='services'?'Pilih layanan':'Pilih kategori utama');
        else await sendChoices(sock,message,chat,sender,tokens,productChoices(data as unknown as import('./catalog.js').CatalogPage),'Pilih produk & pesan');
        const controls:(readonly [MenuAction,string])[]=[];
        if (data.pagination.current_page>1) controls.push([{...context,page:data.pagination.current_page-1},'Sebelumnya']);
        if (data.pagination.current_page<data.pagination.last_page) controls.push([{...context,page:data.pagination.current_page+1},'Berikutnya']);
        controls.push([data.view==='products' && context.service ? {type:'browse',view:'categories',service:context.service,page:1} : data.view==='services'?'menu':servicesQuery(),data.view==='products' && context.service?'Kategori utama':data.view==='services'?'Menu utama':'Layanan']);
        const navigation=navigationControls(data,context);
        await sendControls(sock,message,chat,sender,tokens,controls,navigation,navigation);
    } else if (products) {
        let data;
        try {
            const query=new URLSearchParams({page:String(products.page),q:products.search});
            const response=await api('/api/internal/bot/products?'+query) as {data?:unknown};
            data=catalogPage(response.data);
        } catch {
            await sock.sendMessage(chat,{text:catalogErrorText(storeName)},{quoted:message});
            await sendControls(sock,message,chat,sender,tokens,[['products','Coba lagi'],['menu','Menu utama']],'Silakan pilih langkah berikutnya');
            return;
        }
        await sock.sendMessage(chat,{text:catalogText(data,products)},{quoted:message});
        await sendChoices(sock,message,chat,sender,tokens,productChoices(data),'Pilih produk & pesan');
        const controls:(readonly [MenuAction,string])[]=[];
        if (data.pagination.current_page>1) controls.push([{type:'products',page:data.pagination.current_page-1,search:products.search},'Sebelumnya']);
        if (data.pagination.current_page<data.pagination.last_page) controls.push([{type:'products',page:data.pagination.current_page+1,search:products.search},'Berikutnya']);
        controls.push(['menu','Menu utama']);
        const navigation=catalogNavigation(data,products);
        await sendControls(sock,message,chat,sender,tokens,controls,navigation,navigation);
    }
}
