import {useForm} from '@inertiajs/react';
import {useEffect,useState} from 'react';
import {Clock3,Settings2,ShieldCheck} from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import {FormErrors} from '../../components/CatalogForms';

type Settings={enabled:boolean;active:boolean;message:string;ends_at:string|null};
type Active={message:string;ends_at:string|null};
const localTime=(value:string|null)=>value?new Date(Date.parse(value)+7*3600000).toISOString().slice(0,16):'';
export default function Settings({role,store,settings,platformMaintenance,initialNow}:{role:'owner'|'admin';store:{id:string;name:string}|null;settings:Settings;platformMaintenance:Active|null;initialNow:string}) {
    const [open,setOpen]=useState(false);
    const [now,setNow]=useState(()=>Date.parse(initialNow));
    const form=useForm({enabled:settings.enabled,message:settings.message,ends_at:localTime(settings.ends_at)});
    useEffect(()=>{const start=performance.now(),base=Date.parse(initialNow);setNow(base);const timer=setInterval(()=>setNow(base+performance.now()-start),1000);return()=>clearInterval(timer);},[initialNow]);
    const active=settings.enabled && (!settings.ends_at || Date.parse(settings.ends_at)>now);
    const seconds=settings.ends_at?Math.max(0,Math.ceil((Date.parse(settings.ends_at)-now)/1000)):null;
    const countdown=seconds===null?'Tanpa batas waktu':Math.floor(seconds/3600)+' jam '+Math.floor(seconds%3600/60)+' menit '+seconds%60+' detik';
    const url=store?'/stores/'+store.id+'/maintenance':'/admin/maintenance';
    return <WorkspaceLayout role={role} title="Maintenance layanan" description={store?'Kelola jeda layanan '+store.name+'.':'Hentikan sementara aktivitas baru di seluruh toko.'}>
        {platformMaintenance && <div className="feedback warning" role="status"><strong>Maintenance platform sedang aktif.</strong><p>Pengaturan toko tidak dapat membuka layanan sebelum maintenance platform berakhir.</p></div>}
        <section className="card onboarding-card account-profile-card"><div className="account-profile-row"><div><span className={'pill '+(active?'warning':'')}><ShieldCheck size={16}/>{active?'Maintenance aktif':'Layanan terbuka'}</span><h2 style={{marginTop:14}}>{store?store.name:'Seluruh toko'}</h2><p>{active?(settings.message || 'Layanan sedang dalam pemeliharaan.'):settings.enabled?'Batas waktu berakhir; maintenance sudah tidak membatasi toko.':'Pelanggan dapat membuat transaksi baru.'}</p></div><button className="button secondary" onClick={()=>{form.setData({enabled:active,message:settings.message,ends_at:active?localTime(settings.ends_at):''});form.clearErrors();setOpen(true);}}><Settings2 size={17}/>Atur</button></div>
        {active && <p className="summary-note"><Clock3 size={17}/> {countdown}{seconds!==null && <small> · Buka otomatis sesuai batas waktu</small>}</p>}
        <p className="field-help">Pesanan baru, topup baru, pendaftaran pelanggan, dan cek tagihan baru dijeda. Pembayaran serta status pesanan yang sudah dibuat tetap berjalan.</p><p className="field-help">{store?'Panel pengelola tetap dapat diakses.':'Landing page DigitalToko, panel, callback, dan pembaruan transaksi tetap dapat diakses.'}</p></section>
        <Modal open={open} title={store?'Maintenance toko':'Maintenance seluruh toko'} onClose={()=>setOpen(false)} busy={form.processing} footer={<><button className="button secondary" disabled={form.processing} onClick={()=>setOpen(false)}>Batal</button><button type="submit" form="maintenance-settings" className="button" disabled={form.processing}>{form.processing?'Menyimpan…':'Simpan pengaturan'}</button></>}>
        <form id="maintenance-settings" className="form-stack" onSubmit={event=>{event.preventDefault();form.transform(data=>({...data,ends_at:data.enabled&&data.ends_at?new Date(data.ends_at+':00+07:00').toISOString():null}));form.patch(url,{preserveScroll:true,onSuccess:()=>setOpen(false)});}}><FormErrors errors={form.errors}/><label className="checkbox-label"><input type="checkbox" checked={form.data.enabled} onChange={event=>form.setData('enabled',event.target.checked)}/>Aktifkan maintenance {store?'toko ini':'seluruh toko'}</label>{form.data.enabled && <><label>Pesan untuk pelanggan<textarea rows={3} maxLength={1000} value={form.data.message} placeholder="Kami sedang meningkatkan layanan. Terima kasih sudah menunggu." onChange={event=>form.setData('message',event.target.value)}/></label><label>Buka otomatis pada (WIB)<input type="datetime-local" value={form.data.ends_at} onChange={event=>form.setData('ends_at',event.target.value)}/></label><small>Kosongkan jika akan dibuka manual. Waktu selesai ditampilkan sebagai countdown kepada pelanggan.</small></>}</form></Modal>
    </WorkspaceLayout>;
}
