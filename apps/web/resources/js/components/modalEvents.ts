type ModalCancelEvent = {
    target: EventTarget | null;
    currentTarget: EventTarget | null;
    preventDefault: () => void;
    stopPropagation: () => void;
};

/** File inputs also emit bubbling cancel events when their picker is dismissed. */
export function handleModalCancel(event: ModalCancelEvent, busy: boolean, onClose: () => void): void {
    if (event.target !== event.currentTarget) return;
    event.preventDefault();
    event.stopPropagation();
    if (!busy) onClose();
}
