import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, Trash2, Layers, ArrowRight } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import ConfirmDialog from '../../components/ConfirmDialog';
import { CatalogPicker, FormErrors, type CatalogStore } from '../../components/CatalogForms';
import { FilterBar, Pagination, useServerList, type ListFilters, type PageMeta } from '../../components/ListControls';

type Service={id:string;name:string};
type Item=Service & {categories_count?:number;products_count:number;service?:Service};
type Props={role:'owner'|'staff';store:CatalogStore;tab:'services'|'categories';items:Item[];canManage:boolean;filters:ListFilters;pagination:PageMeta;selectedService:Service|null};
export default function Taxonomy({role,store,tab,items,canManage,filters,pagination,selectedService}:Props) {
    const base='/stores/'+store.id,category=tab==='categories';
    const list=useServerList(filters,['items','filters','pagination','selectedService']);
    const [open,setOpen]=useState(false),[editing,setEditing]=useState<Item|null>(null),[service,setService]=useState<Service|null>(null),[removing,setRemoving]=useState<Item|null>(null);
    const form=useForm({name:'',service_id:''}),deletion=useForm({});
    function start(item:Item|null) {setEditing(item);const parent=item?.service ?? selectedService;setService(parent);form.setData({name:item?.name ?? '',service_id:parent?.id ?? ''});form.clearErrors();setOpen(true);}
    return <WorkspaceLayout role={role} title="Layanan & kategori" description={'Susun katalog '+store.name+' dari layanan, kategori utama, lalu produk.'} actions={<><Link className="button secondary" href={base+'/products'}>Katalog produk<ArrowRight size={16}/></Link>{canManage && <button type="button" className="button" onClick={()=>start(null)}><Plus size={16}/>{category?'Tambah kategori utama':'Tambah layanan'}</button>}</>}>
        <nav className="content-tabs" aria-label="Struktur katalog"><Link className={!category?'selected':''} href={base+'/catalog-structure'}>Layanan</Link><Link className={category?'selected':''} href={base+'/catalog-structure?tab=categories'}>Kategori utama</Link></nav>
        <div className="summary-note"><strong>Layanan → kategori utama → produk</strong><p>Buat layanan, tambahkan kategori di dalamnya, lalu pilih kategori saat membuat produk. Data toko Anda terpisah dari toko lain. Bagian yang belum memiliki produk aktif belum ditampilkan pada katalog bot/web.</p></div>
        {category && selectedService && <div className="summary-note admin-owner-filter"><span>Layanan: <strong>{selectedService.name}</strong></span><Link className="text-link" href={base+'/catalog-structure?tab=categories'}>Semua layanan</Link></div>}
        <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder={category?'Cari nama kategori utama…':'Cari nama layanan…'}/>
        <section className="table-card data-list-table"><header className="data-table-header"><h2>{category?'Kategori utama':'Layanan toko'}</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy} label="Navigasi atas struktur katalog"/></header>
            <div className="table-scroll"><table><thead><tr><th>Nama {category?'kategori':'layanan'}</th><th>{category?'Layanan':'Kategori utama'}</th><th>Produk</th><th>Tindakan</th></tr></thead><tbody>{items.map(item=><tr key={item.id}><td><strong>{item.name}</strong></td><td>{category?item.service?.name:<Link className="text-link" href={base+'/catalog-structure?tab=categories&service_id='+item.id}>{item.categories_count} kategori<ArrowRight size={13}/></Link>}</td><td>{category?<Link className="text-link" href={base+'/products?category_id='+item.id}>{item.products_count} produk<ArrowRight size={13}/></Link>:item.products_count+' produk'}</td><td>{canManage?<div className="row-actions"><button type="button" className="button secondary small" onClick={()=>start(item)}><Pencil size={14}/>Edit</button><button type="button" className="icon-button danger-text" aria-label={'Hapus '+item.name} onClick={()=>{deletion.clearErrors();setRemoving(item);}}><Trash2 size={16}/></button></div>:<small>Akses baca</small>}</td></tr>)}</tbody></table></div>
            {!items.length && <div className="empty-state"><Layers size={30}/><h3>Belum ada {category?'kategori utama':'layanan'} yang cocok</h3><p>{canManage?'Gunakan tombol tambah untuk mulai menyusun katalog.':'Minta owner atau staff pengelola produk untuk menambahkan data.'}</p></div>}
            <Pagination meta={pagination} onPage={list.go} busy={list.busy}/>
        </section>
        <Modal open={open} title={(editing?'Edit ':'Tambah ')+(category?'kategori utama':'layanan')} onClose={()=>setOpen(false)} busy={form.processing} footer={<><button type="button" className="button secondary" disabled={form.processing} onClick={()=>setOpen(false)}>Batal</button><button type="submit" form="taxonomy-form" className="button" disabled={form.processing}>{form.processing?'Menyimpan…':'Simpan'}</button></>}>
            <form id="taxonomy-form" className="form-stack" onSubmit={event=>{event.preventDefault();if(form.processing)return;form.transform(data=>category && !editing?data:{name:data.name});const url=base+'/'+(category?'categories':'services')+(editing?'/'+editing.id:'');const options={preserveScroll:true,onSuccess:()=>setOpen(false)};if(editing)form.patch(url,options);else form.post(url,options);}}>
                <FormErrors errors={form.errors}/>
                <label>Nama {category?'kategori utama':'layanan'}<input data-modal-focus required maxLength={100} value={form.data.name} onChange={event=>form.setData('name',event.target.value)} placeholder={category?'Contoh: Telkomsel, Canva, Mobile Legends':'Contoh: Pulsa, Aplikasi premium, Game'}/></label>
                {category && <><label>Layanan induk<input readOnly value={service?.name ?? 'Pilih layanan di bawah'} required/></label>{!editing && <CatalogPicker url={base+'/catalog-structure/options?type=services'} enabled={open} multiple={false} selected={service?[service]:[]} searchLabel="Cari layanan induk" emptyText="Tambahkan layanan terlebih dahulu melalui tab Layanan." onToggle={item=>{setService({id:item.id,name:item.name});form.setData('service_id',item.id);}}/>}</>}

                {editing && <p className="summary-note">Nama pada produk ikut diperbarui. Aturan markup lama dipertahankan; nama yang berbenturan dengan aturan harga lain perlu diselesaikan terlebih dahulu.</p>}
            </form>
        </Modal>
        <ConfirmDialog open={!!removing} title={'Hapus '+(category?'kategori utama':'layanan')+'?'} confirmLabel="Hapus" danger busy={deletion.processing} onCancel={()=>setRemoving(null)} onConfirm={()=>{if(removing)deletion.delete(base+'/'+(category?'categories':'services')+'/'+removing.id,{preserveScroll:true,onSuccess:()=>setRemoving(null)});}}><FormErrors errors={deletion.errors}/><p>{removing?.name}</p><p>{category?'Kategori hanya dapat dihapus jika sudah tidak dipakai produk.':'Layanan hanya dapat dihapus jika sudah tidak memiliki kategori utama.'}</p></ConfirmDialog>
    </WorkspaceLayout>;
}
