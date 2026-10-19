import { Link } from '@inertiajs/react';
import { ArrowRight, Crown, ShieldCheck, Trophy } from 'lucide-react';
import { ShopLayout, money, type Shop } from '../../components/Commerce';
import { Pagination, useServerList, type ListFilters, type PageMeta } from '../../components/ListControls';

type Ranking = { name: string; amount: number; purchases: number };
const periods = [['day', 'Hari ini'], ['week', 'Minggu ini'], ['month', 'Bulan ini']];

export default function Leaderboard({ store, ranking, period, pagination, filters }: {
    store: Shop; ranking: Ranking[]; period: string; pagination: PageMeta; filters: ListFilters;
}) {
    const list = useServerList(filters, ['ranking', 'pagination', 'filters', 'period']);
    const selectedPeriod = periods.find(([key]) => key === period)?.[1] ?? 'Bulan ini';

    return <ShopLayout store={store} title="Leaderboard">
        <section className="sf-leaderboard" aria-label="Peringkat pelanggan">
            <header className="sf-leaderboard-heading">
                <div className="sf-leaderboard-heading-copy">
                    <span className="sf-leaderboard-icon"><Trophy size={23} aria-hidden="true" /></span>
                    <div><h2>Pelanggan teratas</h2><p>Apresiasi untuk pelanggan {store.name} berdasarkan total belanja.</p></div>
                </div>
                <nav className="sf-leaderboard-periods" aria-label="Periode leaderboard">
                    {periods.map(([key, label]) => <Link key={key} className={period === key ? 'selected' : ''}
                        aria-current={period === key ? 'page' : undefined} preserveScroll
                        href={(store.preview ? `/preview/storefront?template=${store.template}&page=leaderboard&period=` : '/leaderboard?period=') + key}>{label}</Link>)}
                </nav>
            </header>

            <div className="sf-leaderboard-board" aria-busy={list.busy}>
                <div className="sf-leaderboard-board-heading"><h3>Peringkat <span>{selectedPeriod.toLowerCase()}</span></h3><span>{pagination.total} pelanggan</span></div>
                {ranking.length > 0 ? <>
                    {pagination.last_page > 1 && <div className="sf-leaderboard-pagination"><Pagination compact meta={pagination} busy={list.busy} onPage={list.go} label="Navigasi atas peringkat" /></div>}
                    <div className="sf-leaderboard-columns" aria-hidden="true"><span>Peringkat</span><span>Pelanggan</span><span>Pesanan</span><span>Total belanja</span></div>
                    <ol className="sf-leaderboard-list" start={pagination.from ?? 1}>
                        {ranking.map((item, index) => {
                            const rank = (pagination.from ?? 1) + index;
                            return <li key={`${rank}-${item.name}`} className={rank <= 3 ? `sf-leaderboard-winner place-${rank}` : ''}>
                                <span className="sf-leaderboard-rank" aria-label={`Peringkat ${rank}`}>{rank === 1 ? <Crown size={19} aria-hidden="true" /> : String(rank).padStart(2, '0')}</span>
                                <div className="sf-leaderboard-person"><span className="sf-leaderboard-avatar" aria-hidden="true">{item.name.charAt(0)}</span><div><strong>{item.name}</strong><small>{item.purchases} pesanan berhasil</small></div></div>
                                <span className="sf-leaderboard-orders">{item.purchases} <small>berhasil</small></span>
                                <strong className="sf-leaderboard-amount">{money(item.amount)}</strong>
                            </li>;
                        })}
                    </ol>
                    {pagination.last_page > 1 && <div className="sf-leaderboard-pagination"><Pagination meta={pagination} busy={list.busy} onPage={list.go} /></div>}
                </> : <div className="sf-leaderboard-empty">
                    <span className="sf-leaderboard-icon"><Trophy size={25} aria-hidden="true" /></span>
                    <h3>Belum ada peringkat {selectedPeriod.toLowerCase()}</h3>
                    <p>Belanja dengan akun pelanggan. Pesanan yang berhasil akan masuk ke peringkat ini.</p>
                    <Link href="/">Jelajahi produk <ArrowRight size={15} aria-hidden="true" /></Link>
                </div>}
            </div>
            <footer className="sf-leaderboard-note"><ShieldCheck size={16} aria-hidden="true" /><div><strong>Identitas pelanggan tetap privat</strong><p>Nama ditampilkan sebagai alias. Peringkat hanya menghitung nilai produk dari pesanan berhasil yang sudah dibayar; biaya pembayaran, top up saldo, dan refund tidak dihitung.</p></div></footer>
        </section>
    </ShopLayout>;
}
