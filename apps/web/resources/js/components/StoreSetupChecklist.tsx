import { Link } from '@inertiajs/react';
import { ArrowRight, Bot, Check, Circle, CreditCard, Globe2, Package, PlugZap } from 'lucide-react';

type SetupItem = {key:string;title:string;ready:boolean;required:boolean;description:string;href:string|null;action:string};
export type StoreSetup = {items:SetupItem[];completed:number;total:number;ready:boolean;sandbox:boolean};
const icons = {source:PlugZap,catalog:Package,payment:CreditCard,domain:Globe2,whatsapp:Bot};

export default function StoreSetupChecklist({setup,preparing=false}:{setup:StoreSetup;preparing?:boolean}) {
    return <section id="persiapan-toko" className="card store-setup-checklist" aria-labelledby="store-setup-title">
        <header><div><span className="eyebrow">PERSIAPAN BERJUALAN</span><h2 id="store-setup-title">{setup.ready?'Konfigurasi awal sudah lengkap':'Lengkapi persiapan toko'}</h2><p>{preparing?'Pembayaran paket diterima. Masa sewa belum dimulai. ':''}{setup.sandbox?'Mode pengujian: checklist mengikuti konfigurasi uji toko.':'Atur bagian yang belum siap, lalu uji pesanan setelah aktivasi.'}</p></div><span className="setup-progress" role="status">{setup.completed}/{setup.total} siap</span></header>
        <div className="setup-progress-track" role="progressbar" aria-label="Persiapan berjualan" aria-valuenow={setup.completed} aria-valuemin={0} aria-valuemax={setup.total}><span style={{width:(setup.completed/setup.total*100)+'%'}}/></div>
        <ul>{setup.items.map(item=>{const Icon=icons[item.key as keyof typeof icons]??Package;return <li key={item.key} className={item.ready?'is-ready':''}>
            <span className="setup-step-icon"><Icon size={20}/></span><div className="setup-step-copy"><div><h3>{item.title}</h3><span className={'setup-step-status '+(item.ready?'done':'')}>{item.ready?<Check size={13}/>:<Circle size={12}/>} {item.ready?'Siap':item.required?'Perlu disiapkan':'Opsional'}</span></div><p>{item.description}</p></div>
            {item.href?<Link className="button secondary small" href={item.href}>{item.action}<ArrowRight size={14}/></Link>:<span className="setup-access-note">Minta owner mengatur</span>}
        </li>})}</ul>
        <p className="setup-footnote">Checklist mengikuti data tersimpan; koneksi dan pembayaran tetap perlu diuji. Domain pribadi serta WhatsApp pada paket Web bersifat opsional untuk aktivasi.</p>
    </section>;
}
