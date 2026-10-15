import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { CatalogPicker } from './CatalogForms';

export type CategorySelection={service_id:string;category_id:string;service:string;category:string};
export default function TaxonomySelect({storeId,value,onChange,enabled}:{storeId:string;value:CategorySelection;onChange:(value:CategorySelection)=>void;enabled:boolean}) {
    const [picker,setPicker]=useState<'service'|'category'|null>(null);
    const base='/stores/'+storeId+'/catalog-structure';
    return <section className="catalog-form-section"><h3>Layanan & kategori utama</h3><div className="taxonomy-selection">
        <div><small>Layanan</small><strong>{value.service || 'Belum dipilih'}</strong><button type="button" className="text-link" onClick={()=>setPicker(picker==='service'?null:'service')}>Pilih layanan</button></div>
        <div><small>Kategori utama</small><strong>{value.category || 'Belum dipilih'}</strong><button type="button" className="text-link" disabled={!value.service_id} onClick={()=>setPicker(picker==='category'?null:'category')}>Pilih kategori utama</button></div>
    </div>
    {picker==='service' && <CatalogPicker key="services" url={base+'/options?type=services'} enabled={enabled} multiple={false} selected={value.service_id?[{id:value.service_id,name:value.service}]:[]} searchLabel="Cari layanan" emptyText="Belum ada layanan. Tambahkan melalui menu Layanan & kategori." onToggle={item=>{onChange({service_id:item.id,service:item.name,category_id:'',category:''});setPicker('category');}}/>}
    {picker==='category' && value.service_id && <CatalogPicker key={value.service_id} url={base+'/options?type=categories&service_id='+value.service_id} enabled={enabled} multiple={false} selected={value.category_id?[{id:value.category_id,name:value.category}]:[]} searchLabel="Cari kategori utama" emptyText="Belum ada kategori pada layanan ini." onToggle={item=>{onChange({...value,category_id:item.id,category:item.name});setPicker(null);}}/>}
    <p className="taxonomy-helper">Belum ada pilihan? <Link className="text-link" href={base}>Kelola layanan & kategori</Link> terlebih dahulu.</p></section>;
}
