import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Activity, ArrowUpRight, Bot, Check, CircleHelp, Clock3, Database, Info, Layers, Plug, RefreshCw, Server, ShieldAlert, Webhook } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import TableAutoRefresh from '../../components/TableAutoRefresh';
import Modal from '../../components/Modal';
import { FilterBar, Pagination, useServerList, type ListFilters, type PageMeta } from '../../components/ListControls';

type Status = 'ok' | 'warning' | 'error' | 'unknown';
type Service = { id: string; label: string; status: Status; message: string; checked_at: string | null; workers: number | null };
type Item = { id: string; label: string; source: string; status: Status; message: string; observed_at: string | null; checked_at?: string; reference?: string; impact?: string; next_steps?: string[]; state?: string | null; count?: number; url: string | null };
type Queue = { connection: string; driver: string; name: string; ready: number | null; reserved: number | null; delayed: number | null; failed: number | null; oldest_at: string | null; unavailable?: boolean; probe_retry_available: boolean; probe_pending: boolean; probe_queued_at: string | null; probe_received_at: string | null; probe_status: Status; probe_message: string | null };
type Props = { overview: { services: Service[]; queue: Queue; issues: number; webhook_requests: number; webhook_errors: number; generated_at: string }; items: Item[]; pagination: PageMeta; filters: ListFilters; tab: string; listingUnavailable: boolean };
const statuses = { ok: 'Sehat', warning: 'Perlu perhatian', error: 'Bermasalah', unknown: 'Belum terverifikasi' };
const icons = { database: Database, cache: Layers, scheduler: Clock3, queue_worker: Activity, bot_manager: Server, hanshop_api: Plug, backup: Database };
const tabs = [ { key: 'bots', label: 'WhatsApp toko', icon: Bot }, { key: 'suppliers', label: 'Pemasok', icon: Plug }, { key: 'webhooks', label: 'Callback', icon: Webhook }, { key: 'queue', label: 'Antrean', icon: Layers }, { key: 'failed', label: 'Job gagal', icon: ShieldAlert } ];
const partials = ['overview', 'items', 'pagination', 'filters', 'tab', 'listingUnavailable'];
const time = (value: string | null | undefined) => value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Asia/Jakarta', hourCycle: 'h23' }).format(new Date(value)) + ' WIB' : 'Belum ada';

function Badge({ status }: { status: Status }) {
    const Icon = status === 'ok' ? Check : status === 'unknown' ? CircleHelp : ShieldAlert;
    return <span className={'health-badge health-' + status}><Icon size={14}/>{statuses[status]}</span>;
}

