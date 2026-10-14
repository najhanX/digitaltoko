import { useId, useState, type ReactNode } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Bot, Globe2, Layers } from 'lucide-react';
import Modal from './Modal';
import StoreJourney from './StoreJourney';
import TemplatePicker from './TemplatePicker';
import type { WorkspaceContext } from '../types';

export function CreateStoreButton({children, className = 'button'}:{children:ReactNode;className?:string}) {
    const [open,setOpen] = useState(false);
    const workspace = usePage().props.workspace as WorkspaceContext | null;
    return <><button type="button" className={className} onClick={() => setOpen(true)}>{children}</button>
        {open && <CreateStoreModal open onClose={() => setOpen(false)} platformDomain={workspace?.platformDomain ?? ''}/>}</>;
}

export default function CreateStoreModal({open,onClose,platformDomain}:{open:boolean;onClose:()=>void;platformDomain:string}) {
    const formId = useId();
    const form = useForm({name:'',slug:'',category:'bot',plan:'space',template:'aurora'});
    const services = [
        {key:'bot',label:'Bot WhatsApp',description:'Satu nomor WhatsApp untuk melayani pelanggan toko.',icon:Bot},
        {key:'web',label:'Web Topup',description:'Web toko dengan subdomain dan template pilihan.',icon:Globe2},
        {key:'full',label:'Web + Bot',description:'Web toko dan bot WhatsApp dalam satu langganan.',icon:Layers},
    ];
    return <Modal open={open} title="Buat toko baru" description="Tentukan identitas dan layanan toko. Paket dan durasi dipilih setelah toko dibuat." wide busy={form.processing} onClose={onClose}
        footer={<><button type="button" className="button secondary" disabled={form.processing} onClick={onClose}>Batal</button><button type="submit" form={formId} className="button" disabled={form.processing}>{form.processing?'Membuat toko…':'Buat toko & pilih paket'}<ArrowRight size={16}/></button></>}>
        <StoreJourney stage="store"/>
        <form id={formId} onSubmit={event => {event.preventDefault();if(form.processing)return;form.post('/owner/stores',{preserveScroll:true});}}>
            <fieldset className="modal-fields" disabled={form.processing}>
                <div className="field-grid">
                    <label>Nama toko<input value={form.data.name} onChange={event => form.setData('name',event.target.value)} required maxLength={100} placeholder="Contoh: Toko Digital Anda"/></label>
                    <label>Subdomain<input value={form.data.slug} onChange={event => form.setData('slug',event.target.value.toLowerCase())} required pattern="[a-z0-9]+(-[a-z0-9]+)*" minLength={3} maxLength={40} placeholder="toko-anda"/><small>{form.data.slug || 'toko-anda'}.{platformDomain}</small></label>
                </div>
                <p className="field-help">Subdomain ditetapkan saat toko dibuat. Gunakan huruf kecil, angka, dan tanda hubung.</p>
                <div className="card-section-heading"><div><h3>Layanan toko</h3><p>Pilih kanal yang ingin Anda sewakan.</p></div></div>
                <div className="service-options">{services.map(({key,label,description,icon:Icon}) => <label key={key} className={'service-option '+(form.data.category === key?'selected':'')}><input type="radio" name="category" checked={form.data.category === key} onChange={() => form.setData('category',key)}/><Icon size={23}/><strong>{label}</strong><small>{description}</small></label>)}</div>
                {form.data.category !== 'bot' && <><div className="card-section-heading"><div><h3>Template web</h3><p>Dapat diubah kembali melalui pengaturan toko.</p></div></div><TemplatePicker value={form.data.template} onChange={value => form.setData('template',value)}/></>}
                {Object.values(form.errors).length > 0 && <div className="feedback error" role="alert">{Object.entries(form.errors).map(([key,message]) => <p key={key}>{message}</p>)}</div>}
                <div className="summary-note"><strong>Masa sewa belum berjalan</strong><p>Toko dibuat sebagai draft. Setelah paket dibayar dan layanan siap, Anda mengonfirmasi aktivasi.</p></div>
            </fieldset>
        </form>
    </Modal>;
}
