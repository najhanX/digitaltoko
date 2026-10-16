import GameArt from './GameArt';
import type { StoreBranding } from './storefrontDesign';
import { Link } from '@inertiajs/react';
import { Fragment, useEffect, useState, type ReactNode } from 'react';
import { ArrowRight, Clock3, Flame, Gift, Headphones, ShieldCheck, Zap } from 'lucide-react';
import { money } from './Commerce';
import StorefrontBanner from './StorefrontBanner';
import NebulaLayout from './NebulaLayout';
import type { DesignBanner, SectionKey } from './storefrontDesign';

export type GamingProduct = {id:string;name:string;category:string;price:number;available:boolean;quantity:number|null;code:string;description:string|null;kind:string;service:string;price_label:string;category_id?:string;category_image?:string|null;compare_price?:number};
export type GamingData = {flash_sale:GamingProduct[];flash_ends_at:string|null;flash_starts_at?:string|null;trending:GamingProduct[]};
type Props = {initialNow:number;store:{slug:string;name:string;branding:StoreBranding|null};preview:boolean;available:boolean;home:boolean;catalog:ReactNode;innerPage:ReactNode;search:ReactNode;gaming?:GamingData|null;onSelect:(id:string)=>void;onCategory:(category:string)=>void;previewHref:(page:'home'|'catalog'|'order'|'profile'|'lookup')=>string};
const sampleGames = ['Mobile Legends','Free Fire','PUBG Mobile','Genshin Impact','Valorant','Honkai: Star Rail'];
const demoProducts:GamingProduct[] = sampleGames.map((name,index)=>({id:'demo-'+index,name:['Weekly Diamond Pass','140 Diamonds','60 UC','60 Genesis Crystals','125 Valorant Points','60 Oneiric Shards'][index],category:name,price:[27900,18500,14900,12900,14900,12900][index],compare_price:[32000,22000,18000,16000,18000,16000][index],available:true,quantity:12+index*3,code:'DEMO-'+index,description:null,kind:'ppob',service:'Topup game',price_label:'Harga'}));

function Countdown({until,initialNow}:{until:string;initialNow:number}) {
    const [remaining,setRemaining]=useState(()=>Math.max(0,Date.parse(until)-initialNow));
    useEffect(()=>{const update=()=>setRemaining(Math.max(0,Date.parse(until)-Date.now()));update();const timer=setInterval(update,1000);return ()=>clearInterval(timer);},[until]);
    const seconds=Math.floor(remaining/1000);
    const parts=[Math.floor(seconds/3600),Math.floor(seconds/60)%60,seconds%60];
    return <span className="ng-countdown" role="timer" aria-label={remaining>0?'Waktu tersisa flash sale':'Flash sale berakhir'}>{parts.map((part,index)=><span key={index}>{String(part).padStart(2,'0')}</span>)}</span>;
}

