import { useState } from 'react';
import { Download, Smartphone, Monitor } from 'lucide-react';

export type ForwarderReleases = {apk:string|null;tools:{os:string;label:string;url:string|null}[]};
export default function ForwarderDownloads({downloads}:{downloads:ForwarderReleases}) {
    const [os,setOs] = useState('');
    const tools = downloads.tools.find(item=>item.os===os);
    return <div className="forwarder-downloads">
        <section><h3><Smartphone size={19}/>Aplikasi Android</h3><p>Pasang APK pada HP penerima notifikasi.</p>{downloads.apk ? <a className="button" href={downloads.apk}><Download size={17}/>Download APK</a> : <p className="field-help">APK belum tersedia.</p>}</section>
        <section><h3><Monitor size={19}/>Tools aktivasi listener</h3><p>Pilih OS komputer. ZIP hanya berisi tools untuk OS tersebut.</p><label>Sistem operasi komputer<select value={os} onChange={event=>setOs(event.target.value)}><option value="">Pilih OS komputer</option>{downloads.tools.map(item=><option key={item.os} value={item.os} disabled={!item.url}>{item.label}{!item.url?' · belum tersedia':''}</option>)}</select></label>{tools?.url ? <a className="button secondary" href={tools.url}><Download size={17}/>Download tools {tools.label}</a> : <button className="button secondary" type="button" disabled><Download size={17}/>Pilih OS terlebih dahulu</button>}</section>
    </div>;
}
