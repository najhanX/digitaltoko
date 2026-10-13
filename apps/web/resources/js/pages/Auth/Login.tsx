import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, LockKeyhole } from 'lucide-react';
import Brand from '../../components/Brand';

export default function Login({mode,store}:{mode:'owner'|'staff';store:{id:string;name:string}|null;ownerOtpReady:boolean}) {
    const form=useForm({identifier:'',password:''});
    return <><Head title="Masuk panel"/><div className="auth-page"><div className="auth-card"><Brand/><div className="icon-box"><LockKeyhole/></div><h1>{mode==='staff'?'Masuk sebagai staff':'Masuk ke ruang toko'}</h1><p>{mode==='staff'?store?.name:'Akun owner terhubung dengan akun Hanshop Anda.'}</p>{mode==='staff'&&store?<form onSubmit={e=>{e.preventDefault();form.post(`/staff/${store.id}/login`);}}><label>Username atau email<input autoComplete="username" value={form.data.identifier} onChange={e=>form.setData('identifier',e.target.value)} required/></label><label>Password<input type="password" autoComplete="current-password" value={form.data.password} onChange={e=>form.setData('password',e.target.value)} required/></label>{Object.values(form.errors).map(e=><p className="form-error" key={e}>{e}</p>)}<button className="button" disabled={form.processing}>Masuk panel staff</button></form>:<div className="notice"><strong>Login owner belum diaktifkan</strong><p>Integrasi OTP WhatsApp/email Hanshop akan disambungkan pada tahap autentikasi. Tidak ada akses owner melalui login contoh.</p></div>}<Link className="text-link" href="/"><ArrowLeft size={16}/> Kembali ke DigitalToko</Link></div></div></>;
}
