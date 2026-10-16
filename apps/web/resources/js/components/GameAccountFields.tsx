import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, LoaderCircle, RotateCcw, UserRound, AlertCircle } from 'lucide-react';
import { gameDestination, gameFieldsReady, type GameAccount, type GameProfile } from './gameIdentity';

export default function GameAccountFields({profile,disabled=false,onChange}:{profile:GameProfile;disabled?:boolean;onChange:(destination:string,account:GameAccount|null)=>void}) {
    const [id,setId]=useState(''),[server,setServer]=useState(''),[attempt,setAttempt]=useState(0);
    const [state,setState]=useState<'idle'|'checking'|'valid'|'error'>('idle'),[message,setMessage]=useState('');
    const [account,setAccount]=useState<GameAccount|null>(null);
    const latest=useRef({id:'',server:''});
    const change=useRef(onChange);change.current=onChange;
    function update(nextId:string,nextServer:string){latest.current={id:nextId,server:nextServer};setId(nextId);setServer(nextServer);setAccount(null);setState('idle');change.current(gameDestination(profile,nextId,nextServer),null);}
    useEffect(()=>{
        const controller=new AbortController();setAccount(null);setState('idle');setMessage('');
        const current=()=>!controller.signal.aborted && latest.current.id===id && latest.current.server===server;
        change.current(gameDestination(profile,id,server),null);
        if(!gameFieldsReady(profile,id,server))return()=>controller.abort();
        const timer=setTimeout(async()=>{
            if(!current())return;
            setState('checking');
            try {
                const cookie=document.cookie.split('; ').find(item=>item.startsWith('XSRF-TOKEN='));
                const response=await fetch('/game-identity',{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':cookie?decodeURIComponent(cookie.slice(11)):''},body:JSON.stringify({category_id:profile.category_id,id,server})});
                const data=await response.json();if(!current())return;
                if(!response.ok)throw new Error((Object.values(data.errors ?? {}).flat()[0] as string) || data.message || 'Pengecekan akun belum berhasil. Coba ulang.');
                setAccount(data.data);setState('valid');change.current(data.data.destination,data.data);
            }catch(error){if(current()){setState('error');setMessage(error instanceof Error?error.message:'Layanan cek ID belum dapat dihubungi.');}}
        },800);
        return()=>{clearTimeout(timer);controller.abort();};
    },[id,server,attempt,profile.category_id,profile.game,profile.destination_format]);
    useEffect(()=>{
        if(!account)return;
        const timer=setTimeout(()=>{setAccount(null);setState('error');setMessage('Verifikasi sudah kedaluwarsa. Cek ulang ID sebelum memesan.');change.current(gameDestination(profile,latest.current.id,latest.current.server),null);},Math.max(0,account.expires_at*1000-Date.now()));
        return()=>clearTimeout(timer);
    },[account,profile,id,server]);
    return <div className="game-identity-fields"><div className={profile.server_label?'ng-contact-fields':'form-stack'}><label>{profile.id_label}<input required disabled={disabled} autoComplete="off" spellCheck={false} inputMode={profile.id_type==='numeric'?'numeric':'text'} maxLength={64} value={id} placeholder={profile.id_type==='riot'?'NamaPemain#TAG':'Masukkan '+profile.id_label} onChange={event=>update(profile.id_type==='riot'?event.target.value:event.target.value.trim(),server)}/></label>{profile.server_label && <label>{profile.server_label}{profile.server_options.length?<select required disabled={disabled} value={server} onChange={event=>update(id,event.target.value)}><option value="">Pilih server</option>{profile.server_options.map(option=><option key={option} value={option}>{option}</option>)}</select>:<input required disabled={disabled} autoComplete="off" inputMode="numeric" maxLength={8} value={server} placeholder="Contoh: 13486" onChange={event=>update(id,event.target.value.trim())}/>}</label>}</div>
        <div className={'game-identity-result '+state} role="status" aria-live="polite">{state==='checking'?<LoaderCircle size={20} className="game-identity-spinner"/>:state==='valid'?<CheckCircle2 size={21}/>:state==='error'?<AlertCircle size={20}/>:<UserRound size={20}/>}<div>{state==='valid' && account?<><small>Nickname {profile.name}</small><strong>{account.nickname}</strong><span>{account.id}{account.server?' · '+account.server:''}</span></>:<><strong>{state==='checking'?'Mencari nickname…':state==='error'?'Akun belum terverifikasi':'Nickname akan diperiksa otomatis'}</strong><small>{state==='error'?message:state==='checking'?'Tunggu sebentar, akun sedang diperiksa.':'Isi '+profile.id_label+(profile.server_label?' dan '+profile.server_label:'')+' untuk memastikan tujuan top up.'}</small></>}</div>{state==='error' && <button type="button" disabled={disabled} className="game-identity-retry" onClick={()=>setAttempt(value=>value+1)}><RotateCcw size={15}/>Cek ulang</button>}</div><small>Pastikan nickname sesuai dengan akun Anda. Ubah ID atau server untuk mengecek akun lain.</small>
    </div>;
}
