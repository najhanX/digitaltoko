import type { ReactNode } from 'react';
import Modal from './Modal';
export default function ConfirmDialog({open,title,children,confirmLabel,onConfirm,onCancel,busy = false,danger = false}:{open:boolean;title:string;children:ReactNode;confirmLabel:string;onConfirm:()=>void;onCancel:()=>void;busy?:boolean;danger?:boolean}) {
    return <Modal open={open} title={title} onClose={onCancel} busy={busy} footer={<><button data-modal-focus type="button" className="button secondary" onClick={onCancel} disabled={busy}>Kembali</button><button type="button" className={'button '+(danger?'danger':'')} onClick={onConfirm} disabled={busy}>{busy?'Memproses…':confirmLabel}</button></>}><div className="dialog-body">{children}</div></Modal>;
}
