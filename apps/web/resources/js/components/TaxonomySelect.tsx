import { Link } from '@inertiajs/react';
import CatalogSelect from './CatalogSelect';

export type CategorySelection={service_id:string;category_id:string;service:string;category:string};
export default function TaxonomySelect({storeId,value,onChange,enabled}:{storeId:string;value:CategorySelection;onChange:(value:CategorySelection)=>void;enabled:boolean}) {
    const base='/stores/'+storeId+'/catalog-structure';
    return <section className="catalog-form-section"><h3>Layanan & kategori</h3><div className="compact-field-grid">
        <CatalogSelect label="Layanan" url={base+'/options?type=services'} disabled={!enabled} value={value.service_id?{id:value.service_id,name:value.service}:null} onChange={item=>onChange({service_id:item.id,service:item.name,category_id:'',category:''})}/>
        <CatalogSelect key={value.service_id} label="Kategori utama" url={base+'/options?type=categories&service_id='+value.service_id} disabled={!enabled || !value.service_id} value={value.category_id?{id:value.category_id,name:value.category}:null} onChange={item=>onChange({...value,category_id:item.id,category:item.name})}/>
    </div><small className="taxonomy-helper">Belum ada pilihan? <Link className="text-link" href={base}>Kelola layanan & kategori</Link>.</small></section>;
}
