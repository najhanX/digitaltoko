import { Check, ArrowRight } from 'lucide-react';
import { packageName } from './packageCatalog';
import { Link } from '@inertiajs/react';
import type { StoreSummary } from '../types';

export const categories: Record<string,string> = {bot:'Bot WhatsApp',web:'Web Topup',full:'Web + Bot'};
export const storeStatuses: Record<string,string> = {draft:'Belum berlangganan',provisioning:'Menunggu aktivasi',active:'Aktif',grace:'Masa tenggang',expired:'Masa sewa habis',suspended:'Layanan berhenti',suspended_manual:'Ditinjau admin',purging:'Dalam penghapusan'};
export const money = (value: number) => 'Rp'+value.toLocaleString('id-ID');
export const date = (value?: string|null) => value ? new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Jakarta'}).format(new Date(value)) : 'Belum dimulai';
export function nextStoreStep(store: StoreSummary) {
    const href = '/stores/'+store.id+'/subscription';
    if (store.admin_suspended_at) return {title:'Toko ditangguhkan administrator',description:store.admin_suspension_reason ?? 'Hubungi administrator untuk tindak lanjut.',label:'Lihat status toko',href};
    if (store.billing_status === 'review') return {title:'Pembayaran perlu diperiksa',description:'Administrator perlu meninjau pembayaran sebelum paket dapat diterapkan.',label:'Lihat pembayaran',href};
    if (store.billing_status === 'pending') return {title:'Selesaikan pembayaran',description:'Invoice sudah dibuat. Bayar sesuai total QRIS, lalu periksa statusnya.',label:'Lanjutkan pembayaran',href};
    if (store.status === 'provisioning') return {title:'Siapkan dan aktifkan toko',description:store.category === 'web'?'Konfirmasikan aktivasi untuk mulai menghitung masa sewa.':'Koneksi WhatsApp harus siap sebelum masa sewa dimulai.',label:'Lanjutkan aktivasi',href};
    if (['expired','grace','suspended'].includes(store.status)) return {title:store.status === 'grace'?'Perpanjang sebelum layanan berhenti':'Perpanjang untuk kembali beroperasi',description:store.is_trial?'Trial telah selesai. Pilih paket berbayar untuk melanjutkan.':'Perpanjangan berlaku khusus untuk toko ini.',label:'Perpanjang langganan',href};
    if (['suspended_manual','purging'].includes(store.status)) return {title:'Toko sedang ditinjau',description:'Hubungi administrator untuk tindak lanjut toko ini.',label:'Lihat status toko',href};
    if (store.status === 'active') return {title:'Toko Anda aktif',description:'Kelola toko atau periksa masa langganan berikutnya.',label:'Kelola toko',href:'/owner/stores/'+store.id+'/manage'};
    return {title:'Pilih paket dan durasi',description:'Toko sudah dibuat. Pilih paket untuk menyiapkan pembayaran.',label:'Pilih paket',href};
}
export default function StoreJourney({stage = 'payment'}:{stage?:'store'|'payment'|'activation'|'ready'|'active'}) {
    const current = {store:0,payment:1,activation:2,ready:3,active:4}[stage];
    const steps = ['Buat toko','Pilih paket & bayar','Siapkan layanan','Aktifkan toko'];
    return <ol className="store-journey" aria-label="Tahapan aktivasi toko">{steps.map((label,index) => <li key={label} className={index < current?'complete':index === current?'current':''} aria-current={index === current?'step':undefined}><span>{index < current?<Check size={14}/>:index+1}</span><strong>{label}</strong></li>)}</ol>;
}
export function StoreCard({store,role = 'owner',permissions = [],preview = false,scoped = false}:{store:StoreSummary;role?:'owner'|'staff';permissions?:string[];preview?:boolean;scoped?:boolean}) {
    const next = nextStoreStep(store);
    const billing = role === 'owner' || permissions.includes('subscription.view');
    const settings = role === 'owner' || permissions.includes('web.manage');
    const paid = !!store.expires_at || store.status === 'provisioning';
    return <article className="card tenant-store-card">
        <div className="store-card-heading"><div><span className="store-category">{categories[store.category]}</span><h3>{scoped ? 'Status & langganan' : store.name}</h3><p>{store.slug}</p></div><span className={'store-status '+(store.admin_suspended_at?'suspended_manual':store.billing_status ?? store.status)}>{store.admin_suspended_at?'Ditangguhkan admin':store.billing_status === 'pending'?'Menunggu pembayaran':store.billing_status === 'review'?'Pembayaran diperiksa':storeStatuses[store.status] ?? store.status}</span></div>
        <dl className="store-card-meta"><div><dt>Paket</dt><dd>{paid?((store.is_trial?'Trial lama · ':'')+packageName(store.category,store.plan)):store.billing_status?'Pilihan ada pada invoice':'Belum dipilih'}</dd></div><div><dt>Masa aktif sampai</dt><dd>{date(store.expires_at)}</dd></div></dl>
        <div className="store-next-step"><strong>{next.title}</strong><p>{next.description}</p></div>
        {!preview && <div className="card-actions">{billing && <Link className="button" href={role === 'staff' || store.status !== 'active'?'/stores/'+store.id+'/subscription':next.href}>{role === 'staff'?'Lihat langganan':next.label}<ArrowRight size={16}/></Link>}{role === 'owner' && !scoped && <Link className="button secondary" href={'/owner/stores/'+store.id}>Ringkasan toko</Link>}{role === 'staff' && settings && <Link className="button secondary" href={'/staff/'+store.id+'/settings'}>Pengaturan</Link>}</div>}
    </article>;
}
