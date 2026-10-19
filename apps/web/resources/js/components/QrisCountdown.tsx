import { useEffect, useState, type ReactNode } from 'react';
import { Clock3 } from 'lucide-react';
import { paymentClock, paymentSecondsRemaining } from './paymentTime';

export default function QrisCountdown({expiresAt,children}:{expiresAt:string;children:ReactNode}) {
    const [clock,setClock]=useState<{deadline:string;remaining:number|null}|null>(null);
    useEffect(()=>{
        let timer:ReturnType<typeof setInterval>|undefined;
        function update(){
            const remaining=paymentSecondsRemaining(expiresAt,Date.now());
            setClock({deadline:expiresAt,remaining});
            if(remaining===null || remaining===0)clearInterval(timer);
        }
        timer=setInterval(update,1000);update();
        document.addEventListener('visibilitychange',update);
        return()=>{clearInterval(timer);document.removeEventListener('visibilitychange',update);};
    },[expiresAt]);
    const remaining=clock?.deadline===expiresAt?clock.remaining:null;
    const expired=remaining===0;
    return <><div className={'payment-countdown'+(expired?' expired':remaining!==null && remaining<=60?' urgent':'')}><span><Clock3 size={19}/>{expired?'Batas waktu pembayaran berakhir':'Sisa waktu pembayaran'}</span><strong role="timer" aria-live="off" aria-label="Sisa waktu pembayaran">{remaining===null?'--:--':paymentClock(remaining)}</strong></div>
        {expired?<p className="feedback error" role="status">Waktu pembayaran QRIS sudah habis. Jangan lakukan pembayaran pada QR ini. Jika sudah membayar, tekan Periksa / siapkan pembayaran untuk memastikan statusnya.</p>:remaining!==null?children:clock?<p role="status">Batas waktu pembayaran belum dapat dibaca. Periksa kembali status pembayaran.</p>:null}</>;
}