export default function SystemHealth({ overview, items, pagination, filters, tab, listingUnavailable }: Props) {
    const list = useServerList(filters, partials);
    const refresh = useForm({});
    const retry = useForm({queue: ''});
    const [retryOpen, setRetryOpen] = useState(false);
    const [detail, setDetail] = useState<Item | null>(null);
    const queue = overview.queue;
    const problems = overview.services.filter(service => service.status !== 'ok').length;
    return <WorkspaceLayout role="admin" title="Kesehatan sistem" description="Pantau layanan, antrean, dan koneksi seluruh toko." contentClassName="system-health-page" actions={<button className="button" disabled={refresh.processing} onClick={() => refresh.post('/admin/system-health/refresh', { preserveScroll: true })}><RefreshCw size={17} className={refresh.processing ? 'is-refreshing' : ''}/>{refresh.processing ? 'Memeriksa…' : 'Periksa sekarang'}</button>}>
        <div className="health-toolbar"><span><span className={'health-live-dot' + (problems ? ' needs-attention' : '')}/>{problems ? `${problems} layanan perlu diperiksa` : 'Seluruh layanan inti sehat'}<small>Data ditampilkan {time(overview.generated_at)}</small></span><TableAutoRefresh only={partials} paused={refresh.processing || !!detail || list.busy || retryOpen || retry.processing}/></div>
        <div className="health-service-grid">{overview.services.map(service => {
            const Icon = icons[service.id as keyof typeof icons] ?? Activity;
            return <section className={'health-service health-' + service.status} key={service.id}><header><span className="health-service-icon"><Icon size={20}/></span><h2>{service.label}</h2></header><Badge status={service.status}/><p>{service.message}</p>{service.workers !== null && <small>{service.workers} worker dikelola manager</small>}<footer><Clock3 size={13}/>{time(service.checked_at)}</footer></section>;
        })}</div>
        <section className="health-queue-card"><header><div><h2>Antrean & aktivitas</h2><p>{queue.driver} · {queue.name}</p></div><span className="health-probe"><Activity size={15}/>{queue.probe_pending ? 'Uji antrean menunggu worker' : queue.probe_status === 'ok' ? 'Uji antrean berhasil' : queue.probe_status === 'error' ? 'Uji antrean gagal' : 'Belum ada uji antrean'}</span></header>
            <div className="health-metrics">{[{ label: 'Siap diproses', value: queue.ready }, { label: 'Diambil worker', value: queue.reserved }, { label: 'Terjadwal', value: queue.delayed }, { label: 'Job gagal tersimpan', value: queue.failed }, { label: 'Koneksi perlu perhatian', value: overview.issues }, { label: 'Callback bermasalah · 24 jam', value: Number(overview.webhook_errors) }].map(metric => <div key={metric.label}><strong>{metric.value === null ? '—' : metric.value.toLocaleString('id-ID')}</strong><span>{metric.label}</span></div>)}</div>
            <footer>{queue.unavailable ? <span className="health-alert-text">Statistik antrean belum dapat dibaca. Periksa driver dan layanan penyimpanannya.</span> : <span>Antrean siap tertua: {time(queue.oldest_at)}</span>}<span>Uji terakhir diproses: {time(queue.probe_received_at)}</span></footer>
            {queue.probe_message && <p className="health-alert-text">{queue.probe_message}</p>}
            {queue.probe_retry_available && <button className="button secondary small health-probe-retry" type="button" onClick={() => { retry.clearErrors(); setRetryOpen(true); }}><RefreshCw size={15}/>Uji ulang antrean</button>}
        </section>
        <section className="table-card data-list-table catalog-data-card health-list"><nav className="health-tabs" aria-label="Bagian pemantauan">{tabs.map(({ key, label, icon: Icon }) => <Link key={key} href={'/admin/system-health?tab=' + key} className={'button secondary small' + (tab === key ? ' selected' : '')} aria-current={tab === key ? 'page' : undefined} preserveScroll><Icon size={16}/>{label}</Link>)}</nav>
            <header className="data-table-header"><h2>{tabs.find(item => item.key === tab)?.label}</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy} label="Navigasi atas pemantauan"/></header>
            <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder={tab === 'webhooks' ? 'Cari Digiflazz, Midtrans, OtomaX, atau Forward…' : tab === 'queue' || tab === 'failed' ? 'Cari nama antrean…' : 'Cari nama toko…'} choices={['bots', 'suppliers'].includes(tab) ? [{ key: 'status', label: 'Status', options: statuses }] : []}/>
            {tab === 'webhooks' && <p className="health-list-note">Ringkasan 24 jam · {Number(overview.webhook_requests).toLocaleString('id-ID')} callback. HTTP 200 berarti endpoint menerima permintaan, bukan transaksi sudah sukses.</p>}
            {tab === 'failed' && <p className="health-list-note">Riwayat kegagalan, bukan status layanan saat ini. Buka Rincian untuk melihat penyebab dan langkah pemeriksaan.</p>}
            {tab === 'queue' && queue.driver !== 'database' && <p className="health-list-note">Rincian job tersedia untuk driver database. Driver {queue.driver} menampilkan statistik pada ringkasan antrean.</p>}
            {listingUnavailable && <p className="health-list-note health-alert-text">Daftar belum dapat dibaca. Periksa koneksi penyimpanan antrean.</p>}
            <div className="table-scroll"><table className="health-table"><thead><tr><th>{['bots', 'suppliers'].includes(tab) ? 'Toko' : 'Komponen'}</th><th>Sumber</th><th>Status</th><th>{tab === 'webhooks' ? 'Permintaan' : 'Aktivitas terakhir'}</th><th>Keterangan</th><th>Tindakan</th></tr></thead><tbody>{items.map(item => <tr key={item.id}><td><strong>{item.label}</strong>{item.reference && <small>{item.reference}</small>}</td><td>{item.source}</td><td className="table-cell-center"><Badge status={item.status}/></td><td className="table-cell-center">{tab === 'webhooks' ? <><strong>{item.count?.toLocaleString('id-ID')}</strong><small>{time(item.observed_at)}</small></> : time(item.observed_at)}</td><td><p>{item.message}</p></td><td className="table-cell-center"><button type="button" className="button secondary small" onClick={() => setDetail(item)}><Info size={15}/>Rincian</button></td></tr>)}</tbody></table></div>
            {!items.length && <div className="empty-state"><Activity size={28}/><h3>{listingUnavailable ? 'Data sementara tidak tersedia' : 'Belum ada data yang cocok'}</h3><p>{tab === 'failed' ? 'Job gagal yang tersimpan akan tampil di sini.' : ['bots', 'suppliers'].includes(tab) ? 'Hanya koneksi toko aktif, masa tenggang, dan penyiapan yang dipantau.' : 'Ubah pencarian atau tunggu aktivitas berikutnya.'}</p></div>}
            <Pagination meta={pagination} onPage={list.go} busy={list.busy}/>
        </section>
        <p className="health-footnote"><Info size={16}/>Pemeriksaan otomatis setiap menit. Tombol periksa dan auto refresh tidak membuat heartbeat scheduler atau menjalankan ulang transaksi.</p>
        <Modal open={!!detail} title={detail?.label ?? 'Rincian pemantauan'} description={detail?.source} onClose={() => setDetail(null)} footer={<><button className="button secondary" onClick={() => setDetail(null)}>Tutup</button>{detail?.url && <Link className="button" href={detail.url}>Kelola toko<ArrowUpRight size={16}/></Link>}</>}>
            {detail && <div className="health-detail"><Badge status={detail.status}/><p>{detail.message}</p>{detail.impact && <p className="health-impact">{detail.impact}</p>}{!!detail.next_steps?.length && <section className="health-recovery"><h3>Yang perlu diperiksa</h3><ol>{detail.next_steps.map((step,index) => <li key={index}>{step}</li>)}</ol></section>}<dl>{detail.reference && <div><dt>Nomor catatan</dt><dd>{detail.reference}</dd></div>}<div><dt>Aktivitas terakhir</dt><dd>{time(detail.observed_at)}</dd></div>{detail.checked_at && <div><dt>Terakhir diperiksa</dt><dd>{time(detail.checked_at)}</dd></div>}{detail.state && <div><dt>Koneksi WhatsApp</dt><dd>{detail.state}</dd></div>}{detail.count !== undefined && <div><dt>Jumlah callback · 24 jam</dt><dd>{detail.count}</dd></div>}</dl></div>}
        </Modal>
        <Modal open={retryOpen} title="Uji ulang antrean?" description="Kirim satu job pemeriksaan kesehatan baru." busy={retry.processing} onClose={() => setRetryOpen(false)} footer={<><button className="button secondary" disabled={retry.processing} onClick={() => setRetryOpen(false)}>Batal</button><button className="button" disabled={retry.processing} onClick={() => retry.post('/admin/system-health/queue-probe', {preserveScroll:true,onSuccess:()=>setRetryOpen(false)})}><RefreshCw size={16} className={retry.processing?'is-refreshing':''}/>{retry.processing?'Mengirim…':'Kirim uji antrean'}</button></>}>
            <p>Periksa worker terlebih dahulu, lalu kirim uji antrean baru. Hasil uji lama tidak menimpa uji terbaru.</p><p>Pesanan, pembayaran, dan job bisnis pelanggan tidak dijalankan ulang.</p>{retry.errors.queue && <p className="health-alert-text" role="alert">{retry.errors.queue}</p>}
        </Modal>
    </WorkspaceLayout>;
}
