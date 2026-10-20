import { useEffect, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, KeyRound, Mail, MessageCircle, ShieldCheck, UserPlus } from 'lucide-react';
import { ShopLayout, type Shop } from '../../components/Commerce';
import { FormErrors } from '../../components/CatalogForms';

type Props={store:Shop;initialMode?:'login'|'register';challengePending:boolean;challengePhone?:string|null;challengeExpiresAt?:number|null;challengeChannel?:'wa'|'email';otpReady?:boolean;emailReady?:boolean;canRegister?:boolean};
export default function CustomerLogin({store,initialMode='login',challengePending,challengePhone,challengeExpiresAt,challengeChannel='wa',otpReady=false,emailReady=false,canRegister=true}:Props){
    const registering=initialMode==='register';
    const [editing,setEditing]=useState(false),[now,setNow]=useState<number|null>(null);
    const request=useForm({phone:'',name:'',email:'',password:'',password_confirmation:'',intent:initialMode,channel:(!otpReady&&emailReady?'email':'wa') as 'wa'|'email'});
    const verification=useForm({otp:'',intent:initialMode});
    const login=useForm({identifier:'',password:''});
    const emailLogin=login.data.identifier.includes('@');
    useEffect(()=>{request.setData('intent',initialMode);verification.setData('intent',initialMode);setEditing(false);login.reset();},[initialMode]);
    useEffect(()=>{if(!challengePending)return;const update=()=>setNow(Math.floor(Date.now()/1000));update();const timer=setInterval(update,1000);return()=>clearInterval(timer);},[challengePending,challengeExpiresAt]);
    const verifying=challengePending&&!editing;
    const retrySeconds=challengeExpiresAt&&now!==null?Math.max(0,challengeExpiresAt-240-now):challengePending?60:0;
    const expired=!!challengeExpiresAt&&now!==null&&now>=challengeExpiresAt;
    const busy=request.processing||verification.processing||login.processing;
    function sent(){setEditing(false);verification.reset('otp');verification.clearErrors();}
    function emailOtp(){login.transform(data=>({email:data.identifier.trim().toLowerCase(),intent:'login',channel:'email'}));login.post('/account/otp',{onSuccess:sent,onFinish:()=>login.reset('password')});}
    return <ShopLayout store={store} title={registering?'Daftar pelanggan':'Masuk ke akun pelanggan'} showTitle={false}>
        <section className="sf-form-card commerce-auth customer-auth-card">
            <header className="customer-auth-heading"><span className="customer-auth-icon">{verifying?<ShieldCheck size={23}/>:registering?<UserPlus size={23}/>:<KeyRound size={23}/>}</span><div><h1>{verifying?'Verifikasi '+(challengeChannel==='email'?'email':'WhatsApp'):registering?'Buat akun di '+store.name:'Selamat datang kembali'}</h1><p>{verifying?'Masukkan kode yang dikirim ke '+(challengePhone??'kontak Anda')+'.':registering?'Lengkapi data dan pilih tempat menerima kode verifikasi.':'Masuk untuk memakai saldo toko dan memantau riwayat pembelian.'}</p></div></header>
            {verifying?<form className="form-stack" onSubmit={event=>{event.preventDefault();verification.post('/account/verify',{onFinish:()=>verification.reset('otp')});}}>
                <FormErrors errors={verification.errors}/><label>Kode OTP<input autoFocus required type="text" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} value={verification.data.otp} onChange={event=>verification.setData('otp',event.target.value.replace(/\D/g,''))} placeholder="6 digit kode OTP"/></label><small>Kode berlaku 5 menit. Jangan berikan kode OTP kepada siapa pun.</small>
                {expired&&<p className="feedback warning" role="status">Kode sudah kedaluwarsa. Minta kode baru untuk melanjutkan.</p>}
                <button className="button" disabled={busy||expired}>{verification.processing?'Memverifikasi…':registering?'Verifikasi & buat akun':'Verifikasi & masuk'}</button><button className="button secondary" type="button" disabled={busy} onClick={()=>{setEditing(true);verification.clearErrors();}}>Ganti kontak / kirim ulang OTP</button>
            </form>:registering?<form className="form-stack" onSubmit={event=>{event.preventDefault();request.post('/account/otp',{onSuccess:sent,onFinish:()=>request.reset('password','password_confirmation')});}}>
                <FormErrors errors={request.errors}/><label>Nama lengkap<input required maxLength={100} autoComplete="name" value={request.data.name} onChange={event=>request.setData('name',event.target.value)} placeholder="Nama Anda"/></label>
                <label>Email<input required type="email" autoComplete="email" maxLength={255} value={request.data.email} onChange={event=>request.setData('email',event.target.value)} placeholder="nama@email.com"/></label>
                <label>Nomor WhatsApp<input required type="tel" autoComplete="tel" maxLength={30} value={request.data.phone} onChange={event=>request.setData('phone',event.target.value)} placeholder="08xxxxxxxxxx atau 628xxxxxxxxxx"/></label>
                <div className="ng-contact-fields"><label>Password<input required type="password" autoComplete="new-password" minLength={12} maxLength={128} value={request.data.password} onChange={event=>request.setData('password',event.target.value)} placeholder="Minimal 12 karakter"/></label><label>Konfirmasi password<input required type="password" autoComplete="new-password" minLength={12} maxLength={128} value={request.data.password_confirmation} onChange={event=>request.setData('password_confirmation',event.target.value)} placeholder="Ulangi password"/></label></div>
                <label>Kirim kode verifikasi melalui<select value={request.data.channel} onChange={event=>request.setData('channel',event.target.value as 'wa'|'email')}><option value="wa" disabled={!otpReady}>WhatsApp toko{!otpReady?' · belum terhubung':''}</option><option value="email" disabled={!emailReady}>Email{!emailReady?' · belum tersedia':''}</option></select></label>
                {!otpReady&&!emailReady&&<p className="feedback warning" role="status">Pengiriman kode verifikasi belum tersedia. Hubungi pengelola toko.</p>}
                {!canRegister&&<p className="feedback warning" role="status">Toko belum menerima pendaftaran pelanggan baru.</p>}
                <button className="button" disabled={busy||!canRegister||retrySeconds>0||!(request.data.channel==='wa'?otpReady:emailReady)}>{request.data.channel==='wa'?<MessageCircle size={17}/>:<Mail size={17}/>} {request.processing?'Mengirim OTP…':retrySeconds>0?'Kirim ulang dalam '+retrySeconds+' detik':'Kirim OTP & daftar'}</button>
                {challengePending&&<button type="button" className="text-link" onClick={()=>setEditing(false)}>Saya sudah punya kode OTP</button>}
            </form>:<form className="form-stack" onSubmit={event=>{event.preventDefault();if(emailLogin){login.transform(data=>({email:data.identifier.trim().toLowerCase(),password:data.password}));login.post('/account/password',{onFinish:()=>login.reset('password')});}else{login.transform(data=>({phone:data.identifier.trim(),intent:'login',channel:'wa'}));login.post('/account/otp',{onSuccess:sent});}}}>
                <FormErrors errors={login.errors}/><label>Email atau nomor WhatsApp<input required type={emailLogin?'email':'text'} autoComplete="username" maxLength={255} disabled={busy} value={login.data.identifier} onChange={event=>{const value=event.target.value;login.setData({identifier:value,password:value.includes('@')===emailLogin?login.data.password:''});login.clearErrors();}} placeholder="nama@email.com atau 08xxxxxxxxxx"/></label>
                {emailLogin&&<label>Password<input required type="password" autoComplete="current-password" maxLength={128} disabled={busy} value={login.data.password} onChange={event=>login.setData('password',event.target.value)}/></label>}
                <small>{emailLogin?'Gunakan email terverifikasi dan password, atau minta kode OTP email.':'Masukkan nomor WhatsApp terverifikasi untuk menerima OTP dari toko. Anda juga dapat masuk menggunakan email.'}</small>
                {emailLogin?<><button className="button" disabled={busy}>{login.processing?'Memeriksa…':'Masuk'}</button><button type="button" className="button secondary" disabled={busy||!emailReady||retrySeconds>0} onClick={emailOtp}><Mail size={17}/>{retrySeconds>0?'Kirim ulang dalam '+retrySeconds+' detik':'Kirim OTP email'}</button>{!emailReady&&<small>Pengiriman email toko belum tersedia.</small>}</>:<><button className="button" disabled={busy||!otpReady||retrySeconds>0}><MessageCircle size={17}/>{login.processing?'Mengirim OTP…':retrySeconds>0?'Kirim ulang dalam '+retrySeconds+' detik':'Kirim OTP WhatsApp'}</button>{!otpReady&&<p className="feedback warning" role="status">WhatsApp toko belum terhubung. Masukkan email untuk menggunakan password atau OTP email.</p>}</>}
                {challengePending&&<button type="button" className="text-link" onClick={()=>setEditing(false)}>Saya sudah punya kode OTP</button>}
            </form>}
            <footer className="customer-auth-footer"><p>{registering?'Sudah punya akun?':'Belum punya akun?'}</p><Link href={registering?'/account/login':'/account/register'}>{registering?'Masuk ke akun':'Daftar pelanggan'}</Link></footer><p className="customer-auth-scope"><ShieldCheck size={15}/>Akun dan saldo hanya berlaku di {store.name}.</p><Link className="text-link customer-auth-back" href="/"><ArrowLeft size={15}/>Kembali ke toko</Link>
        </section>
    </ShopLayout>;
}
