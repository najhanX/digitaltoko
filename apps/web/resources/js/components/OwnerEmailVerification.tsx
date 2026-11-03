import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Modal from './Modal';
import { FormErrors } from './CatalogForms';

export type OwnerEmailGate = {required:boolean; email:string; pending:boolean};
export default function OwnerEmailVerification({gate, mandatory=false, onClose}:{gate:OwnerEmailGate; mandatory?:boolean; onClose:()=>void}) {
    const [step,setStep]=useState(gate.pending?'verify':'request');
    const email=useForm({email:gate.email});
    const verify=useForm({otp:''});
    const busy=email.processing || verify.processing;
    return <Modal open title={mandatory?'Verifikasi email akun':'Ubah email akun'} description={mandatory?'Lengkapi email terverifikasi untuk melanjutkan ke panel.':'Email lama tetap digunakan sampai verifikasi berhasil.'} onClose={onClose} dismissible={!mandatory} busy={busy}
        footer={<>{mandatory && <Link href="/logout" method="post" as="button" className="button secondary" disabled={busy}>Keluar</Link>}{step==='verify' && <button type="button" className="button secondary" disabled={busy} onClick={()=>{setStep('request');verify.reset();}}>Ganti email / kirim ulang</button>}<button type="submit" form="owner-email-modal" className="button" disabled={busy}>{busy?'Memproses…':step==='request'?'Kirim OTP email':'Verifikasi & simpan'}</button></>}>
        {step==='request'?<form id="owner-email-modal" className="form-stack" onSubmit={event=>{event.preventDefault();email.post('/owner/profile/email/otp',{preserveScroll:true,onSuccess:()=>setStep('verify')});}}><FormErrors errors={email.errors}/><label>Email aktif<input required type="email" maxLength={255} autoComplete="email" value={email.data.email} onChange={event=>email.setData('email',event.target.value)}/></label><small>Kode verifikasi berlaku 5 menit. Periksa juga folder spam.</small></form>:<form id="owner-email-modal" className="form-stack" onSubmit={event=>{event.preventDefault();verify.post('/owner/profile/email/verify',{preserveScroll:true,onSuccess:onClose});}}><FormErrors errors={verify.errors}/><label>Kode OTP email<input required autoComplete="one-time-code" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={verify.data.otp} onChange={event=>verify.setData('otp',event.target.value.replace(/\D/g,''))}/></label><small>Masukkan kode dari email verifikasi terbaru.</small></form>}
    </Modal>;
}
