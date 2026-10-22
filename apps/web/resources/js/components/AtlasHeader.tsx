import StorefrontMobileMenu from './StorefrontMobileMenu';
import StorefrontSearch from './StorefrontSearch';
import type { ReactNode } from 'react';

export default function AtlasHeader({brand, navigation, account, root='/'}: {
    brand:ReactNode; navigation:ReactNode; account:ReactNode; root?:string;
}) {
    return <header className="sf-atlas-header">
        <div className="sf-container sf-atlas-header-main">{brand}<StorefrontSearch preview={root.startsWith('/preview/')} template="atlas"/>{account}</div>
        <StorefrontMobileMenu><div className="sf-container sf-atlas-header-nav">{navigation}<span>Produk digital pilihan Anda</span></div></StorefrontMobileMenu>
    </header>;
}
