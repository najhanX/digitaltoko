import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Mail, Smartphone, UserRound } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import OwnerEmailVerification from '../../components/OwnerEmailVerification';
import { FormErrors } from '../../components/CatalogForms';

type Profile={name:string;phone:string;email:string;email_verified:boolean};
export default function Account({profile}:{profile:Profile}) {
    const [dialog,setDialog]=useState<'name'|'phone'|'email'|null>(null);
    const [sent,setSent]=useState(false);
    const name=useForm({name:profile.name});
    const phone=useForm({phone:profile.phone});
    const verify=useForm({otp:''});
    const busy=name.processing || phone.processing || verify.processing;
    function close(){setDialog(null);setSent(false);verify.reset();}
    return <WorkspaceLayout title="Profil akun" description="Identitas akun yang terhubung ke Hanshop.">
        <section className="card onboarding-card account-profile-card"><div className="form-stack">
            {[{id:'name',label:'Nama akun',value:profile.name,Icon:UserRound},{id:'phone',label:'WhatsApp',value:profile.phone,Icon:Smartphone},{id:'email',label:'Email',value:profile.email || 'Belum ditambahkan',Icon:Mail}].map(({id,label,value,Icon})=><div className="account-profile-row" key={id}><div><strong><Icon size={18} style={{verticalAlign:'middle',marginRight:8}}/>{label}</strong><p style={{overflowWrap:'anywhere'}}>{value}</p></div><button type="button" className="button secondary" aria-label={'Ubah '+label.toLowerCase()} onClick={()=>{name.clearErrors();phone.clearErrors();verify.clearErrors();setDialog(id as 'name'|'phone'|'email');}}>Ubah</button></div>)}
        </div></section>
        <Modal open={dialog==='name'} title="Ubah nama akun" description="Perubahan ini tidak mengubah data verifikasi KTP." onClose={close} busy={busy} footer={<button type="submit" form="owner-name" className="button" disabled={busy}>{busy?'Menyimpan…':'Simpan nama'}</button>}><form id="owner-name" className="form-stack" onSubmit={event=>{event.preventDefault();name.patch('/owner/account',{preserveScroll:true,onSuccess:close});}}><FormErrors errors={name.errors}/><label>Nama<input required maxLength={100} autoComplete="name" value={name.data.name} onChange={event=>name.setData('name',event.target.value)}/></label></form></Modal>
        <Modal open={dialog==='phone'} title="Ubah nomor WhatsApp" description="Verifikasi nomor baru sebelum mengganti nomor akun Hanshop." onClose={close} busy={busy} footer={<>{sent && <button className="button secondary" disabled={busy} onClick={()=>setSent(false)}>Ganti nomor / kirim ulang</button>}<button type="submit" form="owner-phone" className="button" disabled={busy}>{busy?'Memproses…':sent?'Verifikasi & simpan':'Kirim OTP WhatsApp'}</button></>}>
            <form id="owner-phone" className="form-stack" onSubmit={event=>{event.preventDefault();if(sent)verify.post('/owner/account/phone/verify',{preserveScroll:true,onSuccess:close});else phone.post('/owner/account/phone/otp',{preserveScroll:true,onSuccess:()=>setSent(true)});}}><FormErrors errors={sent?verify.errors:phone.errors}/>{sent?<label>OTP nomor baru<input required inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} value={verify.data.otp} onChange={event=>verify.setData('otp',event.target.value.replace(/\D/g,''))}/></label>:<label>Nomor WhatsApp baru<input required type="tel" maxLength={30} autoComplete="tel" value={phone.data.phone} onChange={event=>phone.setData('phone',event.target.value)}/></label>}</form>
        </Modal>
        {dialog==='email' && profile.email_verified && <OwnerEmailVerification gate={{email:profile.email,required:false,pending:false}} onClose={close}/>}
    </WorkspaceLayout>;
}
