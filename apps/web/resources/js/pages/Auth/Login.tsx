import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, LockKeyhole } from 'lucide-react';
import ThemeToggle from '../../components/ThemeToggle';
import Brand from '../../components/Brand';
import PlatformHeader from '../../components/PlatformHeader';

type Props = {mode:'owner'|'staff';store:{id:string;name:string}|null;ownerOtpReady:boolean;challengePending?:boolean};
export default function Login({mode,store,ownerOtpReady,challengePending}:Props) {
    const staffForm=useForm({identifier:'',password:''});
    const request=useForm({intent:'login',identifier:'',name:'',channel:'wa'});
    const verify=useForm({otp:''});
    const [intent,setIntent]=useState('login');
    const flash=usePage().props.flash as {success?:string};
    return <><Head title="Masuk panel"/>{mode==='owner'&&<PlatformHeader/>}<div className={'auth-page panel-surface'+(mode==='owner'?' public-auth-body':'')}><div className="auth-card"><div className="auth-brand-row">{mode==='staff'&&<Brand/>}<ThemeToggle/></div><div className="icon-box"><LockKeyhole/></div><h1>{mode==='staff'?'Masuk sebagai staff':'Ruang owner DigitalToko'}</h1><p>{mode==='staff'?store?.name:'Gunakan akun Hanshop atau daftar dengan nomor WhatsApp Anda.'}</p>{flash?.success&&<div className="notice">{flash.success}</div>}
        {mode==='staff'&&store?<form onSubmit={e=>{e.preventDefault();staffForm.post('/staff/'+store.id+'/login');}}><label>Username atau email<input autoComplete="username" value={staffForm.data.identifier} onChange={e=>staffForm.setData('identifier',e.target.value)} required/></label><label>Password<input type="password" autoComplete="current-password" value={staffForm.data.password} onChange={e=>staffForm.setData('password',e.target.value)} required/></label>{Object.values(staffForm.errors).map(e=><p className="form-error" key={e}>{e}</p>)}<button className="button" disabled={staffForm.processing}>Masuk panel staff</button></form>:<>
            <div className="onboarding-tabs">{[['login','Masuk'],['register','Daftar owner']].map(([key,label])=><button type="button" key={key} className={intent===key?'selected':''} onClick={()=>{setIntent(key);request.setData('intent',key);if(key==='register')request.setData('channel','wa');request.clearErrors();}}>{label}</button>)}</div>
            {!ownerOtpReady&&<div className="notice"><strong>Koneksi Hanshop belum siap</strong><p>Administrator perlu mengatur koneksi identitas Hanshop sebelum OTP dapat dikirim.</p></div>}
            <form onSubmit={e=>{e.preventDefault();request.post('/login/otp');}}>
                {intent==='register'&&<label>Nama Anda<input value={request.data.name} onChange={e=>request.setData('name',e.target.value)} required autoComplete="name"/></label>}
                <label>{intent==='register'?'Nomor WhatsApp':'Nomor WhatsApp / ID Hanshop / email'}<input value={request.data.identifier} onChange={e=>request.setData('identifier',e.target.value)} required autoComplete="username" placeholder={intent==='register'?'08xxxxxxxxxx':'08xxxxxxxxxx / HD001 / email'}/></label>
                <label>Kirim OTP melalui<select value={request.data.channel} onChange={e=>request.setData('channel',e.target.value)}><option value="wa">WhatsApp</option>{intent==='login'&&<option value="email">Email terverifikasi</option>}</select></label>
                {Object.values(request.errors).map(e=><p className="form-error" key={e}>{e}</p>)}<button className="button" disabled={request.processing||!ownerOtpReady}>{request.processing?'Mengirim…':challengePending?'Kirim ulang OTP':'Kirim OTP'}</button>
            </form>
            {challengePending&&<form onSubmit={e=>{e.preventDefault();verify.post('/login/verify');}}><label>Kode OTP<input value={verify.data.otp} onChange={e=>verify.setData('otp',e.target.value)} inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required/></label>{Object.values(verify.errors).map(e=><p className="form-error" key={e}>{e}</p>)}<button className="button" disabled={verify.processing}>Verifikasi & masuk</button></form>}
            <p className="auth-fineprint">Pendaftaran membuat akun Hanshop setelah nomor WhatsApp terverifikasi. Fitur toko terbuka setelah KTP disetujui dan langganan aktif.</p>
        </>}<Link className="text-link" href="/"><ArrowLeft size={16}/> Kembali ke DigitalToko</Link></div></div></>;
}
