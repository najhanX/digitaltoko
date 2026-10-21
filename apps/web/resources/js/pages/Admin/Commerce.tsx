import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Pencil, QrCode, CheckCircle2 } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import RupiahInput from '../../components/RupiahInput';
import CatalogHelp from '../../components/CatalogHelp';
import { FormErrors } from '../../components/CatalogForms';
import { money } from '../../components/Commerce';
export default function Commerce({price}:{price:number}) {
    const [open,setOpen]=useState(false),form=useForm({price:String(price)});
    function edit(){form.clearErrors();form.setData('price',String(price));setOpen(true);}
    return <WorkspaceLayout role="admin" title="Modul pembayaran" description="Atur penawaran modul tambahan untuk toko penyewa.">
        <section className="card admin-module-card">
            <header className="data-table-header"><div className="admin-module-heading"><span className="admin-feature-icon"><QrCode size={23}/></span><div><h2>QRIS custom</h2><p>Lisensi sekali beli · Per toko</p></div></div><span className={'store-status '+(price?'active':'draft')}>{price?'Tersedia':'Penjualan nonaktif'}</span></header>
            <div className="admin-module-content"><div><span className="field-help">Harga modul</span><strong className="admin-module-price">{price?money(price):'Belum dijual'}</strong><button type="button" className="button secondary" onClick={edit}><Pencil size={16}/>Ubah harga modul</button></div><ul className="admin-module-features"><li><CheckCircle2 size={17}/>Gunakan QRIS milik toko sendiri</li><li><CheckCircle2 size={17}/>Akses selama sewa toko aktif</li><li><CheckCircle2 size={17}/>Lisensi melekat pada satu toko</li></ul></div>
            <CatalogHelp title="Ketentuan lisensi"><p>Lisensi tidak dapat direfund atau dipindah. Midtrans masing-masing toko tersedia tanpa lisensi QRIS custom. Harga baru hanya berlaku untuk pembelian berikutnya.</p></CatalogHelp>
        </section>
        <Modal open={open} title="Ubah harga QRIS custom" description="Harga baru berlaku untuk pembelian berikutnya." onClose={()=>setOpen(false)} busy={form.processing} footer={<><button type="button" className="button secondary" disabled={form.processing} onClick={()=>setOpen(false)}>Batal</button><button className="button" type="submit" form="module-price" disabled={form.processing}>{form.processing?'Menyimpan…':'Simpan harga'}</button></>}>
            <form id="module-price" className="form-stack" onSubmit={event=>{event.preventDefault();if(form.processing)return;form.transform(data=>({price:Number(data.price)}));form.patch('/admin/commerce',{preserveScroll:true,onSuccess:()=>setOpen(false)});}}><FormErrors errors={form.errors}/><label>Harga sekali beli (Rp)<RupiahInput required min={0} max={100000000} value={form.data.price} onChange={value=>form.setData('price',value)}/></label><div className="catalog-inline-note">Isi Rp0 untuk menghentikan penjualan baru. Lisensi dan invoice yang sudah ada tetap berlaku.</div></form>
        </Modal>
    </WorkspaceLayout>;
}
