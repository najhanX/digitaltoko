import { useEffect, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, KeyRound, MessageCircle, ShieldCheck, UserPlus } from 'lucide-react';
import { ShopLayout, type Shop } from '../../components/Commerce';
import { FormErrors } from '../../components/CatalogForms';

type Props = {store:Shop;initialMode?:'login'|'register';challengePending:boolean;challengePhone?:string|null;challengeExpiresAt?:number|null;otpReady?:boolean;canRegister?:boolean};
export default function CustomerLogin({store,initialMode='login',challengePending,challengePhone,challengeExpiresAt,otpReady=true,canRegister=true}:Props) {
    const registering=initialMode==='register';
    const [editingPhone,setEditingPhone]=useState(false);
    const [now,setNow]=useState<number|null>(null);
    const request=useForm({phone:'',name:'',email:'',password:'',password_confirmation:'',intent:initialMode});
    const verification=useForm({otp:'',intent:initialMode});
    const login=useForm({identifier:'',password:''});
    const emailLogin=login.data.identifier.includes('@');
    useEffect(()=>{request.setData('intent',initialMode);verification.setData('intent',initialMode);setEditingPhone(false);login.reset();},[initialMode]);
    useEffect(()=>{if(!challengePending)return;const update=()=>setNow(Math.floor(Date.now()/1000));update();const timer=setInterval(update,1000);return()=>clearInterval(timer);},[challengePending,challengeExpiresAt]);
    const verifying=challengePending && !editingPhone && (registering || !emailLogin);
    const retrySeconds=challengeExpiresAt && now!==null?Math.max(0,challengeExpiresAt-240-now):challengePending?60:0;
    const expired=!!challengeExpiresAt && now!==null && now>=challengeExpiresAt;
    const busy=request.processing || verification.processing || login.processing;
    const title=registering?'Daftar pelanggan':'Masuk ke akun pelanggan';
    return <ShopLayout store={store} title={title} showTitle={false}>
        <section className="sf-form-card commerce-auth customer-auth-card">
            <header className="customer-auth-heading"><span className="customer-auth-icon">{verifying?<ShieldCheck size={23}/>:registering?<UserPlus size={23}/>:<KeyRound size={23}/>}</span><div><h1>{verifying?'Verifikasi WhatsApp':registering?'Buat akun di '+store.name:'Selamat datang kembali'}</h1><p>{verifying?'Masukkan kode yang dikirim ke '+(challengePhone ?? 'nomor WhatsApp Anda')+'.':registering?'Lengkapi nama, email, dan nomor WhatsApp aktif. Verifikasi nomor untuk membuat akun.':'Masuk untuk memakai saldo toko dan memantau riwayat pembelian.'}</p></div></header>
            {verifying?<form className="form-stack" onSubmit={event=>{event.preventDefault();verification.post('/account/verify',{onFinish:()=>verification.reset('otp')});}}>
                <FormErrors errors={verification.errors}/>
                <label>Kode OTP<input autoFocus required type="text" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} value={verification.data.otp} onChange={event=>verification.setData('otp',event.target.value.replace(/\D/g,''))} placeholder="6 digit kode OTP"/></label>
                <small>Kode berlaku 5 menit. Jangan berikan kode OTP kepada siapa pun.</small>
                {expired && <p className="feedback warning" role="status">Kode sudah kedaluwarsa. Minta kode baru untuk melanjutkan.</p>}
                <button className="button" disabled={busy || expired}>{verification.processing?'Memverifikasi…':registering?'Verifikasi & buat akun':'Verifikasi & masuk'}</button>
                <button className="button secondary" type="button" disabled={busy} onClick={()=>{setEditingPhone(true);verification.clearErrors();}}>Ganti nomor / kirim ulang OTP</button>
            </form>:registering?<form className="form-stack" onSubmit={event=>{event.preventDefault();request.post('/account/otp',{onSuccess:()=>{setEditingPhone(false);verification.reset('otp');verification.clearErrors();},onFinish:()=>request.reset('password','password_confirmation')});}}>
                <FormErrors errors={request.errors}/>
                {registering && <label>Nama lengkap<input required maxLength={100} autoComplete="name" value={request.data.name} onChange={event=>request.setData('name',event.target.value)} placeholder="Nama Anda"/></label>}
                {registering && <label>Email<input required type="email" autoComplete="email" maxLength={255} value={request.data.email} onChange={event=>request.setData('email',event.target.value)} placeholder="nama@email.com"/></label>}
                <label>Nomor WhatsApp<input required type="tel" autoComplete="tel" maxLength={30} value={request.data.phone} onChange={event=>request.setData('phone',event.target.value)} placeholder="08xxxxxxxxxx atau 628xxxxxxxxxx"/></label>
                {registering && <div className="ng-contact-fields"><label>Password<input required type="password" autoComplete="new-password" minLength={12} maxLength={128} value={request.data.password} onChange={event=>request.setData('password',event.target.value)} placeholder="Minimal 12 karakter"/></label><label>Konfirmasi password<input required type="password" autoComplete="new-password" minLength={12} maxLength={128} value={request.data.password_confirmation} onChange={event=>request.setData('password_confirmation',event.target.value)} placeholder="Ulangi password"/></label></div>}
                <small>{registering?'Password minimal 12 karakter. Setelah akun dibuat, verifikasi email agar email dan password dapat dipakai untuk masuk.':'Gunakan nomor yang sudah terdaftar. Belum punya akun? Pilih Daftar pelanggan di bawah.'}</small>
                {!otpReady && <p className="feedback warning" role="status">Pengiriman OTP belum tersedia. Hubungi pengelola toko.</p>}
                {registering && !canRegister && <p className="feedback warning" role="status">Toko belum menerima pendaftaran pelanggan baru. Pelanggan terdaftar tetap dapat masuk.</p>}
                <button className="button" disabled={busy || !otpReady || retrySeconds>0 || (registering && !canRegister)}><MessageCircle size={17}/>{request.processing?'Mengirim OTP…':retrySeconds>0?'Kirim ulang dalam '+retrySeconds+' detik':registering?'Kirim OTP & daftar':'Kirim OTP WhatsApp'}</button>
                {challengePending && <button type="button" className="text-link" disabled={busy} onClick={()=>setEditingPhone(false)}>Saya sudah punya kode OTP</button>}
            </form>:<form className="form-stack" onSubmit={event=>{
                event.preventDefault();
                if(emailLogin){
                    login.transform(data=>({email:data.identifier.trim().toLowerCase(),password:data.password}));
                    login.post('/account/password',{onFinish:()=>login.reset('password')});
                }else{
                    login.transform(data=>({phone:data.identifier.trim(),intent:'login'}));
                    login.post('/account/otp',{onSuccess:()=>{setEditingPhone(false);verification.reset('otp');verification.clearErrors();}});
                }
            }}>
                <FormErrors errors={login.errors}/>
                <label>Email atau nomor WhatsApp<input required type={emailLogin?'email':'text'} autoComplete="username" inputMode={emailLogin?'email':'text'} maxLength={255} disabled={busy} value={login.data.identifier} onChange={event=>{const value=event.target.value;login.setData({identifier:value,password:value.includes('@')===emailLogin?login.data.password:''});login.clearErrors();}} placeholder="nama@email.com atau 08xxxxxxxxxx"/></label>
                {emailLogin && <label>Password<input required type="password" autoComplete="current-password" maxLength={128} disabled={busy} value={login.data.password} onChange={event=>login.setData('password',event.target.value)}/></label>}
                <small aria-live="polite">{emailLogin?'Masuk dengan email terverifikasi dan password akun Anda.':'Masukkan nomor WhatsApp terdaftar untuk menerima kode OTP, atau gunakan email untuk masuk dengan password.'}</small>
                {emailLogin?<><button className="button" disabled={busy || !login.data.identifier.trim()}>{login.processing?'Memeriksa…':'Masuk'}</button><small>Lupa password? Gunakan nomor WhatsApp untuk masuk melalui OTP, lalu atur kembali password di akun Anda.</small></>:<>
                    {!otpReady && <p className="feedback warning" role="status">Pengiriman OTP belum tersedia. Hubungi pengelola toko.</p>}
                    <button className="button" disabled={busy || !otpReady || retrySeconds>0 || !login.data.identifier.trim()}><MessageCircle size={17}/>{login.processing?'Mengirim OTP…':retrySeconds>0?'Kirim ulang dalam '+retrySeconds+' detik':'Kirim OTP'}</button>
                    {challengePending && <button type="button" className="text-link" disabled={busy} onClick={()=>setEditingPhone(false)}>Saya sudah punya kode OTP</button>}
                </>}
            </form>}
            <footer className="customer-auth-footer"><p>{registering?'Sudah punya akun?':'Belum punya akun?'}</p><Link href={registering?'/account/login':'/account/register'}>{registering?'Masuk ke akun':'Daftar pelanggan'}</Link></footer>
            <p className="customer-auth-scope"><ShieldCheck size={15}/>Akun dan saldo hanya berlaku di {store.name}.</p>
            <Link className="text-link customer-auth-back" href="/"><ArrowLeft size={15}/>Kembali ke toko</Link>
        </section>
    </ShopLayout>;
}
