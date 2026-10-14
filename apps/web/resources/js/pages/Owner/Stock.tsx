import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Plus, Pencil, Trash2, Eye, Package } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import ConfirmDialog from '../../components/ConfirmDialog';
import RupiahInput from '../../components/RupiahInput';
import { parseStockEntries } from '../../components/stockEntries';
import { CatalogPicker, FormErrors, rupiah, type CatalogStore } from '../../components/CatalogForms';
import { FilterBar, Pagination, useServerList, type PageMeta, type ListFilters } from '../../components/ListControls';
type Product={id:string;code:string;name:string};
type Stock={id:string;product_id:string;product:Product;cost:number;status:string;note:string|null;order_reference:string|null;created_at:string};
type Props={role:'owner'|'staff';store:CatalogStore;stocks:Stock[];canManage:boolean;canReveal:boolean;supported:boolean;filters:ListFilters;pagination:PageMeta};
export default function Stock({role,store,stocks,canManage,canReveal,supported,filters,pagination}:Props) {
    const base='/stores/'+store.id+'/stock',list=useServerList(filters,['stocks','filters','pagination']);
    const [open,setOpen]=useState(false),[editing,setEditing]=useState<Stock|null>(null),[chosen,setChosen]=useState<Product|null>(null),[removing,setRemoving]=useState<Stock|null>(null);
    const [revealing,setRevealing]=useState<Stock|null>(null),[secret,setSecret]=useState(''),[revealBusy,setRevealBusy]=useState(false),[revealError,setRevealError]=useState('');
    const revealSequence=useRef(0);
    const form=useForm({product_id:'',cost:'',note:'',payload:'',bulk:''}),deletion=useForm({});
    function close(){setOpen(false);setEditing(null);setChosen(null);form.reset();form.clearErrors();}
    function edit(item:Stock|null){setEditing(item);setChosen(item?.product ?? null);form.clearErrors();form.setData({product_id:item?.product_id ?? '',cost:item?String(item.cost):'',note:item?.note ?? '',payload:'',bulk:''});setOpen(true);}
    function closeReveal(){revealSequence.current++;setRevealing(null);setSecret('');setRevealBusy(false);setRevealError('');}
    async function reveal(item:Stock){
        const ticket=++revealSequence.current;setRevealing(item);setSecret('');setRevealError('');setRevealBusy(true);
        try {
            const cookie=document.cookie.split('; ').find(value=>value.startsWith('XSRF-TOKEN='));
            const token=cookie?decodeURIComponent(cookie.slice(11)):'';
            const response=await fetch(base+'/'+item.id+'/reveal',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-XSRF-TOKEN':token}});
            if(!response.ok)throw new Error();
            const data=await response.json();if(ticket===revealSequence.current)setSecret(data.data.payload);
        }catch {if(ticket===revealSequence.current)setRevealError('Credential belum dapat dibaca. Periksa hak akses atau coba kembali.');}
        finally {if(ticket===revealSequence.current)setRevealBusy(false);}
    }
    return <WorkspaceLayout role={role} title="Stok aplikasi premium" description={'Persediaan privat '+store.name+'. Credential hanya ditampilkan kepada akun dengan hak akses khusus.'}
        actions={canManage && <button className="button" type="button" disabled={!supported} onClick={()=>edit(null)}><Plus size={16}/>Tambah stok</button>}>
        {!supported && <div className="feedback warning"><strong>Stok sendiri memerlukan Space atau Galaxy</strong><p>Data stok lama tetap disimpan; perbarui paket untuk mengelolanya kembali.</p></div>}
        <section className="table-card data-list-table"><header className="data-table-header"><h2>Persediaan toko</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy}/></header>
            <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari catatan atau referensi pesanan…" choices={[{key:'status',label:'Status stok',options:{ready:'Ready',sent:'Terkirim'}}]}/>
            <div className="table-scroll"><table><thead><tr><th>Produk / stok</th><th>Harga modal</th><th>Status</th><th>Catatan</th><th>Tindakan</th></tr></thead><tbody>{stocks.map(item=><tr key={item.id}><td><strong>{item.product?.name}</strong><small>{item.product?.code}</small><small>Stok {item.id.slice(-8)}</small></td><td>{rupiah(item.cost)}</td><td><span className={'store-status '+(item.status==='ready'?'active':'expired')}>{item.status==='ready'?'Ready':'Terkirim'}</span><small>{item.order_reference}</small></td><td className="catalog-note">{item.note || '—'}</td><td><div className="row-actions">{canReveal && <button type="button" className="button secondary small" onClick={()=>void reveal(item)}><Eye size={14}/>Lihat data</button>}{canManage && supported && item.status==='ready' && <><button type="button" className="button secondary small" onClick={()=>edit(item)}><Pencil size={14}/>Edit</button><button type="button" className="icon-button danger-text" aria-label={'Hapus stok '+item.id.slice(-8)} onClick={()=>setRemoving(item)}><Trash2 size={16}/></button></>}</div></td></tr>)}</tbody></table>{!stocks.length && <div className="empty-state"><Package size={30}/><h3>Belum ada stok yang cocok</h3><p>Tambahkan akun premium atau ubah filter status.</p></div>}</div><Pagination meta={pagination} onPage={list.go} busy={list.busy}/>
        </section>
        <Modal open={open} title={editing?'Edit stok ready':'Tambah stok premium'} wide onClose={close} busy={form.processing} footer={<><button className="button secondary" type="button" disabled={form.processing} onClick={close}>Batal</button><button className="button" type="submit" form="premium-stock-form" disabled={form.processing}>{form.processing?'Menyimpan…':'Simpan stok'}</button></>}>
            <form id="premium-stock-form" className="form-stack" onSubmit={event=>{event.preventDefault();form.transform(data=>editing?{cost:Number(data.cost),note:data.note,payload:data.payload.trim()?data.payload:null}:{product_id:data.product_id,cost:Number(data.cost),note:data.note,entries:parseStockEntries(data.bulk)});const options={preserveScroll:true,onSuccess:close};if(editing)form.patch(base+'/'+editing.id,options);else form.post(base,options);}}><FormErrors errors={form.errors}/>
                {!editing && <section className="catalog-form-section"><h3>Pilih produk premium toko</h3><p>{chosen?chosen.code+' · '+chosen.name:'Buat atau impor produk premium melalui katalog toko terlebih dahulu.'}</p><CatalogPicker url={base+'/products'} enabled={open && !editing} multiple={false} selected={chosen?[chosen]:[]} onToggle={option=>{setChosen(option as Product);form.setData('product_id',option.id);}}/></section>}
                {editing && <p>{editing.product.code} · {editing.product.name}</p>}
                <div className="field-grid"><label>Modal per akun<RupiahInput value={form.data.cost} onChange={value=>form.setData('cost',value)} required min={0} max={1000000000000}/></label><label>Catatan batch<input maxLength={250} value={form.data.note} onChange={event=>form.setData('note',event.target.value)}/></label></div>
                {editing?<label>Ganti data akun<textarea rows={6} value={form.data.payload} autoComplete="off" spellCheck={false} placeholder="Kosong: pertahankan data akun lama" onChange={event=>form.setData('payload',event.target.value)}/><small>Credential lama tidak dimuat ke formulir edit. Gunakan Lihat data jika memiliki izin.</small></label>:<label>Data akun<textarea required rows={10} value={form.data.bulk} autoComplete="off" spellCheck={false} placeholder={'email: akun@example.com\npassword: …\ncatatan: …\n---\nemail: akun-kedua@example.com\npassword: …'} onChange={event=>form.setData('bulk',event.target.value)}/><small>Gunakan satu blok per akun. Pisahkan akun dengan baris --- . Maksimal 100 akun sekali tambah; modal berlaku per akun.</small></label>}
            </form>
        </Modal>
        <Modal open={!!revealing} title="Data stok privat" onClose={closeReveal} footer={<button className="button secondary" type="button" onClick={closeReveal}>Tutup</button>}>
            {revealing && <><p>{revealing.product.name} · stok {revealing.id.slice(-8)}</p>{revealBusy?<p role="status">Membuka data…</p>:revealError?<div role="alert"><p>{revealError}</p><button type="button" className="text-link" onClick={()=>void reveal(revealing)}>Coba kembali</button></div>:<pre className="stock-secret">{secret}</pre>}<small>Data ditutup saat modal ditutup. Jangan membagikannya sebelum produk diserahkan kepada pembeli.</small></>}
        </Modal>
        <ConfirmDialog open={!!removing} title="Hapus stok ready?" confirmLabel="Hapus stok" danger busy={deletion.processing} onCancel={()=>setRemoving(null)} onConfirm={()=>{if(removing)deletion.delete(base+'/'+removing.id,{preserveScroll:true,onSuccess:()=>setRemoving(null)});}}><FormErrors errors={deletion.errors}/><p>Stok {removing?.id.slice(-8)} untuk {removing?.product.name} akan dihapus. Stok yang sudah terkirim tidak dapat dihapus.</p></ConfirmDialog>
    </WorkspaceLayout>;
}
