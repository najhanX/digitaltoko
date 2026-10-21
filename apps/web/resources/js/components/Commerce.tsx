import AtlasHeader from './AtlasHeader';
import StorefrontNavigation from './StorefrontNavigation';
import useStorefrontReveal from './useStorefrontReveal';
import StorefrontAccountMenu from './StorefrontAccountMenu';
import AuroraHeader from './AuroraHeader';
import NebulaLayout from './NebulaLayout';
import { CustomerServiceButton } from './NebulaEngagement';
import { designStyle } from './storefrontDesign';
import type { StoreBranding } from './storefrontDesign';
import { Head, Link, usePage } from '@inertiajs/react';
import { useRef, type ReactNode } from 'react';
export const orderStatuses:Record<string,string>={awaiting_payment:'Menunggu pembayaran',paid:'Lunas · antre diproses',processing:'Diproses',pending:'Pending pemasok',success:'Berhasil',failed:'Gagal',expired:'Pembayaran kedaluwarsa',review:'Perlu pemeriksaan'};
export type Shop={name:string;slug:string;template:string;branding:StoreBranding|null;preview?:boolean};
export type Order={can_simulate_payment?:boolean;simulated_payment?:boolean;midtrans_sandbox?:boolean;sandbox_qr_url?:string|null;is_sandbox?:boolean;cancelled?:boolean;game_account?:{game:string;id:string;server:string;nickname:string}|null;id:string;number:string;purpose:string;status:string;payment_status:string;destination:string;voucher?:{code:string;discount:number}|null;subtotal?:number;amount:number;fee:number;unique_code:number;total:number;credit_amount:number;refund_amount:number;gateway:string;message:string|null;product:string;phone?:string;result?:string|null;qr_image?:string|null;url?:string;created_at:string;expires_at:string;paid_at:string|null;refunded_at:string|null;customer_id?:string|null;resolution?:{method:string;note:string;at:string}|null};
export const money=(v:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(v);
export function ShopLayout({store,title,children,showTitle=true}:{store:Shop;title:string;children:ReactNode;showTitle?:boolean}) {
    const page=usePage();
    const content=useRef<HTMLDivElement>(null);
    useStorefrontReveal(content,page.url.split('?')[0]);
    const flash=page.props.flash as {success?:string;error?:string}|undefined;
    const root=store.preview?`/preview/storefront?template=${store.template}&page=home`:'/';
    const account=store.preview?`/preview/storefront?template=${store.template}&page=profile`:'/account';
    const heading=showTitle?<div className="sf-section-title"><h1>{title}</h1></div>:null;
    const feedback=<>{flash?.success && <div className="feedback success" role="status">{flash.success}</div>}{flash?.error && <div className="feedback error" role="alert">{flash.error}</div>}</>;
    const themeClass=store.branding?.design?.colors?' sf-custom-theme':'';
    if(['nebula','promo'].includes(store.template)) return <><Head title={title+' · '+store.name}/><div className={'sf-site sf-nebula ng-site commerce-page'+themeClass} style={designStyle(store.branding)}><NebulaLayout store={store}><div className="commerce-content">{heading}{feedback}{children}</div></NebulaLayout></div></>;
    const brand=<Link href={root} className="sf-brand">{store.branding?.design?.logo && <img className="sf-custom-logo" width={43} height={43} src={store.branding.design.logo} alt=""/>}<strong>{store.name}</strong></Link>;
    const navigation=<StorefrontNavigation template={store.template} preview={store.preview}/>;
    const menu=<StorefrontAccountMenu preview={store.preview} href={account}/>;
    const aurora=['aurora','minimal'].includes(store.template);
    return <><Head title={title+' · '+store.name}/><div ref={content} className={'sf-site sf-'+(aurora?'aurora':'atlas')+' commerce-page'+themeClass} style={designStyle(store.branding)}>
        <CustomerServiceButton settings={store.branding?.design?.customer_service}/>
        {aurora?<AuroraHeader brand={brand} navigation={navigation} account={menu}/>:<AtlasHeader brand={brand} navigation={navigation} account={menu} root={root}/>}
        <main className="sf-container commerce-content">{heading}{feedback}{children}</main>
        <footer className="sf-container sf-footer-bottom"><Link href={root}>{store.name}</Link><span>Powered by <strong>DigitalToko</strong></span></footer>
    </div></>;
}

export async function privatePost<T>(url:string):Promise<T>{const cookie=document.cookie.split('; ').find(item=>item.startsWith('XSRF-TOKEN='));const response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-XSRF-TOKEN':cookie?decodeURIComponent(cookie.slice(11)):''}});if(!response.ok)throw new Error('Permintaan gagal. Periksa hak akses dan coba kembali.');return response.json();}
