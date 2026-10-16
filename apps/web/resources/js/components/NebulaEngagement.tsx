import { useEffect, useState } from 'react';
import { ArrowRight, MessageCircle } from 'lucide-react';
import Modal from './Modal';
import { customerServiceUrl, validBannerUrl, type CustomerService, type WelcomeAd } from './storefrontDesign';

export default function NebulaEngagement({slug,home,ad,service}:{slug:string;home:boolean;ad?:WelcomeAd;service?:CustomerService}) {
    const [open,setOpen]=useState(false);
    const signature=JSON.stringify(ad ?? null);
    useEffect(()=>{
        setOpen(false);
        if(!home || !ad?.enabled)return;
        const key='digitaltoko:welcome:'+slug;
        try{if(sessionStorage.getItem(key)===signature)return;}catch{/* Storage can be unavailable in private browsers. */}
        const timer=setTimeout(()=>{
            setOpen(true);
            try{sessionStorage.setItem(key,signature);}catch{/* The popup remains dismissible without storage. */}
        },650);
        return()=>clearTimeout(timer);
    },[slug,home,signature]);
    const href=ad?.href && validBannerUrl(ad.href)?ad.href:null;
    return <>
        <CustomerServiceButton settings={service}/>
        <Modal open={!!ad?.enabled && open} title={ad?.title || 'Informasi toko'} onClose={()=>setOpen(false)} footer={<><button type="button" className="button secondary" onClick={()=>setOpen(false)}>Tutup</button>{href && <a className="button" href={href} onClick={()=>setOpen(false)}>{ad?.button || 'Lihat promo'}<ArrowRight size={15}/></a>}</>}><div className="ng-welcome-ad">{ad?.image && <img src={ad.image} width={800} height={450} alt={ad.title}/>}{ad?.message && <p>{ad.message}</p>}</div></Modal>
    </>;
}

export function CustomerServiceButton({settings}:{settings?:CustomerService}) {
    const href=settings?customerServiceUrl(settings):null;
    return href?<a className="ng-chat-float" href={href} target="_blank" rel="noopener noreferrer" aria-label={(settings?.label || 'Chat CS')+' melalui WhatsApp (tab baru)'}><MessageCircle size={22}/><span>{settings?.label || 'Chat CS'}</span></a>:null;
}
