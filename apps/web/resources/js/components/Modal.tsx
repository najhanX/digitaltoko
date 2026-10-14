import { useEffect, useId, useRef, type ReactNode } from 'react';
import { X } from 'lucide-react';

export default function Modal({open, title, description, children, footer, onClose, busy = false, wide = false}: {
    open: boolean; title: string; description?: string; children: ReactNode; footer?: ReactNode;
    onClose: () => void; busy?: boolean; wide?: boolean;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const descriptionId = useId();
    useEffect(() => {
        const element = dialog.current;
        if (!element || !open) return;
        const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const previousOverflow = document.body.style.overflow;
        element.showModal();
        document.body.style.overflow = 'hidden';
        const initialFocus = element.querySelector<HTMLElement>('[data-modal-focus]')
            ?? element.querySelector<HTMLElement>('input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]), textarea, select');
        initialFocus?.focus({preventScroll:true});
        return () => {
            element.close();
            document.body.style.overflow = previousOverflow;
            if (previousFocus?.isConnected) previousFocus.focus({preventScroll:true});
        };
    }, [open]);
    return <dialog ref={dialog} className={'panel-dialog interactive-modal '+(wide?'modal-wide':'')}
        aria-labelledby={titleId} aria-describedby={description ? descriptionId : undefined} aria-busy={busy}
        onCancel={event => {event.preventDefault();if(!busy)onClose();}}
        onClick={event => {
            if (busy || event.target !== event.currentTarget) return;
            const bounds = event.currentTarget.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) onClose();
        }}>
        <header className="modal-header"><div><h2 id={titleId}>{title}</h2>{description && <p id={descriptionId}>{description}</p>}</div>
            <button type="button" className="icon-button" aria-label="Tutup modal" onClick={onClose} disabled={busy}><X size={20}/></button>
        </header>
        <div className="modal-body onboarding-card">{children}</div>
        {footer && <footer className="modal-footer">{footer}</footer>}
    </dialog>;
}
