import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowRight, Bot, Check, CheckCircle2, Globe2, Layers3, LoaderCircle, ShieldCheck, Store, XCircle } from 'lucide-react';
import Brand from '../../components/Brand';
import PlatformHeader from '../../components/PlatformHeader';
import { packages } from '../../components/packageCatalog';

type Offer = {id:number;name:string;category:string;price:number;duration_days:number};
type Availability = {slug:string;state:'checking'|'available'|'unavailable'|'error';message:string};
const money = (value:number) => new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);
const icons = {bot:Bot,web:Globe2,full:Layers3};

export default function Pricing({domain,offers}:{domain:string;offers:Offer[]}) {
    const [name,setName] = useState('');
    const [slug,setSlug] = useState('');
    const [offerId,setOfferId] = useState<number|null>(null);
    const [availability,setAvailability] = useState<Availability|null>(null);
    const [retry,setRetry] = useState(0);
    const normalizedSlug = slug.trim().toLowerCase();
    const categoryOffers = offers.filter(offer=>offer.category==='full');
    const selected = categoryOffers.find(offer=>offer.id===offerId)??categoryOffers[0];
    const selectedPackage = packages[0];
    const currentAvailability = availability?.slug===normalizedSlug?availability:null;

    useEffect(()=>{
        if(!normalizedSlug){setAvailability(null);return;}
        if(normalizedSlug.length<3 || normalizedSlug.length>40 || !/^[a-z0-9]+(-[a-z0-9]+)*$/.test(normalizedSlug)){
            setAvailability({slug:normalizedSlug,state:'unavailable',message:'Gunakan 3–40 huruf kecil/angka, dengan tanda hubung di antara kata.'});return;
        }
        const controller = new AbortController();
        setAvailability({slug:normalizedSlug,state:'checking',message:'Memeriksa ketersediaan…'});
        const timer = setTimeout(async()=>{
            try {
                const response = await fetch('/paket/subdomain?'+new URLSearchParams({slug:normalizedSlug}),{headers:{Accept:'application/json'},signal:controller.signal,cache:'no-store'});
                const result = await response.json();
                if(controller.signal.aborted)return;
                if(response.ok && result.available===true && result.slug===normalizedSlug){
                    setAvailability({slug:normalizedSlug,state:'available',message:'Subdomain tersedia. Anda bisa memakai alamat ini.'});
                } else if(response.status===422){
                    setAvailability({slug:normalizedSlug,state:'unavailable',message:result.errors?.slug?.[0]??'Subdomain tidak dapat digunakan.'});
                } else {
                    setAvailability({slug:normalizedSlug,state:'error',message:response.status===429?'Terlalu banyak pengecekan. Tunggu sebentar lalu coba lagi.':'Belum dapat memeriksa subdomain. Coba lagi.'});
                }
            } catch {
                if(!controller.signal.aborted)setAvailability({slug:normalizedSlug,state:'error',message:'Pengecekan belum berhasil. Coba periksa kembali.'});
            }
        },500);
        return()=>{clearTimeout(timer);controller.abort();};
    },[normalizedSlug,retry]);

    const StatusIcon = currentAvailability?.state==='checking'?LoaderCircle:currentAvailability?.state==='available'?CheckCircle2:XCircle;
    return <div className="pricing-site"><Head title="Paket & harga"><meta name="description" content="Web Topup dan Bot WhatsApp dalam satu paket. Cek subdomain dan pilih durasi sewa toko Anda."/></Head>
        <PlatformHeader/>
        <main className="wrap pricing-main"><section className="pricing-intro"><span className="eyebrow">PAKET DIGITALTOKO</span><h1>Satu paket lengkap.<br/><span>Pilih durasi Anda.</span></h1><p>Web Topup, Bot WhatsApp, dan seluruh koneksi pemasok sudah termasuk. Cukup pilih durasi sewa.</p></section>
            <section className="pricing-bundle" aria-label="Paket lengkap DigitalToko"><span className="icon-box"><Layers3 size={28}/></span><div><h2>{selectedPackage.name}</h2><p>Website toko, satu bot WhatsApp, dan panel pengelolaan dalam satu langganan.</p></div><span className="store-status active">Semua fitur inti</span></section>
            <div className="pricing-included"><ShieldCheck size={21}/><strong>Sumber produk yang didukung</strong><div>{['Digiflazz','API Hanshop','Supplier OtomaX','Stok premium'].map(source=><span key={source}><Check size={15}/>{source}</span>)}</div></div>
            <section className="pricing-simulator" aria-labelledby="simulator-title"><div className="pricing-form-card"><div className="pricing-section-title"><span className="icon-box"><Store size={22}/></span><div><h2 id="simulator-title">Simulasikan toko Anda</h2><p>Coba nama, alamat, dan durasi sebelum mendaftar.</p></div></div>
                <div className="pricing-fields"><label htmlFor="pricing-name">Nama toko<input id="pricing-name" value={name} onChange={event=>setName(event.target.value)} maxLength={100} placeholder="Contoh: Nusa Digital" autoComplete="organization"/><small>Nama boleh sama dengan toko lain. Alamat subdomain harus unik.</small></label>
                <div><label htmlFor="pricing-slug">Subdomain toko</label><div className="pricing-domain-input"><input id="pricing-slug" value={slug} onChange={event=>setSlug(event.target.value.toLowerCase())} maxLength={40} placeholder="nusa-digital" autoCapitalize="none" autoCorrect="off" spellCheck={false} aria-describedby="pricing-domain-status"/><span>.{domain}</span></div><div id="pricing-domain-status" className={'pricing-domain-status '+(currentAvailability?.state??'')} role="status" aria-live="polite">{currentAvailability?<><StatusIcon size={17} className={currentAvailability.state==='checking'?'spin':''}/><span>{currentAvailability.message}</span></>:<span>Ketik subdomain untuk memeriksa ketersediaannya.</span>}</div>{currentAvailability?.state==='error'&&<button type="button" className="button secondary small" onClick={()=>setRetry(value=>value+1)}>Periksa kembali</button>}</div>
                <label htmlFor="pricing-duration">Durasi sewa<select id="pricing-duration" disabled={!selected} value={selected?.id??''} onChange={event=>setOfferId(Number(event.target.value))}>{categoryOffers.length?categoryOffers.map(offer=><option key={offer.id} value={offer.id}>{offer.duration_days} hari · {money(offer.price)}{offer.name!==`${selectedPackage?.name} · ${offer.duration_days} hari`?` · ${offer.name}`:""}</option>):<option value="">Belum ada durasi tersedia</option>}</select></label></div>
                <p className="pricing-simulation-note">Simulasi ini belum membuat toko atau mengamankan subdomain. Ketersediaan diperiksa kembali saat pendaftaran.</p>
            </div><aside className="pricing-summary" aria-label="Ringkasan simulasi"><span className="eyebrow">TOKO PILIHAN ANDA</span><h2>{name.trim()||'Nama toko Anda'}</h2><p className="pricing-preview-domain">{normalizedSlug||'toko-anda'}.{domain}</p><div className="pricing-summary-details"><div><span>Paket</span><strong>{selectedPackage?.name}</strong></div><div><span>Durasi</span><strong>{selected?`${selected.duration_days} hari`:'Belum tersedia'}</strong></div></div><div className="pricing-total" aria-live="polite"><span>Total sewa</span><strong>{selected?money(selected.price):'—'}</strong><small>{selected?'Untuk satu toko selama periode yang dipilih.':'Durasi belum tersedia. Periksa kembali nanti.'}</small></div><p className="pricing-summary-note">Belum termasuk diskon voucher dan kode unik pembayaran jika berlaku. Total final tertera pada invoice.</p><Link className="button" href="/login">Masuk & buat toko<ArrowRight size={17}/></Link><small className="pricing-start-note">Lengkapi verifikasi owner sebelum membeli. Masa sewa dimulai setelah toko siap dan aktivasi dikonfirmasi.</small></aside></section>
            <section className="pricing-help"><div><h2>Masih ingin memahami alurnya?</h2><p>Lihat panduan mulai berjualan, koneksi pemasok, dan aktivasi toko.</p></div><Link href="/panduan/mulai" className="button secondary">Buka panduan<ArrowRight size={17}/></Link></section>
        </main><footer className="site-footer wrap"><Brand/><span>Web toko dan bot WhatsApp untuk usaha digital.</span></footer>
    </div>;
}
