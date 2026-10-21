import { useId, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, Pencil, Check } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import CatalogHelp from '../../components/CatalogHelp';
import RupiahInput from '../../components/RupiahInput';
import { FilterBar, Pagination, useServerList, serviceOptions, planOptions, type PageMeta, type ListFilters } from '../../components/ListControls';
import { packages, packageName, packageOfferName } from '../../components/packageCatalog';

type Plan = {id:number;name:string;category:string;plan:string;price:number;duration_days:number;active:boolean};
export default function Plans({plans,pagination,filters}:{plans:Plan[];pagination:PageMeta;filters:ListFilters}) {
    const list = useServerList(filters,['plans','pagination','filters']);
    const [editing,setEditing] = useState<number|null>(null);
    const [open,setOpen] = useState(false);
    const formId = useId();
    const form = useForm({name:packageOfferName('bot','space',30),category:'bot',plan:'space',price:'',duration_days:30,active:false});
    const selected = packages.find(item => item.category === form.data.category && item.plan === form.data.plan)!;
    function startEdit(plan:Plan|null) {
        setEditing(plan?.id ?? null);form.clearErrors();
        form.setData(plan ? {name:plan.name,category:plan.category,plan:plan.plan,price:String(plan.price),duration_days:plan.duration_days,active:plan.active}
            : {name:packageOfferName('bot','space',30),category:'bot',plan:'space',price:'',duration_days:30,active:false});
        setOpen(true);
    }
    return <WorkspaceLayout role="admin" title="Paket & harga" description="Sembilan paket dengan kanal dan sumber produk yang berbeda. Atur harga serta durasi setiap penawaran."
        actions={<button type="button" className="button" onClick={() => startEdit(null)}><Plus size={16}/>Tambah paket</button>}>
        <section className="table-card data-list-table catalog-data-card">
            <header className="data-table-header"><h2>Penawaran paket</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy} label="Navigasi atas paket"/></header>
            <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari nama penawaran…" choices={[{key:'category',label:'Layanan',options:serviceOptions},{key:'package',label:'Paket',options:planOptions},{key:'active',label:'Status',options:{'1':'Tersedia','0':'Nonaktif'}}]}/>
            <div className="table-scroll"><table className="admin-plan-table"><thead><tr><th scope="col">Penawaran</th><th scope="col">Layanan & sumber</th><th scope="col">Harga</th><th scope="col">Durasi</th><th scope="col">Status</th><th scope="col">Tindakan</th></tr></thead><tbody>{plans.map(plan=>{
                return <tr key={plan.id}><td><strong>{plan.name}</strong><small>{packageName(plan.category,plan.plan)}</small></td><td><span>{serviceOptions[plan.category as keyof typeof serviceOptions]}</span><small>{plan.plan==='space'?'Stok premium sendiri':plan.plan==='orbit'?'Digiflazz sendiri':'Stok premium + Digiflazz sendiri'}</small></td><td className="table-cell-center admin-price-cell">Rp{plan.price.toLocaleString('id-ID')}</td><td className="table-cell-center">{plan.duration_days} hari</td><td className="table-cell-center"><span className={'store-status '+(plan.active?'active':'draft')}>{plan.active?'Tersedia':'Nonaktif'}</span></td><td className="table-cell-center"><button type="button" className="button secondary small" onClick={()=>startEdit(plan)}><Pencil size={15}/>Edit</button></td></tr>;
            })}</tbody></table></div>
            {!plans.length&&<div className="empty-state"><h3>Belum ada penawaran yang cocok</h3><p>Ubah filter atau tambahkan penawaran baru.</p></div>}
            <Pagination meta={pagination} onPage={list.go} busy={list.busy}/>
        </section>
        <Modal open={open} title={editing?'Edit penawaran paket':'Tambah penawaran paket'} description="Harga dan durasi baru berlaku untuk pembelian berikutnya." busy={form.processing} onClose={() => setOpen(false)}
            footer={<><button type="button" className="button secondary" disabled={form.processing} onClick={() => setOpen(false)}>Batal</button><button type="submit" form={formId} className="button" disabled={form.processing}>{form.processing?'Menyimpan…':'Simpan paket'}</button></>}>
            <form className="form-stack" id={formId} onSubmit={event => {
                event.preventDefault();if(form.processing)return;
                const options = {preserveScroll:true,onSuccess:() => {setOpen(false);setEditing(null);form.reset();}};
                form.transform(data => ({...data,price:Number(data.price)}));
                if(editing)form.patch('/admin/plans/'+editing,options);else form.post('/admin/plans',options);
            }}>
                <label>Paket layanan<select value={selected.key} onChange={event => {
                    const item = packages.find(item => item.key === event.target.value)!;
                    form.setData({...form.data,category:item.category,plan:item.plan,name:packageOfferName(item.category,item.plan,form.data.duration_days)});
                }}>{packages.map(item => <option key={item.key} value={item.key}>{item.name} · {item.description}</option>)}</select></label>
                <CatalogHelp title={selected.name+' · Rincian fitur'}><p>{selected.description}</p><ul className="package-feature-list">{selected.features.map(feature => <li key={feature}><Check size={15}/>{feature}</li>)}</ul></CatalogHelp>
                <label>Nama penawaran<input value={form.data.name} onChange={event => form.setData('name',event.target.value)} required maxLength={100} placeholder="Mercury · 30 hari"/></label>
                <div className="compact-field-grid">
                <label>Harga (Rp)<RupiahInput min={1000} max={100000000} value={form.data.price} onChange={value => form.setData('price',value)} required/></label>
                <label>Durasi (hari)<input type="number" min={1} max={730} value={form.data.duration_days} onChange={event => {
                    const days = Number(event.target.value);
                    form.setData({...form.data,duration_days:days,name:form.data.name === packageOfferName(form.data.category,form.data.plan,form.data.duration_days) ? packageOfferName(form.data.category,form.data.plan,days) : form.data.name});
                }} required/></label>
                </div>
                <label className="checkbox-label"><input type="checkbox" checked={form.data.active} onChange={event => form.setData('active',event.target.checked)}/>Tersedia untuk dibeli</label>
                {Object.values(form.errors).map(error => <p className="form-error" key={error}>{error}</p>)}
            </form>
        </Modal>
    </WorkspaceLayout>;
}
