import { useEffect, useState } from 'react';
import { ArrowRight, ChevronLeft, ChevronRight, Pause, Play, ShieldCheck } from 'lucide-react';
import { bannerHref, type CarouselSettings, type DesignBanner } from './storefrontDesign';

export default function StorefrontBanner({banners,settings}:{banners:DesignBanner[];settings?:CarouselSettings}) {
    const slides=banners.filter(banner=>banner.enabled);
    const [index,setIndex]=useState(0);
    const [hovered,setHovered]=useState(false),[focused,setFocused]=useState(false),[paused,setPaused]=useState(false),[hidden,setHidden]=useState(false),[reducedMotion,setReducedMotion]=useState(false);
    const autoplay=settings?.autoplay ?? true,interval=Math.max(3,Math.min(30,settings?.interval ?? 5));
    const signature=slides.map(item=>item.id).join('|');
    useEffect(()=>{
        const motion=window.matchMedia('(prefers-reduced-motion: reduce)');
        const updateMotion=()=>setReducedMotion(motion.matches),updateVisibility=()=>setHidden(document.hidden);
        updateMotion();updateVisibility();motion.addEventListener('change',updateMotion);document.addEventListener('visibilitychange',updateVisibility);
        return()=>{motion.removeEventListener('change',updateMotion);document.removeEventListener('visibilitychange',updateVisibility);};
    },[]);
    const rotating=autoplay && slides.length>1 && !hovered && !focused && !paused && !hidden && !reducedMotion;
    useEffect(()=>{
        if(!rotating)return;
        const timer=setTimeout(()=>setIndex(value=>(Math.min(value,slides.length-1)+1)%slides.length),interval*1000);
        return()=>clearTimeout(timer);
    },[rotating,interval,signature,index,slides.length]);
    const current=Math.min(index,Math.max(0,slides.length-1)),banner=slides[current];
    if(!banner)return null;
    const href=bannerHref(banner);
    const move=(delta:number)=>setIndex((current+delta+slides.length)%slides.length);
    return <section className={'ng-hero'+(!banner.overlay?' ng-hero-image-only':'')} aria-roledescription="carousel" aria-label="Banner toko" onMouseEnter={()=>setHovered(true)} onMouseLeave={()=>setHovered(false)} onFocusCapture={()=>setFocused(true)} onBlurCapture={event=>{if(!event.currentTarget.contains(event.relatedTarget))setFocused(false);}}>
        <div key={banner.id} className="ng-slide-content"><img width={1440} height={480} className="ng-hero-image" src={banner.image || '/storefront/nebula-gaming-hero.webp'} alt={banner.overlay?'':banner.title} fetchPriority="high"/>
        {banner.overlay?<><div className="ng-hero-shade"/><div className="ng-hero-copy"><span className="ng-eyebrow"><span/>YOUR NEXT LEVEL STARTS HERE</span><h1>{banner.title.split('\n').map((line,i)=><span key={i}>{line}</span>)}</h1>{banner.subtitle && <p>{banner.subtitle}</p>}{href && <a className="ng-button" href={href}>{banner.button || 'Lihat selengkapnya'}<ArrowRight size={17}/></a>}<div className="ng-hero-caption"><ShieldCheck size={13}/> Pilihan lengkap <span>•</span> Harga transparan</div></div></>:href && <a className="ng-banner-overlay-link" href={href} aria-label={banner.title}/>}</div>
        <div className="ng-hero-bottom"><span>{banner.overlay?'PILIHAN TOKO UNTUK ANDA':''}</span>{slides.length>1 && <><div className="ng-slide-dots">{slides.map((item,i)=><button key={item.id} type="button" aria-label={'Tampilkan banner '+(i+1)} aria-pressed={current===i} onClick={()=>setIndex(i)}/>)}</div><div className="ng-slide-arrows">{autoplay && !reducedMotion && <button type="button" aria-label={paused?'Putar banner otomatis':'Jeda banner otomatis'} onClick={()=>setPaused(value=>!value)}>{paused?<Play size={14}/>:<Pause size={14}/>}</button>}<button type="button" aria-label="Banner sebelumnya" onClick={()=>move(-1)}><ChevronLeft size={18}/></button><button type="button" aria-label="Banner berikutnya" onClick={()=>move(1)}><ChevronRight size={18}/></button></div></>}</div>
    </section>;
}