export default function NebulaStorefront({initialNow,store,preview,available,home,catalog,innerPage,search,gaming,onSelect,onCategory,previewHref}:Props) {
    
    const [demoEnd]=useState(()=>new Date(initialNow+6*60*60*1000).toISOString());
    const sectionHref=(id:string)=>home?'#'+id:previewHref('home')+'#'+id;
    const branding=store.branding ?? {};
    const design=branding.design;
    const banners:DesignBanner[]=design?.banners ?? [{id:'default-1',title:branding.gaming_banner_title || 'Upgrade permainan.\nTaklukkan arena.',subtitle:branding.gaming_banner_subtitle || 'Diamond, UC, dan voucher game favoritmu. Pilih nominal, isi ID, lalu lanjut bermain.',image:branding.gaming_banner_image || null,href:sectionHref('catalog'),button:'Top up sekarang',enabled:true,overlay:true},
        {id:'default-2',title:'Game favoritmu.\nSatu tempat top up.',subtitle:'Temukan pilihan top up untuk petualangan berikutnya. Semua pesanan dapat dipantau melalui akunmu.',image:null,href:sectionHref('catalog'),button:'Jelajahi game',enabled:true,overlay:true}];
    const sections=design?.sections ?? (['banner','benefits','flash_sale','trending','catalog','leaderboard','guide'] as SectionKey[]).map(key=>({key,enabled:true,title:''}));
    const title=(key:SectionKey,fallback:string)=>sections.find(item=>item.key===key)?.title || fallback;
    const flash=preview?demoProducts.slice(0,4):gaming?.flash_sale ?? [];
    const trending=preview?demoProducts:gaming?.trending ?? [];

    const flashEnds=preview?demoEnd:gaming?.flash_ends_at;
    const [now,setNow]=useState(initialNow);
    useEffect(()=>{
        if(!flashEnds && !gaming?.flash_starts_at)return;
        let timer:ReturnType<typeof setTimeout>;
        function update(){
            const current=Date.now();setNow(current);
            const next=[flashEnds,gaming?.flash_starts_at].filter((value):value is string=>!!value).map(Date.parse).filter(time=>time>current).sort((a,b)=>a-b)[0];
            if(next)timer=setTimeout(update,Math.min(next-current+50,2_147_483_647));
        }
        update();return()=>clearTimeout(timer);
    },[flashEnds,gaming?.flash_starts_at]);
    const flashExpired=!!flashEnds && Date.parse(flashEnds)<=now;
    const flashStarts=gaming?.flash_starts_at;
    const flashWaiting=!!flashStarts && Date.parse(flashStarts)>now;
    function selectProduct(item:GamingProduct){if(preview)window.location.assign(previewHref('order'));else onSelect(item.id);}
    function selectCategory(value:string){onCategory(value);}
    const content:Record<SectionKey,ReactNode>={
        banner:<StorefrontBanner banners={banners} settings={design?.carousel}/>,
        benefits:<div className="ng-benefits"><div><Zap/><span><strong>Langsung ke permainan</strong><small>Top up tanpa langkah berlebihan</small></span></div><div><ShieldCheck/><span><strong>Transaksi lebih tenang</strong><small>Harga jelas sebelum pembayaran</small></span></div><div><Headphones/><span><strong>Pesanan tetap terpantau</strong><small>Simpan bukti dan cek status kapan saja</small></span></div></div>,
        flash_sale:<section className="ng-flash" id="flash-sale"><div className="ng-section-heading"><div><div className="ng-title-line"><Zap className="ng-flash-icon" fill="currentColor"/><h2>{title('flash_sale','Flash sale')}</h2><span className="ng-tag">PILIHAN PROMO</span></div><p>Kesempatan kecil untuk upgrade yang lebih besar.</p></div>{flashEnds && !flashExpired && !flashWaiting?<div className="ng-deadline"><small>Berakhir dalam</small><Countdown until={flashEnds} initialNow={now}/></div>:<span className="ng-section-note"><Clock3 size={14}/>{flashWaiting?'Promo dimulai '+new Date(flashStarts!).toLocaleString('id-ID'):flashExpired?'Periode promo berakhir':'Harga sesuai katalog toko'}</span>}</div>
                    {flash.length>0 && !flashExpired && !flashWaiting?<div className="ng-flash-grid">{flash.map((item,index)=><button type="button" className="ng-sale-card" key={item.id} onClick={()=>selectProduct(item)}><div className="ng-sale-top"><GameArt name={item.category} image={item.category_image} index={index} small/><span><small>{item.category}</small><strong>{item.name}</strong></span><Zap size={16}/></div><div className="ng-sale-price">{preview && item.compare_price && <del>{money(item.compare_price)}</del>}<strong>{money(item.price)}</strong><ArrowRight size={17}/></div><div className="ng-stock-line"><span/><small>{item.quantity===null?'Tersedia di pemasok':item.quantity+' stok tersedia'}</small></div></button>)}</div>:<div className="ng-inline-empty"><Gift size={25}/><div><strong>{flashWaiting?'Promo segera dimulai.':flashExpired?'Sampai jumpa di promo berikutnya.':'Promo baru sedang disiapkan.'}</strong><p>Sambil menunggu, jelajahi pilihan game di katalog.</p></div><a href={sectionHref('catalog')}>Lihat game<ArrowRight size={15}/></a></div>}
                </section>,
        trending:<section className="ng-trending" id="trending"><div className="ng-section-heading"><div><div className="ng-title-line"><Flame className="ng-hot-icon"/><h2>{title('trending','Lagi trending')}</h2></div><p>{design?.trending_mode==='manual'?'Kategori rekomendasi pilihan toko.':'Game favorit berdasarkan transaksi berhasil dalam 30 hari terakhir.'}</p></div><a href={sectionHref('catalog')}>Semua game<ArrowRight size={15}/></a></div>
                    {trending.length?<div className="ng-trending-grid">{trending.map((item,index)=><button className="ng-trending-card" type="button" key={item.id} onClick={()=>selectCategory(preview?'Topup game':item.category_id || item.category)}><GameArt name={item.category} image={item.category_image} index={index} small/><span><strong>{item.category}</strong><small>{item.service || 'Top up & voucher game'}</small></span><ArrowRight size={16}/></button>)}</div>:<div className="ng-inline-empty"><Flame size={24}/><p>Game yang paling sering dibeli akan muncul di sini setelah ada transaksi berhasil.</p></div>}
                </section>,
        catalog,
        leaderboard:null,
        guide:<section className="ng-how"><div><span className="ng-eyebrow">SIMPLE STEPS. BIG ADVENTURES.</span><h2>{title('guide','Top up, lalu lanjut mabar.')}</h2></div>{[['01','Pilih game','Temukan game dan nominal favoritmu.'],['02','Isi ID & bayar','Pastikan tujuan benar, lalu pilih pembayaran.'],['03','Pantau pesanan','Cek status dan simpan bukti transaksi.']].map(([number,title,description])=><div className="ng-step" key={number}><span>{number}</span><div><h3>{title}</h3><p>{description}</p></div></div>)}</section>
    };
    return <NebulaLayout store={store} home={home} preview={preview} previewHref={previewHref} search={search}>
        {!available && !preview && <div className="ng-notice">Toko belum menerima pesanan. Silakan kembali setelah toko aktif.</div>}
        {home?sections.filter(item=>item.enabled && item.key!=='leaderboard').map(item=><Fragment key={item.key}>{content[item.key]}</Fragment>):innerPage}
    </NebulaLayout>;
}
