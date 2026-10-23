import { useState } from 'react';
import { Check, Copy, Globe2 } from 'lucide-react';

export default function SupplierWhitelistIp({ip,supplier}:{ip:string|null;supplier:string}) {
    const [copyStatus,setCopyStatus] = useState<'idle'|'copied'|'failed'>('idle');
    return <section className="supplier-whitelist-ip" aria-label="IP koneksi DigitalToko">
        <div className="supplier-whitelist-title"><Globe2 size={18}/><strong>IP koneksi DigitalToko</strong></div>
        {ip ? <><p>Daftarkan IP ini pada whitelist akun {supplier} Anda.</p><div className="supplier-whitelist-value"><code>{ip}</code><button type="button" className="button secondary small" onClick={async()=>{try{await navigator.clipboard.writeText(ip);setCopyStatus('copied');}catch{setCopyStatus('failed');}}}>{copyStatus==='copied'?<Check size={15}/>:<Copy size={15}/>} {copyStatus==='copied'?'Tersalin':'Salin IP'}</button></div>{supplier==='OtomaX'&&<small>Berikan IP ini kepada supplier untuk mengizinkan koneksi akun Anda. IP callback supplier diatur terpisah.</small>}<span role="status" className={copyStatus==='failed'?'form-error':'sr-only'}>{copyStatus==='failed'?'Belum bisa menyalin otomatis. Pilih dan salin IP di atas.':copyStatus==='copied'?'IP berhasil disalin.':''}</span></>
        : <p>IP koneksi belum diatur oleh DigitalToko. Hubungi dukungan untuk mengaktifkan informasi whitelist.</p>}
    </section>;
}
