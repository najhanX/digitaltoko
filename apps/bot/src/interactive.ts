import { generateWAMessageFromContent, getDevice, isJidGroup, type WAMessage, type WASocket } from '@whiskeysockets/baileys';
import { MenuTokens, type MenuAction } from './menu.js';
import { menuText } from './replies.js';
import { readCustomerMessage } from './messages.js';
import { withOperationTimeout } from './utils/operationTimeout.js';

export async function sendMenu(sock:WASocket,message:WAMessage,chat:string,sender:string,tokens:MenuTokens,storeName:string) {
    // Text stays usable even when WhatsApp does not render native controls.
    await sock.sendMessage(chat,{text:menuText(storeName)},{quoted:message});
    await sendControls(sock,message,chat,sender,tokens,[['services','Layanan & produk'],['balance','Saldo pelanggan'],['status','Status pesanan']],'Pilih menu toko');
}

type NativeButton={name:string;buttonParamsJson:string};
async function sendNative(sock:WASocket,message:WAMessage,chat:string,body:string,buttons:()=>NativeButton[]) {
    try {
        const device=message.key.id?getDevice(message.key.id):'unknown';
        if ((device!=='android'&&device!=='ios')||isJidGroup(chat)||!sock.user?.id) return;
        const outgoing=generateWAMessageFromContent(chat,{viewOnceMessage:{message:{messageContextInfo:{deviceListMetadata:{},deviceListMetadataVersion:2},interactiveMessage:{header:{hasMediaAttachment:false},body:{text:body},footer:{text:'DigitalToko'},nativeFlowMessage:{buttons:buttons(),messageParamsJson:''}}}}},{userJid:sock.user.id});
        if (!outgoing.message||!outgoing.key.id) return;
        await withOperationTimeout(()=>sock.relayMessage(chat,outgoing.message!,{messageId:outgoing.key.id!,additionalNodes:[{tag:'biz',attrs:{},content:[{tag:'interactive',attrs:{type:'native_flow',v:'1'},content:[{tag:'native_flow',attrs:{name:'mixed',v:'9'}}]}]},{tag:'bot',attrs:{biz_bot:'1'}}]}),8000,'Menu tombol');
    } catch { /* Never retry ambiguous native send; the text was already sent. */ }
}
export async function sendControls(sock:WASocket,message:WAMessage,chat:string,sender:string,tokens:MenuTokens,buttons:readonly (readonly [MenuAction,string])[],body:string) {
    await sendNative(sock,message,chat,body,()=>buttons.map(([action,label])=>({name:'quick_reply',buttonParamsJson:JSON.stringify({display_text:label,id:tokens.issue(chat,sender,action,readCustomerMessage(message).scope)})})));
}
export async function sendChoices(sock:WASocket,message:WAMessage,chat:string,sender:string,tokens:MenuTokens,choices:readonly {title:string;description:string;action:MenuAction}[],title:string) {
    if (!choices.length) return;
    await sendNative(sock,message,chat,title,()=>[{name:'single_select',buttonParamsJson:JSON.stringify({title,sections:[{title,rows:choices.map(choice=>({title:choice.title.slice(0,72),description:choice.description.slice(0,100),id:tokens.issue(chat,sender,choice.action,readCustomerMessage(message).scope)}))}]})}]);
}
