import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, FlaskConical, Plus, ScanText, Settings2, Trash2 } from 'lucide-react';
import Modal from './Modal';
import ConfirmDialog from './ConfirmDialog';
import { FormErrors } from './CatalogForms';
import { money } from './Commerce';

export type QrisParserConfig = {name:string;field:'text'|'combined';excluded:string;rules:{name:string;template:string;active:boolean}[]};
type Result = {matched:boolean;reason:string;amount:number|null;rule:string|null;fields:Record<string,string>};
export default function QrisParserSettings({base,config,configured,canManage,source}:{base:string;config:QrisParserConfig;configured:boolean;canManage:boolean;source:string}) {
    const [modal,setModal]=useState<'edit'|'test'|null>(null),[selected,setSelected]=useState(0),[remove,setRemove]=useState(false);
    const form=useForm({action:'parser',parser:structuredClone(config)});
    const [title,setTitle]=useState(''),[text,setText]=useState(''),[result,setResult]=useState<Result|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
    const request=useRef<AbortController|null>(null);
    const [testConfig,setTestConfig]=useState(config),[testingDraft,setTestingDraft]=useState(false);
    useEffect(()=>()=>request.current?.abort(),[]);
    const rule=form.data.parser.rules[selected];
    function close(){request.current?.abort();setBusy(false);setModal(null);setRemove(false);}
    function openTest(draft=false){setTestConfig(draft?structuredClone(form.data.parser):config);setTestingDraft(draft);setResult(null);setError('');setModal('test');}
    function edit(){form.setData({action:'parser',parser:structuredClone(config)});form.clearErrors();setSelected(0);setModal('edit');}
    function changeRule(values:Partial<QrisParserConfig['rules'][number]>){form.setData('parser',{...form.data.parser,rules:form.data.parser.rules.map((item,index)=>index===selected?{...item,...values}:item)});form.clearErrors();}
    async function test(){
        if(busy)return;
        const controller=new AbortController();request.current=controller;setBusy(true);setError('');setResult(null);
        try{
            const csrf=document.cookie.split('; ').find(item=>item.startsWith('XSRF-TOKEN='));
            const response=await fetch(base+'/parser-test',{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':csrf?decodeURIComponent(csrf.slice(11)):''},body:JSON.stringify({parser:testConfig,title,text})});
            const data=await response.json();
            if(!response.ok)throw Error((Object.values(data.errors??{}).flat()[0] as string)??'Uji belum dapat dilakukan. Periksa hak akses dan isian.');
            if(!controller.signal.aborted)setResult(data);
        }catch(error){if(!controller.signal.aborted)setError(error instanceof Error?error.message:'Uji gagal. Coba kembali.');}
        finally{if(!controller.signal.aborted)setBusy(false);}
    }
    return <>
        <section className="onboarding-card sales-settings-card qris-parser-card">
            <div className="sales-card-title"><span className="sales-card-icon"><ScanText size={20}/></span><h2>Penangkap QRIS</h2></div>
            <div className="sales-setting-copy"><strong>{configured?config.name:'Pembacaan bawaan'}</strong><p>{source} · {configured?`${config.rules.filter(rule=>rule.active).length} format aktif`:'Atur format untuk aplikasi penerima uang Anda.'}</p></div>
            {canManage && <div className="card-actions"><button type="button" className="button secondary" onClick={edit}><Settings2 size={16}/>Atur format</button><button type="button" className="button secondary" onClick={()=>openTest()}><FlaskConical size={16}/>Uji penangkap</button></div>}
        </section>
        <Modal open={modal==='edit'} title="Penangkap notifikasi QRIS" description="Sesuaikan format pesan pembayaran masuk dari aplikasi toko." busy={form.processing} onClose={close} footer={<><button className="button secondary" onClick={close} disabled={form.processing}>Batal</button><button type="button" className="button secondary" disabled={form.processing} onClick={()=>openTest(true)}>Uji contoh</button><button type="submit" form="qris-parser-form" className="button" disabled={form.processing}>{form.processing?'Menyimpan…':'Simpan penangkap'}</button></>}>
            <form id="qris-parser-form" className="form-stack" onSubmit={event=>{event.preventDefault();form.post(base,{preserveScroll:true,onSuccess:close});}}>
                <FormErrors errors={form.errors}/>
                <p className="field-help">Sumber: <strong>{source}</strong>. Ganti aplikasi melalui Atur gateway.</p>
                <label>Nama profil<input required maxLength={80} value={form.data.parser.name} onChange={event=>form.setData('parser',{...form.data.parser,name:event.target.value})}/></label>
                <div className="qris-parser-picker"><label>Format pesan<select value={selected} onChange={event=>setSelected(Number(event.target.value))}>{form.data.parser.rules.map((item,index)=><option key={index} value={index}>{index+1}. {item.name||'Format baru'}{item.active?'':' · Nonaktif'}</option>)}</select></label><button type="button" className="button secondary small" disabled={form.data.parser.rules.length>=10} onClick={()=>{const index=form.data.parser.rules.length;form.setData('parser',{...form.data.parser,rules:[...form.data.parser.rules,{name:'Format '+(index+1),template:'',active:true}]});setSelected(index);}}><Plus size={16}/>Tambah</button></div>
                {rule && <section className="qris-parser-rule form-stack"><div className="qris-parser-rule-heading"><label className="checkbox-label"><input type="checkbox" checked={rule.active} onChange={event=>changeRule({active:event.target.checked})}/>Format aktif</label><button type="button" className="button danger small" disabled={form.data.parser.rules.length===1} onClick={()=>setRemove(true)}><Trash2 size={15}/>Hapus</button></div>
                    <label>Nama format<input required maxLength={80} value={rule.name} onChange={event=>changeRule({name:event.target.value})}/></label>
                    <label>Format notifikasi<textarea required rows={3} maxLength={1000} value={rule.template} onChange={event=>changeRule({template:event.target.value})} placeholder="Rp[nominal] diterima DANA Bisnis."/></label>
                    <div className="qris-parser-tokens" aria-label="Sisipkan placeholder">{['nominal','pengirim','referensi','waktu','abaikan'].map(token=><button type="button" key={token} className="button secondary small" onClick={()=>changeRule({template:rule.template+'['+token+']'})}>[{token}]</button>)}</div>
                    <p className="field-help">Salin pesan lengkap, lalu ganti nominal dengan [nominal]. Pertahankan tanda baca. [abaikan] untuk bagian yang berubah. Waktu pencocokan invoice tetap memakai waktu notifikasi dari HP.</p>
                </section>}
                <details className="qris-parser-advanced"><summary>Pengaturan tambahan</summary><div className="form-stack"><label>Teks yang dibaca<select value={form.data.parser.field} onChange={event=>form.setData('parser',{...form.data.parser,field:event.target.value as 'text'|'combined'})}><option value="text">Isi notifikasi saja</option><option value="combined">Judul + isi notifikasi</option></select></label><label>Kata yang ditolak<textarea rows={3} maxLength={1000} value={form.data.parser.excluded} onChange={event=>form.setData('parser',{...form.data.parser,excluded:event.target.value})}/></label><p className="field-help">Satu kata/frasa per baris. Pesan gagal, dibatalkan, dan berhasil dikirim selalu ditolak. Maksimal 10 format; minimal satu aktif.</p></div></details>
            </form>
        </Modal>
        <ConfirmDialog open={remove} title="Hapus format ini?" confirmLabel="Hapus format" danger onCancel={()=>setRemove(false)} onConfirm={()=>{form.setData('parser',{...form.data.parser,rules:form.data.parser.rules.filter((_,index)=>index!==selected)});setSelected(0);setRemove(false);}}><p>{rule?.name} akan dihapus dari perubahan ini. Terapkan dengan Simpan penangkap.</p></ConfirmDialog>
        <Modal open={modal==='test'} title="Uji penangkap QRIS" description="Membaca contoh pesan tanpa mengubah pembayaran atau saldo." onClose={close} footer={<><button className="button secondary" onClick={()=>{if(testingDraft){request.current?.abort();setBusy(false);setModal('edit');}else close();}}>{testingDraft?'Kembali ke format':'Tutup'}</button><button className="button" type="submit" form="qris-parser-test" disabled={busy||!text.trim()}>{busy?'Membaca…':'Uji pesan'}</button></>}>
            <form id="qris-parser-test" className="form-stack" onSubmit={event=>{event.preventDefault();void test();}}>
                <p className="field-help">{testingDraft?'Menguji perubahan, belum disimpan':configured?'Profil tersimpan':'Contoh profil bawaan, belum diterapkan'} · {source}</p>
                <label>Judul notifikasi (opsional)<input disabled={busy} maxLength={500} value={title} onChange={event=>{setTitle(event.target.value);setResult(null);}}/></label>
                <label>Isi notifikasi<textarea disabled={busy} required rows={4} maxLength={4000} value={text} onChange={event=>{setText(event.target.value);setResult(null);}} placeholder="Rp292 diterima DANA Bisnis."/></label>
                {error && <p role="alert">{error}</p>}{busy && <p role="status">Membaca format pesan…</p>}
                {result && <div className={'qris-parser-result '+(result.matched?'is-match':'')} role="status"><strong>{result.matched?<><CheckCircle2 size={18}/>Format cocok</>:'Pesan tidak cocok'}</strong><p>{result.reason}</p>{result.matched && <><dl><div><dt>Nominal</dt><dd>{money(result.amount??0)}</dd></div><div><dt>Format</dt><dd>{result.rule}</dd></div>{Object.entries(result.fields).filter(([key])=>key!=='nominal'&&key!=='abaikan').map(([key,value])=><div key={key}><dt>{key}</dt><dd>{value}</dd></div>)}</dl><p className="field-help">Uji ini belum memeriksa sumber aplikasi, waktu, atau invoice nyata.</p></>}</div>}
            </form>
        </Modal>
    </>;
}
