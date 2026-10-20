import type { StoreBranding } from '../../components/storefrontDesign';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Plus, Pencil, Trash2, ExternalLink, Users, ShieldCheck, UserCheck, LayoutTemplate, Store as StoreIcon, FlaskConical } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import TemplatePicker from '../../components/TemplatePicker';
import StoreDesignPanel from '../../components/StoreDesignPanel';
import type { ShopDesign, DesignProduct } from '../../components/storefrontDesign';
import ConfirmDialog from '../../components/ConfirmDialog';
import Modal from '../../components/Modal';
import { FormErrors } from '../../components/CatalogForms';
import {categories, storeStatuses} from '../../components/StoreJourney';


type Staff = {id:number;name:string;username:string;email:string;permissions:string[]|null;active:boolean};
type Store = {sandbox:boolean;id:string;name:string;slug:string;category:string;plan:string;template:string;status:string;url:string;expires_at:string|null;is_trial:boolean;branding:StoreBranding|null};
const permissionGroups = [
    {label:'Ringkasan & produk',keys:['dashboard.view','products.view','products.manage','pricing.manage']},
    {label:'Stok premium',keys:['stock.view','stock.manage','stock.credentials']},
    {label:'Pesanan & pelanggan',keys:['orders.view','orders.resend','guests.resolve','customers.view','customers.contacts','customers.manage']},
    {label:'Pembayaran & saldo',keys:['payments.view','payments.policy','wallet.view','wallet.settlement','suppliers.balance']},
    {label:'Toko & langganan',keys:['web.manage','articles.view','articles.manage','checkout.manage','bot.view','bot.manage','notifications.view','notifications.manage','subscription.view','subscription.renew']},
];
export default function ManageStore({role = 'owner',store,staff,permissionLabels,design,designRevision,designProducts}:{role?:'owner'|'staff';store:Store;staff:Staff[];permissionLabels:Record<string,string>;design:ShopDesign;designRevision:number;designProducts:DesignProduct[]}) {
    const requestedTab=new URLSearchParams(usePage().url.split('?')[1] ?? '').get('tab');
    const tab=requestedTab==='staff' && role==='owner'?'staff':requestedTab==='design' && store.category!=='bot'?'design':'settings';
    const base = role === 'owner'?'/owner/stores/'+store.id+'/manage':'/staff/'+store.id+'/settings';
    const aliases:Record<string,string> = {minimal:'aurora',promo:'nebula',category:'atlas'};
    const setting = useForm({name:store.name,template:aliases[store.template] ?? store.template});
    const member = useForm({name:'',username:'',email:'',password:'',active:true,permissions:['dashboard.view'] as string[]});
    const deletion = useForm({});
    const environment = useForm({sandbox:store.sandbox});
    const [environmentOpen,setEnvironmentOpen]=useState(false);
    const [editing,setEditing] = useState<Staff|null>(null);
    const [creating,setCreating] = useState(false);
    const [removing,setRemoving] = useState<Staff|null>(null);
    const [staffEditorTab,setStaffEditorTab]=useState<'account'|'permissions'>('account');
    const staffFormId = useId();
    const settingsFormId = useId();
    const [settingsOpen,setSettingsOpen] = useState(false);
    const [settingsSection,setSettingsSection]=useState<'identity'|'template'>('identity');
    function openSettings(section:'identity'|'template'='identity') {setSettingsSection(section);setting.clearErrors();setting.setData({name:store.name,template:aliases[store.template] ?? store.template});setSettingsOpen(true);}
    function togglePermission(key:string,checked:boolean) {
        let next = checked?[...member.data.permissions,key]:member.data.permissions.filter(value => value !== key);
        if (key === 'subscription.renew' && checked && !next.includes('subscription.view')) next.push('subscription.view');
        if (key === 'subscription.view' && !checked) next = next.filter(value => value !== 'subscription.renew');
        if (key === 'bot.manage' && checked && !next.includes('bot.view')) next.push('bot.view');
        if (key === 'bot.view' && !checked) next = next.filter(value => value !== 'bot.manage');
        member.setData('permissions',next);
    }
    function reset() {setStaffEditorTab('account');setEditing(null);setCreating(false);member.reset();member.clearErrors();}
    function edit(item:Staff,editorTab:'account'|'permissions'='account') {setStaffEditorTab(editorTab);setEditing(item);setCreating(false);member.clearErrors();member.setData({name:item.name,username:item.username,email:item.email,password:'',active:item.active,permissions:item.permissions ?? []});}
    return <WorkspaceLayout role={role} title={tab==='design'?'Desain web':tab === 'staff'?'Staff & hak akses':'Tampilan & pengaturan'} description={tab === 'staff'?'Akun staff hanya berlaku di '+store.name+'. Maksimal lima akun per toko.':'Atur identitas dan tampilan '+store.name+'.'}
        actions={tab==='staff'?<div className="row-actions"><a className="button secondary" href={'/staff/'+store.id+'/login'} target="_blank" rel="noreferrer"><ExternalLink size={15}/>Login staff</a><button type="button" className="button" disabled={staff.length>=5} onClick={()=>{reset();setCreating(true);}}><Plus size={16}/>Tambah staff</button></div>:tab==='settings' && store.category!=='bot'?<a className="button secondary" href={store.url} target="_blank" rel="noreferrer"><ExternalLink size={15}/>Lihat toko</a>:undefined}>
        <div className="store-context-strip"><strong>{store.name}</strong><span>{categories[store.category]}</span><span className={'store-status '+store.status}>{storeStatuses[store.status] ?? store.status}</span></div>
        {tab!=='staff' && store.category!=='bot' && <nav className="content-tabs" aria-label="Tampilan toko"><Link href={base} preserveScroll className={tab==='settings'?'selected':''} aria-current={tab==='settings'?'page':undefined}>Pengaturan toko</Link><Link href={base+'?tab=design'} preserveScroll className={tab==='design'?'selected':''} aria-current={tab==='design'?'page':undefined}>Desain web</Link></nav>}
        {tab==='design'?<StoreDesignPanel store={store} design={design} revision={designRevision} products={designProducts}/>:tab === 'settings'?<div className="store-settings-grid">
            <section className="card onboarding-card store-settings-identity"><header className="store-settings-card-heading"><div className="store-settings-card-title"><StoreIcon size={18}/><h2>Identitas toko</h2></div><button type="button" className="button secondary small" onClick={()=>openSettings('identity')}><Pencil size={14}/>Edit identitas</button></header><dl className="store-settings-details"><div><dt>Nama toko</dt><dd>{store.name}</dd></div><div><dt>Subdomain</dt><dd>{store.slug}<span className="store-settings-fixed">Tetap</span></dd></div>{store.category!=='bot' && <div><dt>Alamat toko</dt><dd><a href={store.url} target="_blank" rel="noreferrer">{store.url.replace(/^https?:\/\//,'').replace(/\/$/,'')}<ExternalLink size={12}/></a></dd></div>}</dl></section>
            {store.category!=='bot' && <section className="card onboarding-card store-settings-template"><header className="store-settings-card-heading"><div className="store-settings-card-title"><LayoutTemplate size={18}/><h2>Template web</h2></div><button type="button" className="button secondary small" onClick={()=>openSettings('template')}>Ganti template</button></header><div className="store-settings-template-preview"><div className={'template-mini '+(aliases[store.template] ?? store.template)} aria-hidden="true"><span className="mini-top"/><span className="mini-banner"/><span className="mini-products"><i/><i/><i/></span></div><div><strong>{({aurora:'Aurora',nebula:'Nebula',atlas:'Atlas'} as Record<string,string>)[aliases[store.template] ?? store.template]}</strong><p>{({aurora:'Produk digital & pesanan cepat',nebula:'Topup game & voucher',atlas:'Katalog bergaya marketplace'} as Record<string,string>)[aliases[store.template] ?? store.template]}</p><Link className="text-link" href={base+'?tab=design'}>Sesuaikan desain<ExternalLink size={12}/></Link></div></div></section>}
            <section className="card onboarding-card store-settings-mode"><div className="store-settings-mode-copy"><div className="store-settings-card-title"><FlaskConical size={18}/><h2>Mode transaksi</h2><span className={'store-status '+(store.sandbox?'grace':'active')}>{store.sandbox?'Pengujian':'Produksi'}</span></div><p>{store.sandbox?'Transaksi memakai lingkungan uji.':'Pembayaran dan pesanan menggunakan layanan asli.'}</p></div>{role==='owner' && <button type="button" className="button secondary small" onClick={()=>{environment.clearErrors();environment.setData('sandbox',store.sandbox);setEnvironmentOpen(true);}}>Ubah mode</button>}</section>
        </div>:<>
            <div className="staff-management">
                <div className="staff-overview" aria-label="Ringkasan staff toko">
                    <div><Users size={19}/><span><small>Akun staff</small><strong>{staff.length}<em> / 5</em></strong></span></div>
                    <div><UserCheck size={19}/><span><small>Staff aktif</small><strong>{staff.filter(item=>item.active).length}</strong></span></div>
                    <div><ShieldCheck size={19}/><span><small>Slot tersedia</small><strong>{Math.max(0,5-staff.length)}</strong></span></div>
                </div>
                <section className="card onboarding-card staff-section staff-management-list">
                    <div className="staff-list-heading"><div><h2>Daftar staff</h2><p>Kelola akun dan akses staff untuk toko ini.</p></div><span className="pill">{staff.length} akun</span></div>
                    {staff.length===0?<div className="empty-state"><Users size={30}/><h3>Belum ada staff</h3><p>Tambahkan staff untuk membantu mengelola toko Anda.</p></div>:<div className="staff-table"><table><thead><tr><th>Staff</th><th>Kontak</th><th>Status</th><th>Hak akses</th><th>Tindakan</th></tr></thead><tbody>{staff.map(item=><tr key={item.id}>
                        <td><div className="staff-member"><span className="staff-avatar" aria-hidden="true">{item.name.trim().split(/\s+/).slice(0,2).map(word=>word[0]).join('').toUpperCase()}</span><div><strong>{item.name}</strong><small>@{item.username}</small></div></div></td>
                        <td className="table-cell-center staff-contact">{item.email}</td>
                        <td className="table-cell-center"><span className={'store-status '+(item.active?'active':'expired')}>{item.active?'Aktif':'Nonaktif'}</span></td>
                        <td className="table-cell-center"><button type="button" className="staff-permission-count" aria-label={'Atur hak akses '+item.name} onClick={()=>edit(item,'permissions')}><ShieldCheck size={14}/>{item.permissions?.length ?? 0} izin</button></td>
                        <td className="table-cell-center"><div className="row-actions"><button type="button" className="button secondary small" onClick={()=>edit(item)}><Pencil size={14}/>Edit</button><button type="button" className="icon-button danger-text" aria-label={'Hapus staff '+item.name} onClick={()=>setRemoving(item)}><Trash2 size={16}/></button></div></td>
                    </tr>)}</tbody></table></div>}
                    <p className="staff-limit-note">Owner tetap memiliki akses penuh. Akun nonaktif tetap dihitung dalam batas 5 staff.</p>
                </section>
            </div>
            <Modal open={creating || !!editing} title={editing?'Edit staff':'Tambah staff'} description={editing?'Perbarui akun dan akses '+editing.name+'.':'Buat akun staff khusus untuk '+store.name+'.'} wide busy={member.processing} onClose={reset} footer={<><span className="staff-modal-footnote">{member.data.permissions.length} izin dipilih</span><button type="button" className="button secondary" disabled={member.processing} onClick={reset}>Batal</button><button type="submit" form={staffFormId} className="button" disabled={member.processing}>{member.processing?'Menyimpan…':editing?'Simpan perubahan':'Tambah staff'}</button></>}>
                <form id={staffFormId} className="staff-editor" onInvalidCapture={event=>{if(staffEditorTab==='permissions'){event.preventDefault();if(event.currentTarget.querySelector('input:invalid')===event.target){setStaffEditorTab('account');requestAnimationFrame(()=>document.getElementById(staffFormId)?.querySelector<HTMLInputElement>('input:invalid')?.reportValidity());}}}} onSubmit={event=>{event.preventDefault();if(member.processing)return;const url='/owner/stores/'+store.id+'/staff'+(editing?'/'+editing.id:'');const options={preserveScroll:true,onSuccess:reset,onError:(errors:Record<string,string>)=>setStaffEditorTab(Object.keys(errors).some(key=>key.startsWith('permissions'))?'permissions':'account')};if(editing)member.patch(url,options);else member.post(url,options);}}>
                    <FormErrors errors={member.errors}/>
                    <div className="modal-editor-tabs" role="group" aria-label="Pengaturan staff"><button type="button" className={staffEditorTab==='account'?'selected':''} aria-pressed={staffEditorTab==='account'} onClick={()=>setStaffEditorTab('account')}>Data akun</button><button type="button" className={staffEditorTab==='permissions'?'selected':''} aria-pressed={staffEditorTab==='permissions'} onClick={()=>setStaffEditorTab('permissions')}>Hak akses <span>{member.data.permissions.length}</span></button></div>
                    <div hidden={staffEditorTab!=='account'}>
                        <div className="staff-editor-heading"><h3>Identitas & login</h3><p>Staff masuk menggunakan username atau email dan password.</p></div>
                        <div className="field-grid"><label>Nama staff<input value={member.data.name} onChange={event=>member.setData('name',event.target.value)} required maxLength={100}/></label><label>Username<input value={member.data.username} onChange={event=>member.setData('username',event.target.value)} required pattern="[a-zA-Z0-9_.-]{3,40}" autoComplete="off"/><small>3–40 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.</small></label><label>Email<input type="email" value={member.data.email} onChange={event=>member.setData('email',event.target.value)} required/></label><label>{editing?'Password baru (opsional)':'Password'}<input type="password" value={member.data.password} onChange={event=>member.setData('password',event.target.value)} minLength={12} maxLength={128} required={!editing} autoComplete="new-password"/><small>{editing?'Kosongkan untuk mempertahankan password saat ini.':'Gunakan minimal 12 karakter.'}</small></label></div>
                        <label className="checkbox-label staff-account-toggle"><input type="checkbox" checked={member.data.active} onChange={event=>member.setData('active',event.target.checked)}/><span><strong>Akun aktif</strong><small>Izinkan staff login dan mengakses toko sesuai izinnya.</small></span></label>
                    </div>
                    <div hidden={staffEditorTab!=='permissions'}>
                        <div className="staff-editor-heading"><h3>Hak akses staff</h3><p>Buka kelompok untuk memilih akses yang diperlukan. Perpanjangan hanya menambah masa aktif; tidak mengubah paket atau mengaktifkan toko pertama kali.</p></div>
                        <div className="staff-permission-groups">{permissionGroups.map(group=>{const keys=group.keys.filter(key=>permissionLabels[key]),selected=keys.filter(key=>member.data.permissions.includes(key)).length;return <details name={staffFormId+'-permission-group'} key={group.label}><summary><span>{group.label}</span><small>{selected}/{keys.length}</small></summary><div>{keys.map(key=><label className="checkbox-label" key={key}><input type="checkbox" checked={member.data.permissions.includes(key)} onChange={event=>togglePermission(key,event.target.checked)}/><span>{permissionLabels[key]}</span></label>)}</div></details>;})}</div>
                    </div>
                </form>
            </Modal>
        </>}
        <Modal open={settingsOpen} title={settingsSection==='template'?'Pilih template web':'Edit identitas toko'} wide={settingsSection==='template'} busy={setting.processing} onClose={()=>setSettingsOpen(false)} footer={<><button type="button" className="button secondary" disabled={setting.processing} onClick={()=>setSettingsOpen(false)}>Batal</button><button type="submit" form={settingsFormId} className="button" disabled={setting.processing}>{setting.processing?'Menyimpan…':'Simpan perubahan'}</button></>}><form id={settingsFormId} onSubmit={event=>{event.preventDefault();if(setting.processing)return;setting.patch(base,{preserveScroll:true,onSuccess:()=>setSettingsOpen(false)});}}>{settingsSection==='identity'?<><label>Nama toko<input value={setting.data.name} onChange={event=>setting.setData('name',event.target.value)} required maxLength={100}/></label><label>Subdomain<input value={store.slug} readOnly aria-readonly="true"/><small>Subdomain ditetapkan saat toko dibuat.</small></label></>:<TemplatePicker value={setting.data.template} onChange={value=>setting.setData('template',value)}/>}<FormErrors errors={setting.errors}/></form></Modal>
        <Modal open={environmentOpen} title="Mode toko" busy={environment.processing} onClose={()=>setEnvironmentOpen(false)} footer={<><button type="button" className="button secondary" disabled={environment.processing} onClick={()=>setEnvironmentOpen(false)}>Batal</button><button type="submit" form="store-environment" className="button" disabled={environment.processing}>{environment.processing?'Menyimpan…':'Simpan mode toko'}</button></>}><form id="store-environment" className="form-stack" onSubmit={event=>{event.preventDefault();environment.patch('/owner/stores/'+store.id+'/environment',{preserveScroll:true,onSuccess:()=>setEnvironmentOpen(false)});}}><label>Lingkungan<select value={environment.data.sandbox?'sandbox':'production'} onChange={event=>environment.setData('sandbox',event.target.value==='sandbox')}><option value="sandbox">Sandbox · Pengujian</option><option value="production">Produksi</option></select></label><p>Pengujian memakai key development Digiflazz, saldo uji, dan simulasi produk. Pembayaran melalui simulasi lokal atau Midtrans Sandbox.</p><small className="field-help">Selesaikan pesanan berjalan sebelum berganti mode. Setelah berganti, sinkronkan ulang sumber produk.</small>{Object.values(environment.errors).map(message=><p className="form-error" role="alert" key={message}>{message}</p>)}</form></Modal>
        <ConfirmDialog open={!!removing} title="Hapus akun staff?" confirmLabel="Ya, hapus staff" busy={deletion.processing} danger onCancel={() => setRemoving(null)} onConfirm={() => {if(removing)deletion.delete('/owner/stores/'+store.id+'/staff/'+removing.id,{onSuccess:()=>setRemoving(null)});}}><p>{removing?.name} tidak dapat login lagi ke toko ini setelah akunnya dihapus.</p>{Object.values(deletion.errors as Record<string,string>).map(message => <p className="form-error" role="alert" key={message}>{message}</p>)}</ConfirmDialog>
    </WorkspaceLayout>;
}
