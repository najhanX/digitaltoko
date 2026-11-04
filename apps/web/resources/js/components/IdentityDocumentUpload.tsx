import {useEffect,useId,useRef,useState} from 'react';
import {CheckCircle2,ImagePlus,UploadCloud,X} from 'lucide-react';

export default function IdentityDocumentUpload({file,onChange,storedUrl,readOnly=false,busy=false,progress,error}:{file?:File|null;onChange?:(file:File|null)=>void;storedUrl?:string;readOnly?:boolean;busy?:boolean;progress?:number|null;error?:string}) {
    const input=useRef<HTMLInputElement>(null);
    const helpId=useId();
    const [preview,setPreview]=useState('');
    const [localError,setLocalError]=useState('');
    const [imageFailed,setImageFailed]=useState(false);
    const [dragging,setDragging]=useState(false);
    useEffect(()=>{
        if(!file){setPreview('');return;}
        const url=URL.createObjectURL(file);setPreview(url);
        return()=>URL.revokeObjectURL(url);
    },[file]);
    const image=preview||storedUrl;
    useEffect(()=>setImageFailed(false),[image]);
    function choose(next:File|undefined){
        if(!next||busy||readOnly)return;
        if(!['image/jpeg','image/png'].includes(next.type)){setLocalError('Gunakan foto JPG atau PNG.');return;}
        if(next.size>5*1024*1024){setLocalError('Ukuran foto maksimal 5 MB.');return;}
        setLocalError('');onChange?.(next);
    }
    const status=busy?'Mengunggah foto KTP…':file?'Foto dipilih · siap dikirim':storedUrl?'Foto KTP sudah tersimpan':'Unggah foto KTP';
    return <div className="identity-upload-field"><span className="identity-upload-label">Foto KTP</span><div className={'identity-upload'+(dragging?' is-dragging':'')+(image?' has-image':'')} aria-busy={busy} onDragOver={event=>{event.preventDefault();if(!busy&&!readOnly)setDragging(true);}} onDragLeave={()=>setDragging(false)} onDrop={event=>{event.preventDefault();setDragging(false);choose(event.dataTransfer.files[0]);}}>
        <input ref={input} className="identity-upload-input" type="file" aria-label="Pilih foto KTP" aria-describedby={helpId} accept="image/jpeg,image/png" disabled={busy||readOnly} onChange={event=>{choose(event.target.files?.[0]);event.target.value='';}}/>
        <div className="identity-upload-main">{image&&!imageFailed?<img className="identity-upload-preview" src={image} alt={file?'Pratinjau foto KTP yang dipilih':'Foto KTP pengajuan tersimpan'} onError={()=>setImageFailed(true)}/>:<span className="identity-upload-icon"><ImagePlus size={28}/></span>}<div className="identity-upload-copy"><strong role="status">{(file||storedUrl)&&!busy&&<CheckCircle2 size={16}/>} {status}</strong>{file?<span className="identity-upload-filename">{file.name} · {file.size<1024*1024?Math.max(1,Math.ceil(file.size/1024))+' KB':(file.size/1024/1024).toLocaleString('id-ID',{maximumFractionDigits:2})+' MB'}</span>:<span>{storedUrl?'Dokumen hanya dapat diakses Anda dan administrator.':'Pilih foto atau tarik ke area ini.'}</span>}{imageFailed&&<small>Pratinjau belum dapat ditampilkan. Coba pilih ulang atau buka dokumen tersimpan.</small>}</div></div>
        {!readOnly&&<div className="identity-upload-actions"><button type="button" className="button secondary small" disabled={busy} onClick={()=>input.current?.click()}><UploadCloud size={17}/>{file?'Ganti foto':storedUrl?'Pilih foto pengganti':'Pilih foto KTP'}</button>{file&&<button type="button" className="icon-button" disabled={busy} aria-label="Hapus foto yang dipilih" onClick={()=>{setLocalError('');onChange?.(null);}}><X size={18}/></button>}</div>}
        {busy&&<div className="identity-upload-progress" role="progressbar" aria-label="Progres unggah KTP" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress??undefined}><span style={{width:Math.max(0,Math.min(100,progress??0))+'%'}}/><small>{progress!=null?`${progress}% · `:''}Sedang mengirim pengajuan…</small></div>}
        {readOnly&&storedUrl&&<a className="button secondary small" href={storedUrl} target="_blank" rel="noopener noreferrer">Buka foto KTP</a>}
    </div>{!readOnly&&<small id={helpId} className="identity-upload-help">JPG/PNG, maksimal 5 MB. Foto baru tersimpan setelah pengajuan berhasil dikirim.</small>}{(localError||error)&&<p className="form-error" role="alert">{localError||error}</p>}</div>;
}
