import { Link } from '@inertiajs/react';
import { BookOpen, ArrowRight } from 'lucide-react';
import { ShopLayout, type Shop } from '../../components/Commerce';
import { ArticleCard, type ArticleSummary } from '../../components/ArticleBody';
import { FilterBar, Pagination, useServerList, type ListFilters, type PageMeta } from '../../components/ListControls';

export default function Articles({store,articles,topics,filters,pagination}:{store:Shop;articles:ArticleSummary[];topics:string[];filters:ListFilters;pagination:PageMeta}) {
    const list=useServerList(filters,['articles','topics','filters','pagination']);
    return <ShopLayout store={store} title="Artikel & berita" showTitle={false}>
        <section className="article-index" data-reveal>
            <header className="article-index-heading"><div><span className="article-eyebrow"><BookOpen size={16}/>JURNAL {store.name}</span><h1>Kabar, tips & inspirasi.</h1><p>Temukan panduan, informasi terbaru, dan cerita pilihan dari {store.name}.</p></div><Link href="/">Jelajahi toko<ArrowRight size={16}/></Link></header>
            {topics.length>0 && <nav className="article-topics" aria-label="Topik artikel"><Link href="/artikel" className={!filters.tag?'selected':''}>Semua topik</Link>{topics.map(topic=><Link key={topic} href={'/artikel?tag='+encodeURIComponent(topic)} className={filters.tag===topic?'selected':''}>#{topic}</Link>)}</nav>}
            <FilterBar storefront filters={filters} onApply={list.apply} placeholder="Cari judul atau topik artikel…" busy={list.busy}/>
            {!!filters.tag && <p className="article-filter-note">Topik: <strong>#{filters.tag}</strong><Link href="/artikel">Hapus filter</Link></p>}
            {pagination.last_page>1 && <Pagination compact meta={pagination} busy={list.busy} onPage={list.go} label="Navigasi atas artikel"/>}
            {articles.length?<div className="article-grid" aria-busy={list.busy}>{articles.map((article,index)=><ArticleCard key={article.id} article={article} featured={index===0 && !filters.q && !filters.tag && pagination.current_page===1}/>)}</div>:<div className="article-empty"><BookOpen size={36}/><h2>{filters.q || filters.tag?'Artikel tidak ditemukan':'Cerita baru sedang disiapkan'}</h2><p>{filters.q || filters.tag?'Coba kata kunci atau topik lainnya.':'Kunjungi kembali halaman ini untuk membaca artikel terbaru dari toko.'}</p><Link href="/">Kembali ke toko<ArrowRight size={15}/></Link></div>}
            {pagination.last_page>1 && <Pagination meta={pagination} busy={list.busy} onPage={list.go}/>}
        </section>
    </ShopLayout>;
}
