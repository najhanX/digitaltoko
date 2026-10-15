import { jidNormalizedUser, normalizeMessageContent, type WAMessage } from '@whiskeysockets/baileys';
import type { MenuScope } from './menu.js';

export function readCustomerMessage(message:WAMessage):{text:string;selection:string|null;scope:MenuScope} {
    const content=normalizeMessageContent(message.message);
    const text=(content?.conversation ?? content?.extendedTextMessage?.text ?? '').trim();
    let selection=content?.buttonsResponseMessage?.selectedButtonId
        ?? content?.templateButtonReplyMessage?.selectedId
        ?? content?.listResponseMessage?.singleSelectReply?.selectedRowId ?? null;
    if (!selection) {
        const raw=content?.interactiveResponseMessage?.nativeFlowResponseMessage?.paramsJson;
        if (typeof raw==='string' && raw.length<=4096) {
            try {
                const data=JSON.parse(raw);
                const id=data?.id ?? data?.selected_row_id;
                if (typeof id==='string' && id.length<=256) selection=id;
            } catch { /* Invalid interactive content is never executed as a command. */ }
        }
    }
    // Baileys supplies the alternate PN/LID in the authenticated message key.
    const aliases=[message.key.remoteJid, message.key.remoteJidAlt]
        .filter((id):id is string=>typeof id==='string' && /@(s\.whatsapp\.net|lid)$/.test(id))
        .map(id=>jidNormalizedUser(id));
    return {text,selection,scope:{chatAliases:aliases,senderAliases:aliases}};
}
