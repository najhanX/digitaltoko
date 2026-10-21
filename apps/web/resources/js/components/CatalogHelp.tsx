import { ChevronDown, Info } from 'lucide-react';
import type { ReactNode } from 'react';

export default function CatalogHelp({ title, children }: { title: string; children: ReactNode }) {
    return <details className="catalog-help">
        <summary><Info size={16} aria-hidden="true"/><span>{title}</span><ChevronDown size={16} aria-hidden="true"/></summary>
        <div>{children}</div>
    </details>;
}
