import { CatalogPicker, rupiah, type MappingOption } from './CatalogForms';
import RupiahInput from './RupiahInput';
export type PostpaidPair={inquiry_id:string;admin:string;inquiry?:MappingOption};
export default function PostpaidMappings({base,items,values,onChange}:{base:string;items:MappingOption[];values:Record<string,PostpaidPair>;onChange:(values:Record<string,PostpaidPair>)=>void}) {
 return <div className="form-stack">{items.filter(item=>item.source==='otomax').map(item=>{
  const pair=values[item.id]??{inquiry_id:'',admin:''};
  const update=(value:Partial<PostpaidPair>)=>onChange({...values,[item.id]:{...pair,...value}});
  return <section className="catalog-form-section form-stack" key={item.id}><h3>{item.name}</h3><p>Produk bayar: <strong>{item.sku}</strong> · {item.billing?.adjustment!==undefined?item.billing.adjustment<0?'Komisi '+rupiah(-item.billing.adjustment):'Penyesuaian '+rupiah(item.billing.adjustment):'Penyesuaian '+rupiah(item.cost)}</p>
   <CatalogPicker url={base+'/products/mappings?inquiry=1&connection_id='+item.connection_id} enabled multiple={false} selected={pair.inquiry?[pair.inquiry]:[]} searchLabel="Pilih produk cek tagihan" onToggle={option=>update({inquiry_id:option.id,inquiry:option as MappingOption})}/>
   <label>Admin layanan (sesuai produk supplier)<RupiahInput value={pair.admin} min={0} max={100000000} required onChange={admin=>update({admin})}/></label>
   <p className="field-help">Pilih kode cek dan kode bayar yang berpasangan pada supplier yang sama. Harga produk bayar OtomaX bertanda: minus mengurangi modal, positif menambah modal. Markup ditambahkan setelah penyesuaian modal.</p>
  </section>;
 })}</div>;
}
