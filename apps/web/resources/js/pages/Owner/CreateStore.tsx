import { router } from '@inertiajs/react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import CreateStoreModal from '../../components/CreateStoreModal';

export default function CreateStore({platformDomain}:{platformDomain:string}) {
    return <WorkspaceLayout title="Buat toko" description="Tentukan identitas toko, lalu lanjutkan pemilihan paket.">
        <CreateStoreModal open platformDomain={platformDomain} onClose={() => router.get('/owner/stores')}/>
    </WorkspaceLayout>;
}
