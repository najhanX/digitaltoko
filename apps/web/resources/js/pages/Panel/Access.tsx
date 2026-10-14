import { ShieldCheck } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';

export default function Access({storeName}:{storeName:string}) {
    return <WorkspaceLayout role="staff" title="Akses fitur toko" description={'Anda masuk sebagai staff '+storeName+'.'}><section className="card empty-state"><ShieldCheck size={36}/><h3>Hak akses perlu dilengkapi</h3><p>Belum ada halaman yang dapat dibuka dengan hak akses saat ini. Hubungi owner toko untuk meminta akses ringkasan, langganan, atau pengaturan sesuai tugas Anda.</p></section></WorkspaceLayout>;
}
