import { randomUUID } from 'node:crypto';
import type { ChildProcess } from 'node:child_process';
import type { WASocket } from '@whiskeysockets/baileys';

export type DirectMessage = { type: 'store-message'; id: string; phone: string; message: string };
export function validDirectMessage(value: unknown): value is DirectMessage {
    const input = value as DirectMessage | undefined;
    return !!input && input.type === 'store-message' && /^[a-f0-9-]{36}$/i.test(input.id)
        && /^62[1-9][0-9]{7,12}$/.test(input.phone) && typeof input.message === 'string'
        && input.message.length > 0 && input.message.length <= 6000;
}

export function sendDirectMessage(child: ChildProcess, phone: string, message: string): Promise<void> {
    const input: DirectMessage = { type: 'store-message', id: randomUUID(), phone, message };
    if (!validDirectMessage(input) || !child.connected) return Promise.reject(new Error('Message unavailable'));
    return new Promise((resolve, reject) => {
        const done = (success: boolean) => {
            clearTimeout(timer);
            child.removeListener('message', receive);
            child.removeListener('exit', stopped);
            child.removeListener('disconnect', stopped);
            if (success) resolve(); else reject(new Error('Message not confirmed'));
        };
        const receive = (value: unknown) => {
            const result = value as { type?: string; id?: string; success?: boolean };
            if (result?.type === 'store-message-result' && result.id === input.id) done(result.success === true);
        };
        const stopped = () => done(false);
        const timer = setTimeout(stopped, 15_000);
        child.on('message', receive);
        child.once('exit', stopped);
        child.once('disconnect', stopped);
        child.send(input, error => { if (error) done(false); });
    });
}

export async function deliverDirectMessage(input: unknown, socket: WASocket | undefined, canSend: boolean): Promise<boolean> {
    // Laravel already authorized this IPC request. Calling it again would deadlock artisan serve.
    if (!validDirectMessage(input) || !socket || canSend !== true) return false;
    try {
        await socket.sendMessage(input.phone + '@s.whatsapp.net', { text: input.message });
        return true;
    } catch { return false; }
}
