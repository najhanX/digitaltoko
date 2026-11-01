import {Link} from '@inertiajs/react';
import {useRef,useState} from 'react';
import {FlaskConical,RefreshCw,ArrowLeft} from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import CatalogSelect from '../../components/CatalogSelect';
import OtomaxNavigation from '../../components/OtomaxNavigation';
import {responseEvents,responseOutcomes,responseFields,responseSegments,type ResponseConfig,type CaptureSpan} from '../../components/otomaxResponseEditor';
type Check={index:number;name:string;outcome:string;event:string;priority:number;eligible:boolean;matched:boolean;fields:Record<string,string>;captures:CaptureSpan[];reason:string;error:string|null;expression:string};
type Result={status:string;fields:Record<string,string>;matched:string[];error:string|null;checks:Check[];message:string};
type Props={role:'owner'|'staff';store:{id:string;name:string};permissions:{deposit:boolean;responses:boolean}};
export default function OtomaxTester({role,store,permissions}:Props){
 const base='/stores/'+store.id+'/otomax';
 const [profile,setProfile]=useState<{id:string;name:string}|null>(null),[config,setConfig]=useState<ResponseConfig|null>(null),[version,setVersion]=useState(0),[event,setEvent]=useState('callback'),[text,setText]=useState(''),[query,setQuery]=useState(''),[result,setResult]=useState<Result|null>(null),[error,setError]=useState(''),[loading,setLoading]=useState(false),[busy,setBusy]=useState(false),[highlight,setHighlight]=useState(0);
 const requestVersion=useRef(0),profileRequest=useRef(0);
 async function loadProfile(value:{id:string;name:string}){
  const revision=++profileRequest.current;requestVersion.current++;setProfile(value);setConfig(null);setResult(null);setError('');setLoading(true);
  try{const response=await fetch(base+'/profiles/'+value.id,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await response.json();if(revision!==profileRequest.current)return;if(!response.ok)throw new Error(data.message??'Kelompok belum dapat dimuat.');setConfig(data.config);setVersion(data.version);setEvent(data.config.rules[0]?.event??'callback');}
  catch(e){if(revision===profileRequest.current)setError((e as Error).message);}finally{if(revision===profileRequest.current)setLoading(false);}
 }
 async function testResponse(){
  if(!config||busy)return;const revision=requestVersion.current;setBusy(true);setResult(null);setError('');
  try{const cookie=document.cookie.split('; ').find(value=>value.startsWith('XSRF-TOKEN='));const response=await fetch(base+'/test',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':cookie?decodeURIComponent(cookie.slice(11)):''},body:JSON.stringify({config,event,text,query:Object.fromEntries(new URLSearchParams(query))})});const data=await response.json();if(revision!==requestVersion.current)return;if(!response.ok)throw new Error(Object.values(data.errors??{}).flat().join(' ')||data.message||'Jawaban belum dapat diuji.');setResult(data);setHighlight(Math.max(0,data.checks.findIndex((check:Check)=>check.matched)));}
  catch(e){if(revision===requestVersion.current)setError((e as Error).message);}finally{setBusy(false);}
 }
 function invalidate(){requestVersion.current++;setResult(null);setError('');}
 const activeCheck=result?.checks[highlight];
 const sources=new Set(result?.checks.filter(check=>check.matched).flatMap(check=>Object.keys(check.fields))??[]);
 return <WorkspaceLayout role={role} title="Penguji jawaban supplier" description={'Uji penangkap '+store.name+' tanpa membuat atau mengubah transaksi.'} contentClassName="catalog-workspace otomax-page" actions={<Link className="button secondary" href={base+'?tab=responses'}><ArrowLeft size={16}/>Kelompok jawaban</Link>}>
  <OtomaxNavigation base={base} active="tester" permissions={permissions}/>
  <div className="otomax-tester-layout">
   <section className="card otomax-tester-input"><header><h2>Jawaban supplier</h2><p className="otomax-editor-hint">Pilih kelompok tersimpan, lalu tempel balasan aslinya.</p></header>
    <CatalogSelect label="Kelompok jawaban" value={profile} onChange={value=>void loadProfile(value)} url={base+'/options?type=response'}/>
    {loading&&<p role="status">Memuat penangkap…</p>}
    {config&&<div className="otomax-tester-profile"><span>Versi {version} · {config.rules.length} penangkap</span><button className="button secondary small" disabled={loading||busy} onClick={()=>{if(profile)void loadProfile(profile);}}><RefreshCw size={14}/>Muat ulang</button></div>}
    <label>Jenis jawaban<select disabled={loading||busy} value={event} onChange={e=>{setEvent(e.target.value);invalidate();}}>{Object.entries(responseEvents).map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></label>
    <label>Teks jawaban asli<textarea rows={8} maxLength={20000} spellCheck={false} value={text} onChange={e=>{setText(e.target.value);invalidate();}} placeholder="#264447712 CEKGOJEK.08123456789 SUKSES. SN/Ref: …"/></label>
    <details className="otomax-details"><summary>Query callback (opsional)</summary><label>Parameter yang diterima<input placeholder="refid=TR12342&status=20&price=5" value={query} onChange={e=>{setQuery(e.target.value);invalidate();}}/></label><p className="field-help">Isi hanya jika supplier mengirim parameter terpisah dari pesan.</p></details>
    {error&&<p className="feedback error" role="alert">{error}</p>}
    <button className="button" disabled={!config||loading||busy||!text.trim()} onClick={()=>void testResponse()}><FlaskConical size={17}/>{busy?'Menguji…':'Uji penangkap'}</button>
   </section>
   <section className="card otomax-tester-result" aria-live="polite"><header><h2>Hasil pengujian</h2></header>
    {!result?<div className="empty-state"><FlaskConical size={28}/><h3>{busy?'Menguji jawaban…':'Hasil akan tampil di sini'}</h3><p>Nilai tangkapan, status dan penangkap yang cocok ditampilkan setelah pengujian.</p></div>:<>
     <div className={'otomax-test-verdict '+(result.error?'warning':'success')}><strong>{result.error?'Perlu diperiksa':result.matched.length?'Jawaban dikenali':'Belum dikenali'}</strong><span>Hasil transaksi: {responseOutcomes[result.status]??result.status}</span>{result.error&&<p>{result.error}</p>}{!result.error&&!result.matched.length&&<p>Belum ada penangkap teks yang cocok. Transaksi tetap pending; kode query tidak menentukan status sendiri.</p>}</div>
     <label>Sorotan penangkap<select value={highlight} onChange={e=>setHighlight(Number(e.target.value))}>{result.checks.map((check,index)=><option value={index} key={index}>{check.name} · {check.matched?'Cocok':check.eligible?'Tidak cocok':'Dilewati'}</option>)}</select></label>
     {activeCheck&&<><p className="otomax-editor-hint">{activeCheck.error??activeCheck.reason}</p><pre className="otomax-highlight">{responseSegments(result.message,activeCheck.captures).map((segment,index)=>segment.field?<mark key={index} title={responseFields[segment.field]??segment.field}>{segment.text}</mark>:<span key={index}>{segment.text}</span>)}</pre></>}
     <div className="table-scroll otomax-result-fields"><table><thead><tr><th>Data</th><th>Nilai tangkapan</th><th>Sumber</th></tr></thead><tbody>{Object.entries(result.fields).map(([field,value])=><tr key={field}><td><strong>{responseFields[field]??field}</strong></td><td><code>{value}</code></td><td>{sources.has(field)?'Teks':'Query callback'}</td></tr>)}</tbody></table>{!Object.keys(result.fields).length&&<p className="otomax-editor-hint">Belum ada nilai akhir yang dapat digunakan. Periksa rincian penangkap di bawah.</p>}</div>
     <details className="otomax-details"><summary>Rincian pemeriksaan ({result.checks.length} penangkap)</summary><div className="otomax-check-list">{result.checks.map((check,index)=><div key={index}><strong>{check.name}</strong><span>{check.matched?'Cocok':check.eligible?'Tidak cocok':'Dilewati'} · prioritas {check.priority}</span><p>{check.error??check.reason}</p></div>)}</div></details>
    </>}
   </section>
  </div>
 </WorkspaceLayout>;
}
