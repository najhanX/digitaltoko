import { useLayoutEffect, useRef } from 'react';

export default function useStorefrontHeaderSize(variable: string) {
    const header = useRef<HTMLElement>(null);

    useLayoutEffect(() => {
        const element = header.current;
        const store = element?.closest<HTMLElement>('.sf-site');
        if (!element || !store) return;
        const previous = store.style.getPropertyValue(variable);
        const measure = () => store.style.setProperty(variable, `${Math.ceil(element.getBoundingClientRect().height)}px`);
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        return () => {
            observer.disconnect();
            if (previous) store.style.setProperty(variable, previous);
            else store.style.removeProperty(variable);
        };
    }, [variable]);

    return header;
}
