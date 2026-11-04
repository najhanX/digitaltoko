import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Clock3, Wrench } from 'lucide-react';
import Modal from './Modal';
import { designStyle, type ShopDesign } from './storefrontDesign';

export type StoreMaintenance = { message:string; ends_at:string|null; server_now:string; scope:string; store_name:string; store_slug:string; template:string; branding:{design?:Pick<ShopDesign,'accent'|'colors'|'logo'>} };

export function useMaintenanceCountdown(maintenance:StoreMaintenance|null|undefined) {
    const base = maintenance ? Date.parse(maintenance.server_now) : 0;
    const [clock,setClock] = useState({base,now:base});
    const now=clock.base===base?clock.now:base;
    useEffect(()=>{
        setClock({base,now:base});
        if(!maintenance)return;
        const start=performance.now();
        const timer=window.setInterval(()=>setClock({base,now:base+performance.now()-start}),1000);
        return ()=>window.clearInterval(timer);
    },[base,!!maintenance]);
    const seconds=maintenance?.ends_at?Math.max(0,Math.ceil((Date.parse(maintenance.ends_at)-now)/1000)):null;
    const active=!!maintenance && (seconds===null || seconds>0);
    const label=seconds===null?'Menunggu pemeliharaan selesai':`${String(Math.floor(seconds/3600)).padStart(2,'0')} : ${String(Math.floor(seconds%3600/60)).padStart(2,'0')} : ${String(seconds%60).padStart(2,'0')}`;
    return {active,label};
}

export function MaintenanceCountdown({maintenance}:{maintenance:StoreMaintenance}) {
    const {active,label}=useMaintenanceCountdown(maintenance);
    if(!active)return null;
    return <div className="sf-maintenance-countdown"><span><Clock3 size={16}/>{maintenance.ends_at?'Estimasi pemesanan dibuka kembali':'Pemesanan sementara dijeda'}</span><strong role="timer" aria-label="Sisa waktu maintenance">{label}</strong>{maintenance.ends_at&&<small>{new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Jakarta'}).format(new Date(maintenance.ends_at))} WIB</small>}</div>;
}

export default function StorefrontMaintenance({initial}:{initial?:StoreMaintenance|null}) {
    const [maintenance,setMaintenance]=useState(initial);
    const [dismissed,setDismissed]=useState<string|null>(null);
    useEffect(()=>router.on('navigate',event=>setMaintenance(event.detail.page.props.storeMaintenance as StoreMaintenance|null|undefined)),[]);
    const {active}=useMaintenanceCountdown(maintenance);
    const key=maintenance?JSON.stringify([maintenance.store_slug,maintenance.scope,maintenance.message,maintenance.ends_at]):null;
    const open=active && key!==dismissed;
    if(!maintenance)return null;
    const theme=['nebula','promo'].includes(maintenance.template)?'nebula':['aurora','minimal'].includes(maintenance.template)?'aurora':'atlas';
    const close=()=>setDismissed(key);
    return <div className={'sf-site sf-'+theme+(theme==='nebula'?' ng-site':'')+' sf-maintenance-layer'} style={designStyle(maintenance.branding)}><Modal open={open} title="Layanan sedang maintenance" onClose={close} footer={<button type="button" className="button" onClick={close}>Tutup & lihat katalog</button>}><div className="sf-maintenance-content"><span className="sf-maintenance-icon"><Wrench size={27}/></span>{maintenance.branding.design?.logo&&<img className="sf-maintenance-logo" src={maintenance.branding.design.logo} alt={maintenance.store_name}/>}<strong>{maintenance.store_name}</strong><p>{maintenance.message}</p><MaintenanceCountdown maintenance={maintenance}/><p className="sf-maintenance-note">Katalog dan harga tetap bisa dilihat. Pesanan yang sudah dibuat tetap diproses.</p></div></Modal></div>;
}
