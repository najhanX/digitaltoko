import { useLayoutEffect, useRef, useState } from 'react';

export default function RupiahInput({value, onChange, min, max, required = false}: {
    value: string; onChange: (digits: string) => void; min?: number; max?: number; required?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [cursor, setCursor] = useState<{digitsBefore: number} | null>(null);
    const formatted = value === '' ? '' : 'Rp'+value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    useLayoutEffect(() => {
        const element = input.current;
        if (!element) return;
        const amount = Number(value);
        const invalid = value !== '' && ((min !== undefined && amount < min) || (max !== undefined && amount > max));
        element.setCustomValidity(invalid ? `Harga harus antara Rp${(min ?? 0).toLocaleString('id-ID')} dan Rp${(max ?? amount).toLocaleString('id-ID')}.` : '');
        if (!cursor || document.activeElement !== element) return;
        let position = formatted === '' ? 0 : 2;
        let digits = 0;
        for (let index = 2; index < formatted.length && digits < cursor.digitsBefore; index++) {
            if (/\d/.test(formatted[index])) digits++;
            position = index+1;
        }
        element.setSelectionRange(position, position);
    }, [value, formatted, cursor, min, max]);

    return <input ref={input} type="text" inputMode="numeric" autoComplete="off" spellCheck={false}
        value={formatted} placeholder="Rp10.000" required={required}
        onChange={event => {
            const raw = event.currentTarget.value;
            const rawDigits = raw.replace(/\D/g, '');
            const digits = rawDigits.replace(/^0+(?=\d)/, '');
            const removedZeros = rawDigits.length-digits.length;
            const digitsBefore = Math.max(0, raw.slice(0, event.currentTarget.selectionStart ?? raw.length).replace(/\D/g, '').length-removedZeros);
            setCursor({digitsBefore});
            onChange(digits);
        }}/>
}
