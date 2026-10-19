import AuroraQuickOrder from './AuroraQuickOrder';
import type { MobileProvider } from './mobileProviders';
import type { ReactNode } from 'react';
import { ArrowDown, ArrowRight, CreditCard, LayoutGrid, ReceiptText, ShieldCheck } from 'lucide-react';
import { StorefrontSections, type GamingData } from './NebulaStorefront';
import type { StoreBranding } from './storefrontDesign';
import type { ShopDesign, SectionKey } from './storefrontDesign';

export default function AuroraLanding({catalog, design,providers,preview,available,store,gaming,initialNow,onSelect,onCategory,previewHref}: {catalog:ReactNode; design?:ShopDesign;providers:MobileProvider[];preview:boolean;available:boolean;store:{name:string;slug:string;branding:StoreBranding|null};gaming?:GamingData|null;initialNow:number;onSelect:(id:string)=>void;onCategory:(id:string)=>void;previewHref:(page:'home'|'catalog'|'order'|'profile'|'lookup')=>string}) {
    const quickOrder=design?.sections.find(section=>section.key==='quick_order');
    const savedSections=design?.sections ?? (['banner','benefits','flash_sale','trending','catalog','leaderboard','guide'] as SectionKey[]).map(key=>({key,enabled:true,title:''}));
    const sections=quickOrder?savedSections:[{key:'quick_order' as const,enabled:true,title:''},...savedSections];
    return <div className="sf-shared-sections"><StorefrontSections store={store} sections={sections} initialNow={initialNow} preview={preview} catalog={catalog} gaming={gaming} onSelect={onSelect} onCategory={onCategory} previewHref={previewHref} digital overrides={{
        quick_order:<section className="au-hero" data-reveal>
            <div className="au-hero-copy">
                <span className="au-eyebrow"><span/>PRODUK DIGITAL, LEBIH PRAKTIS</span>
                <h1>Kebutuhan digital,<br/><em>beres dalam genggaman.</em></h1>
                <p>Pilihan produk digital untuk menemani setiap hari. Temukan kebutuhan Anda, pilih produknya, dan lanjutkan dengan mudah.</p>
                <a className="sf-button au-primary" href="#quick-order">Mulai pesanan<ArrowRight size={17}/></a>
                <div className="au-hero-note"><ShieldCheck size={16}/><span>Harga jelas. Pesanan mudah ditelusuri.</span></div>
            </div>
            <AuroraQuickOrder title={quickOrder?.title || undefined} providers={providers} preview={preview} available={available || preview}/>
        </section>,
            benefits:<div className="au-benefits"><div><LayoutGrid size={20}/><span><strong>Banyak pilihan</strong><small>Temukan produk sesuai kebutuhan</small></span></div><div><CreditCard size={20}/><span><strong>Pembayaran praktis</strong><small>Metode yang tersedia di toko</small></span></div><div><ReceiptText size={20}/><span><strong>Pesanan tercatat</strong><small>Cek perkembangannya kapan saja</small></span></div></div>,
            guide:        <section className="au-guide">
            <div><span className="au-eyebrow">SESEDERHANA ITU</span><h2>{design?.sections.find(section=>section.key==='guide')?.title || <>Produk digital,<br/>tanpa langkah yang rumit.</>}</h2><p>Semua informasi pesanan ada dalam satu alur.</p><a href="#catalog">Mulai dari katalog<ArrowDown size={15}/></a></div>
            <ol>{[['Pilih produk','Jelajahi kategori dan pilih produk yang Anda perlukan.'],['Isi data tujuan','Periksa kembali nomor atau data tujuan sebelum melanjutkan.'],['Selesaikan pembayaran','Ikuti petunjuk pembayaran, lalu pantau status pesanan.']].map(([title,description],index)=><li key={title}><span>{String(index+1).padStart(2,'0')}</span><div><h3>{title}</h3><p>{description}</p></div></li>)}</ol>
        </section>
        }}/></div>;
}
