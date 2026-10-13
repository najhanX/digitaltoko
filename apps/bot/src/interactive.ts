import { generateWAMessageFromContent, getDevice, isJidGroup, type WAMessage, type WASocket } from '@whiskeysockets/baileys';
import { MenuTokens } from './menu.js';
import { withOperationTimeout } from './utils/operationTimeout.js';

export async function sendMenu(sock:WASocket,message:WAMessage,chat:string,sender:string,tokens:MenuTokens,storeName:string) {
    // Text stays usable even when WhatsApp does not render native controls.
    await sock.sendMessage(chat,{text:`*${storeName}*\n\nPilih menu:\n#produk — Katalog produk\n#status — Status pesanan\n#saldo — Saldo pelanggan\n\nPowered by DigitalToko`},{quoted:message});
    const device=message.key.id?getDevice(message.key.id):'unknown';
    if ((device!=='android'&&device!=='ios')||isJidGroup(chat)||!sock.user?.id) return;
    const buttons=[['products','Produk'],['status','Status pesanan'],['balance','Saldo']] as const;
    const outgoing=generateWAMessageFromContent(chat,{viewOnceMessage:{message:{messageContextInfo:{deviceListMetadata:{},deviceListMetadataVersion:2},interactiveMessage:{header:{hasMediaAttachment:false},body:{text:'Pilih menu toko'},footer:{text:'DigitalToko'},nativeFlowMessage:{buttons:buttons.map(([action,label])=>({name:'quick_reply',buttonParamsJson:JSON.stringify({display_text:label,id:tokens.issue(chat,sender,action)})})),messageParamsJson:''}}}}},{userJid:sock.user.id});
    if (!outgoing.message||!outgoing.key.id) return;
    try {
        await withOperationTimeout(()=>sock.relayMessage(chat,outgoing.message!,{messageId:outgoing.key.id!,additionalNodes:[{tag:'biz',attrs:{},content:[{tag:'interactive',attrs:{type:'native_flow',v:'1'},content:[{tag:'native_flow',attrs:{name:'mixed',v:'9'}}]}]},{tag:'bot',attrs:{biz_bot:'1'}}]}),8000,'Menu tombol');
    } catch { /* Never retry ambiguous native send; the text was already sent. */ }
}
