import { useId, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Pencil, CalendarClock, ShieldOff, ShieldCheck, Package, Check } from 'lucide-react';
import Modal from './Modal';
import CatalogHelp from './CatalogHelp';
import { date } from './StoreJourney';
import { packages, packageName } from './packageCatalog';
import type { StoreSummary } from '../types';

export type ManagedStore = StoreSummary & {
    created_at:string; owner:{id:number;name:string;email:string|null;phone:string|null;hanshop_user_id:string|null;verification_status:string};
    domain:{hostname:string;status:string;verified_at:string|null}|null;
};
export const adminActions:Record<string,string> = {edit:'Edit toko',expiry:'Koreksi masa aktif',package:'Ubah paket',suspend:'Tangguhkan toko',resume:'Pulihkan toko'};
function localDate(value:string|null) {
    if (!value) return '';
    const date = new Date(value);
    return new Date(date.getTime()-date.getTimezoneOffset()*60000).toISOString().slice(0,16);
}
export default function AdminStoreActions({store}:{store:ManagedStore}) {
    const [action,setAction] = useState('');
    const formId = useId();
    const form = useForm({name:store.name,template:store.template,expires_at:localDate(store.expires_at),package:packages.find(item=>item.category===store.category)?.key ?? '',reason:''});
    function open(value:string) {form.setData({name:store.name,template:store.template,expires_at:localDate(store.expires_at),package:packages.find(item=>item.category===store.category)?.key ?? '',reason:''});form.clearErrors();setAction(value);}
    const blocked = store.status === 'purging';
    const selectedPackage = packages.find(item=>item.key===form.data.package);
    return <>
        <div className="admin-store-actions">
            <button className="button secondary" disabled={blocked} onClick={()=>open('edit')}><Pencil size={16}/>Edit toko</button>
            <button className="button secondary" disabled={blocked || store.is_trial || !['draft','active','grace','expired'].includes(store.status)} onClick={()=>open('package')}><Package size={16}/>Ubah paket</button>
            <button className="button secondary" disabled={blocked || store.is_trial || !store.starts_at || !['active','grace','expired'].includes(store.status)} onClick={()=>open('expiry')}><CalendarClock size={16}/>Koreksi masa aktif</button>
            <button className="button secondary" disabled={blocked} onClick={()=>open(store.admin_suspended_at?'resume':'suspend')}>{store.admin_suspended_at?<ShieldCheck size={16}/>:<ShieldOff size={16}/>} {store.admin_suspended_at?'Pulihkan toko':'Tangguhkan toko'}</button>
        </div>
        <Modal open={!!action} title={adminActions[action] ?? 'Kelola toko'} description={store.name+' · '+store.slug} busy={form.processing} onClose={()=>setAction('')}
            footer={<><button type="button" className="button secondary" disabled={form.processing} onClick={()=>setAction('')}>Batal</button><button type="submit" form={formId} className={'button '+(action==='suspend'?'admin-danger-button':'')} disabled={form.processing}>{form.processing?'Menyimpan…':action==='suspend'?'Ya, tangguhkan':action==='resume'?'Ya, pulihkan':'Simpan perubahan'}</button></>}>
            <form className="form-stack" id={formId} onSubmit={event=>{
                event.preventDefault();if(form.processing)return;
                form.transform(data=>({action,reason:data.reason,...(action==='edit'?{name:data.name,template:data.template}:action==='package'?{package:data.package}:action==='expiry'?{expires_at:data.expires_at?new Date(data.expires_at).toISOString():''}:{})}));
                form.patch('/admin/stores/'+store.id,{preserveScroll:true,onSuccess:()=>setAction('')});
            }}>
                {action==='edit' && <><label>Nama toko<input value={form.data.name} required maxLength={100} onChange={event=>form.setData('name',event.target.value)}/></label><label>Template web<select value={form.data.template} onChange={event=>form.setData('template',event.target.value)}>{['aurora','nebula','atlas'].map(value=><option key={value} value={value}>{value[0].toUpperCase()+value.slice(1)}</option>)}</select></label><small className="field-help">Subdomain dan kepemilikan toko tetap.</small></>}
                {action==='package' && <><div className="summary-note"><strong>Paket saat ini: {packageName(store.category,store.plan)}</strong><p>Langsung berlaku, termasuk pengurangan fitur. Masa aktif tetap; tanpa tagihan atau refund otomatis.</p></div><label>Paket baru<select required aria-label="Paket baru" value={form.data.package} onChange={event=>form.setData('package',event.target.value)}>{packages.map(item=><option key={item.key} value={item.key}>{item.name} · {item.description}</option>)}</select></label>{selectedPackage && <CatalogHelp title={selectedPackage.name+' · Fitur paket tujuan'}><p>{selectedPackage.description}</p><ul className="package-feature-list">{selectedPackage.features.map(feature=><li key={feature}><Check size={15}/>{feature}</li>)}</ul></CatalogHelp>}<CatalogHelp title="Ketentuan perubahan paket"><p>Selesaikan invoice atau perubahan terjadwal terlebih dahulu. Produk, stok, dan sesi WhatsApp tetap disimpan. Akses layanan mengikuti paket baru; penangguhan admin tetap berlaku.</p></CatalogHelp></>}
                {action==='expiry' && <><div className="summary-note">Saat ini: <strong>{date(store.expires_at)}</strong><p>Koreksi administratif hanya untuk langganan berbayar yang sudah aktif. Selesaikan invoice atau perubahan paket terjadwal terlebih dahulu. Invoice lama dan harga tidak berubah.</p></div><label>Masa aktif baru (waktu lokal perangkat)<input type="datetime-local" required value={form.data.expires_at} onInput={event=>form.setData('expires_at',event.currentTarget.value)}/></label></>}
                {action==='suspend' && <div className="feedback warning"><strong>Layanan bot dan web akan dihentikan</strong><p>Data toko tetap disimpan dan masa sewa tetap berjalan. Pembayaran tidak mencabut penangguhan; pemulihan harus dilakukan admin.</p></div>}
                {action==='resume' && <div className="summary-note"><strong>Penangguhan akan dicabut</strong><p>Layanan kembali mengikuti masa aktif dan paket toko. Toko yang sudah kedaluwarsa tetap perlu diperpanjang. Koneksi WhatsApp mungkin perlu dihubungkan kembali.</p></div>}
                <label>Alasan tindakan<textarea required minLength={5} maxLength={1000} rows={3} value={form.data.reason} onChange={event=>form.setData('reason',event.target.value)} placeholder="Alasan ini dicatat dan dikirim ke notifikasi owner/staff."/></label>
                {Object.values(form.errors).map(error=><p className="form-error" key={error} role="alert">{error}</p>)}
            </form>
        </Modal>
    </>;
}
