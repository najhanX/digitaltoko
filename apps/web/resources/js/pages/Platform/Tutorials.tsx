import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowLeft, ArrowRight, BookOpen, CheckCircle2, ChevronLeft, ChevronRight, Search } from 'lucide-react';
import Brand from '../../components/Brand';
import TutorialCommand from '../../components/TutorialCommand';
import PlatformHeader from '../../components/PlatformHeader';
import Modal from '../../components/Modal';
import TutorialStepImage, { AnnotatedTutorialImage, type TutorialVisual } from '../../components/TutorialStepImage';

type Summary = {slug:string;title:string;category:string;summary:string;keywords?:string[]};
type Guide = Summary & {download?:{label:string;url:string}|null;reviewed?:string;image:string;imageAlt:string;prerequisites:string[];steps:{title:string;paragraphs:string[];commands?:{platform:'windows-cmd'|'windows-powershell'|'macos'|'linux';label:string;code:string}[];visual:TutorialVisual}[];troubleshooting:{question:string;answer:string}[];related:string[];sources:{label:string;url:string}[]};
export default function Tutorials({guides,guide}:{guides:Summary[];guide:Guide|null}) {
    const [terminal,setTerminal] = useState('windows-cmd');
    const [search,setSearch] = useState('');
    const [category,setCategory] = useState('Semua');
    const [page,setPage] = useState(1);
    const [zoom,setZoom] = useState<TutorialVisual|null>(null);
    useEffect(()=>{setZoom(null);},[guide?.slug]);
    const query = search.trim().toLocaleLowerCase('id');
    const filtered = guides.filter(item=>(category==='Semua'||item.category===category)&&[item.title,item.summary,item.category,item.slug,...(item.keywords??[])].join(' ').toLocaleLowerCase('id').includes(query));
    const totalPages = Math.max(1,Math.ceil(filtered.length/9));
    const activePage = Math.min(page,totalPages);
    const related = guide ? guide.related.map(slug=>guides.find(item=>item.slug===slug)).filter((item):item is Summary=>!!item) : [];
    const card = (item:Summary) => <Link href={'/panduan/'+item.slug} className="tutorial-card" key={item.slug}><span className="tutorial-category">{item.category}</span><h2>{item.title}</h2><p>{item.summary}</p><span className="tutorial-read">Baca panduan<ArrowRight size={17}/></span></Link>;
    return <div className="tutorial-site"><Head title={guide?.title ?? 'Pusat panduan'}><meta name="description" content={guide?.summary ?? 'Panduan DigitalToko untuk menghubungkan pemasok, pembayaran, WhatsApp dan mengelola toko.'}/></Head>
        <PlatformHeader/>
        <main className="tutorial-main wrap">
            {!guide ? <><section className="tutorial-hero"><span className="eyebrow"><BookOpen size={17}/>PUSAT PANDUAN</span><h1>Toko siap, langkah demi langkah.</h1><p>Pelajari koneksi pemasok, pembayaran dan pengelolaan toko dengan petunjuk serta gambar antarmuka.</p><label className="tutorial-search"><Search size={21}/><span className="sr-only">Cari panduan</span><input type="search" placeholder="Cari Digiflazz, Midtrans, banner…" value={search} onChange={e=>{setSearch(e.target.value);setPage(1);}}/></label></section>
                <div className="tutorial-filters" role="group" aria-label="Topik panduan">{['Semua',...new Set(guides.map(item=>item.category))].map(name=><button type="button" key={name} aria-pressed={category===name} onClick={()=>{setCategory(name);setPage(1);}}>{name}</button>)}</div>
                {!search && category==='Semua' && <Link className="tutorial-start" href="/panduan/mulai"><span><strong>Baru membuat toko?</strong><small>Mulai dari akun, paket, hingga aktivasi.</small></span><ArrowRight size={24}/></Link>}
                <p className="tutorial-count" role="status">{filtered.length} panduan{search?' untuk “'+search+'”':''}</p><div className="tutorial-grid">{filtered.slice((activePage-1)*9,activePage*9).map(card)}</div>
                {!filtered.length && <div className="tutorial-empty"><BookOpen size={30}/><h2>Panduan belum ditemukan</h2><p>Coba kata lain atau pilih topik Semua.</p><button type="button" className="button secondary" onClick={()=>{setSearch('');setCategory('Semua');setPage(1);}}>Tampilkan semua</button></div>}
                {totalPages>1 && <nav className="tutorial-pagination" aria-label="Halaman panduan"><button className="button secondary small" disabled={activePage===1} onClick={()=>setPage(activePage-1)}><ChevronLeft size={17}/>Sebelumnya</button><span>{activePage} / {totalPages}</span><button className="button secondary small" disabled={activePage===totalPages} onClick={()=>setPage(activePage+1)}>Berikutnya<ChevronRight size={17}/></button></nav>}
            </> : <><Link className="tutorial-back" href="/panduan"><ArrowLeft size={17}/>Semua panduan</Link><header className="tutorial-intro"><span className="tutorial-category">{guide.category}</span><h1>{guide.title}</h1><p>{guide.summary}</p><small>Ditinjau {guide.reviewed ?? '23 Oktober 2026'} · {guide.steps.length} langkah · Panduan owner/staff</small>{guide.download && <p><a className="button" href={guide.download.url}>{guide.download.label}</a></p>}</header>
                <div className="tutorial-document"><aside className="tutorial-contents"><h2>Dalam panduan ini</h2><ol>{guide.steps.map((step,index)=><li key={step.title}><a href={'#langkah-'+(index+1)}>{step.title}</a></li>)}</ol><Link href="/panduan/pengujian">Pelajari mode pengujian<ArrowRight size={15}/></Link></aside>
                <article className="tutorial-body"><section className="tutorial-preparation"><h2>Sebelum mulai</h2><ul>{guide.prerequisites.map(item=><li key={item}><CheckCircle2 size={18}/><span>{item}</span></li>)}</ul>{guide.steps.some(step=>step.commands?.length) && <label className="tutorial-terminal">Pilih komputer dan terminal Anda<select value={terminal} onChange={event=>setTerminal(event.target.value)}><option value="windows-cmd">Windows · Command Prompt (CMD)</option><option value="windows-powershell">Windows · PowerShell</option><option value="macos">macOS · Terminal</option><option value="linux">Linux · Terminal</option></select><small>Pilihan ini menyesuaikan semua perintah pada langkah di bawah.</small></label>}</section>
                    {guide.steps.map((step,index)=><section className="tutorial-step" id={'langkah-'+(index+1)} key={step.title}><h2><span>{index+1}</span>{step.title}</h2>{step.paragraphs.map((text,i)=><p key={i}>{text}</p>)}{step.commands?.filter(command=>command.platform===terminal).map(command=><TutorialCommand key={terminal+command.label} label={command.label} code={command.code}/>)}{step.visual&&<TutorialStepImage visual={step.visual} onZoom={()=>setZoom(step.visual)}/>}</section>)}
                    <section className="tutorial-problems"><h2>Jika menemui kendala</h2>{guide.troubleshooting.map(item=><details key={item.question}><summary>{item.question}</summary><p>{item.answer}</p></details>)}</section>
                    {!!guide.sources.length && <section className="tutorial-sources"><h2>Rujukan resmi</h2>{guide.sources.map(source=><a href={source.url} key={source.url} target="_blank" rel="noopener noreferrer">{source.label}<ArrowRight size={15}/></a>)}</section>}
                    <p className="tutorial-footnote">Tampilan dapat mengikuti pembaruan aplikasi. Gunakan URL dan nilai dari panel toko Anda; jangan menyalin credential atau token dari gambar panduan.</p>
                </article></div><section className="tutorial-related"><h2>Lanjutkan membaca</h2><div className="tutorial-grid">{related.map(card)}</div></section>
                <Modal open={!!zoom} title="Petunjuk langkah" description="Geser gambar bila diperlukan untuk melihat penanda dengan jelas." wide onClose={()=>setZoom(null)}>{zoom&&<div className="tutorial-image-zoom"><AnnotatedTutorialImage visual={zoom}/></div>}</Modal>
            </>}
        </main><footer className="tutorial-footer wrap"><Brand/><Link href="/panduan">Pusat panduan</Link><span>Web toko dan bot WhatsApp, satu pengelolaan.</span></footer>
    </div>;
}
