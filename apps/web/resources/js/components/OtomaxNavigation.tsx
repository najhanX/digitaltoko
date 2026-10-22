import {Link} from '@inertiajs/react';
export const OTOMAX_FILE_IMPORT_UI_ENABLED = false;
export default function OtomaxNavigation({base,active,permissions}:{base:string;active:string;permissions:{deposit?:boolean;responses?:boolean}}){
 const tabs:Record<string,string>={connections:'Koneksi',responses:'Kelompok jawaban',tester:'Penguji jawaban',profiles:'Profil impor',imports:'Riwayat impor',syncs:'Sinkronisasi harga',tickets:'Tiket deposit',events:'Callback'};
 return <nav className="content-tabs" aria-label="Modul OtomaX">{Object.entries(tabs).filter(([key])=>(OTOMAX_FILE_IMPORT_UI_ENABLED||!['profiles','imports'].includes(key))&&(key!=='tickets'||permissions.deposit)&&(key!=='tester'||permissions.responses)).map(([key,label])=><Link key={key} className={active===key?'selected':''} aria-current={active===key?'page':undefined} href={base+(key==='tester'?'/tester':key==='connections'?'':'?tab='+key)}>{label}</Link>)}</nav>;
}
