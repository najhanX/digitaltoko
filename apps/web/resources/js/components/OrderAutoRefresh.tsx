import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CircleCheck, RefreshCw, WifiOff } from 'lucide-react';

export default function OrderAutoRefresh({orderId,finished,paused=false,paymentPending=false,paymentCheckFailed=false}:{orderId:string;finished:boolean;paused?:boolean;paymentPending?:boolean;paymentCheckFailed?:boolean}) {
    const [checking,setChecking]=useState(false),[failed,setFailed]=useState(false),[lastChecked,setLastChecked]=useState<number|null>(null);
    useEffect(()=>{
        setChecking(false);
        if(finished || paused)return;
        let active=true,inFlight=false;
        let animation:ReturnType<typeof setTimeout>|undefined;
        const timer=setInterval(()=>{
            if(document.hidden || inFlight)return;
            inFlight=true;const started=performance.now();setChecking(true);
            const failure=()=>{if(active)setFailed(true);return false;};
            router.reload({only:['order'],async:true,
                onSuccess:()=>{if(active){setLastChecked(Date.now());setFailed(false);}},
                onError:failure,onNetworkError:failure,onHttpException:failure,
                onFinish:()=>{inFlight=false;if(active)animation=setTimeout(()=>{if(active)setChecking(false);},Math.max(0,1000-(performance.now()-started)));}
            });
        },10000);
        return()=>{active=false;clearInterval(timer);clearTimeout(animation);};
    },[orderId,finished,paused]);
    const hasError=failed || paymentCheckFailed;
    const title=finished?'Pembaruan status selesai':checking?(paymentPending?'Memeriksa pembayaran dan pesanan…':'Memperbarui status pesanan…'):hasError?(paymentCheckFailed?'Pembayaran belum dapat diperiksa':'Belum dapat memperbarui status'):'Pembaruan otomatis aktif';
    const detail=finished?'Pesanan telah mencapai status akhir.':checking?'Sedang mengambil status terbaru.':hasError?'Akan dicoba lagi secara otomatis.':lastChecked?'Terakhir diperiksa '+new Intl.DateTimeFormat('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Asia/Jakarta'}).format(lastChecked)+' WIB':paymentPending?'Pembayaran dan status pesanan diperiksa setiap 10 detik.':'Status diperiksa setiap 10 detik.';
    const Icon=finished?CircleCheck:hasError && !checking?WifiOff:RefreshCw;
    return <div className={'order-auto-refresh'+(checking && !finished?' is-checking':'')+(hasError && !checking && !finished?' has-error':'')} role="status" aria-live="polite" aria-atomic="true">
        <Icon size={17} aria-hidden="true"/><div><strong>{title}</strong><small>{detail}</small></div>
        {checking && !finished && <span className="order-refresh-progress" aria-hidden="true"/>}
    </div>;
}
