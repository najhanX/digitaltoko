import { useId } from 'react';

export type MarkupMode = 'own' | 'inherit';
export const inheritedMarkupLabel=(scope:string)=>scope==='product'?'Ikuti markup kategori / layanan':scope==='category'?'Ikuti markup layanan':'Gunakan bawaan: tanpa markup (0)';
export const inheritedUpdateLabel=(scope:string)=>scope==='product'?'Ikuti pembaruan kategori / layanan':scope==='category'?'Ikuti pembaruan layanan':'Gunakan bawaan: nonaktif';

export default function PricingSettings({scope,mode,markup,autoUpdate,onMode,onMarkup,onAutoUpdate,postpaid=false}:{
    postpaid?:boolean;scope:'product'|'category'|'service';mode:MarkupMode;markup:string;autoUpdate:string;
    onMode:(value:MarkupMode)=>void;onMarkup:(value:string)=>void;onAutoUpdate:(value:string)=>void;
}) {
    const id=useId();
    const markupHelp=scope==='product'?'Gunakan markup kategori. Jika kategori tidak mengaturnya, gunakan markup layanan. Jika keduanya tidak mengatur, markup 0.':scope==='category'?'Gunakan markup layanan kategori ini. Jika layanan tidak mengatur, markup 0.':'Tidak ada tingkat lain setelah layanan. Pilihan bawaan menggunakan markup 0.';
    const updateHelp=scope==='product'?'Ikuti status pembaruan dari kategori, lalu layanan. Jika tidak ada yang mengatur, pembaruan otomatis nonaktif.':scope==='category'?'Ikuti status pembaruan dari layanan. Jika layanan tidak mengatur, pembaruan otomatis nonaktif.':'Tidak ada tingkat lain setelah layanan. Pilihan bawaan menonaktifkan pembaruan otomatis.';
    return <div className="pricing-settings">
        <fieldset><legend>1. Tentukan markup</legend>
            <label>Sumber markup<select value={mode} aria-describedby={id+'-markup-help'} onChange={event=>onMode(event.target.value as MarkupMode)}><option value="inherit">{inheritedMarkupLabel(scope)}</option><option value="own">Atur markup sendiri</option></select></label>
            {mode==='own'?<><label>{postpaid?'Markup admin toko':'Nilai markup'}<input required maxLength={100} placeholder="Contoh: 5000, 5%, atau 5% + 5000" value={markup} onChange={event=>onMarkup(event.target.value)}/></label><p id={id+'-markup-help'}>Markup ini menggantikan markup dari tingkat lain, bukan ditambahkan. Isi 0 untuk tanpa markup.</p></>:<p id={id+'-markup-help'}>{markupHelp}</p>}
        </fieldset>
        <fieldset><legend>2. Tentukan pembaruan harga</legend>
            <label>Pembaruan harga otomatis<select value={autoUpdate} aria-describedby={id+'-update-help'} onChange={event=>onAutoUpdate(event.target.value)}><option value="inherit">{inheritedUpdateLabel(scope)}</option><option value="on">{'Aktif - modal parsing pilihan + markup'}</option><option value="off">Nonaktif — pertahankan harga jual tersimpan</option></select></label>
            <p id={id+'-update-help'}>{autoUpdate==='inherit'?updateHelp:autoUpdate==='on'?(postpaid?'Harga mengikuti parsing dasar pilihan, penyesuaian bertanda, dan markup. Admin layanan tetap terpisah.':'Harga jual dihitung ulang dari modal parsing pilihan yang tersedia dan markup yang dipilih. Harga dapat naik atau turun saat modal berubah.'):(postpaid?'Total biaya admin tersimpan tetap dipertahankan saat komisi berubah.':'Perubahan modal pemasok tidak mengubah harga jual tersimpan secara otomatis.')}</p>
            <p className="pricing-settings-note">Pilihan ini hanya mengatur pembaruan harga. Sumber markup tetap mengikuti pilihan pada bagian 1. Persen pascabayar memakai besar penyesuaian dari parsing dasar pilihan; tanda plus/minus tetap memengaruhi modal.</p>
        </fieldset>
    </div>;
}
