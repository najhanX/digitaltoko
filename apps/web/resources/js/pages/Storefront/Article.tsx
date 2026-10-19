import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, ChevronRight, Copy, Check, Mail, Facebook, Linkedin, MessageCircle } from 'lucide-react';
import { ShopLayout, type Shop } from '../../components/Commerce';
import ArticleBody, { ArticleCard, ArticleMeta, ArticleTags, type ArticleNode, type ArticleSummary } from '../../components/ArticleBody';

function ShareButtons({url,title}:{url:string;title:string}) {
    const [copied,setCopied]=useState(false),[error,setError]=useState(false);
    const encoded=encodeURIComponent(url),text=encodeURIComponent(title);
    return <div className="article-sharing"><span>Bagikan</span><a href={'https://wa.me/?text='+text+'%20'+encoded} target="_blank" rel="noopener noreferrer" aria-label="Bagikan ke WhatsApp"><MessageCircle size={17}/></a><a href={'https://www.facebook.com/sharer/sharer.php?u='+encoded} target="_blank" rel="noopener noreferrer" aria-label="Bagikan ke Facebook"><Facebook size={17}/></a><a href={'https://twitter.com/intent/tweet?url='+encoded+'&text='+text} target="_blank" rel="noopener noreferrer" aria-label="Bagikan ke X">𝕏</a><a href={'https://www.linkedin.com/sharing/share-offsite/?url='+encoded} target="_blank" rel="noopener noreferrer" aria-label="Bagikan ke LinkedIn"><Linkedin size={17}/></a><a href={'mailto:?subject='+text+'&body='+encoded} aria-label="Bagikan melalui email"><Mail size={17}/></a><button type="button" aria-label="Salin tautan artikel" onClick={async()=>{try{await navigator.clipboard.writeText(url);setCopied(true);setError(false);}catch{setError(true);}}}>{copied?<Check size={17}/>:<Copy size={17}/>}</button><small role="status">{copied?'Tautan disalin':error?'Salin alamat dari bilah browser':''}</small></div>;
}
export default function Article({store,article,related,shareUrl}:{store:Shop;article:ArticleSummary & {content:ArticleNode};related:ArticleSummary[];shareUrl:string}) {
    return <ShopLayout store={store} title={article.title} showTitle={false}>
        <Head><meta name="description" content={article.excerpt ?? article.title}/><meta property="og:title" content={article.title}/><meta property="og:description" content={article.excerpt ?? ''}/><meta property="og:type" content="article"/><meta property="og:url" content={shareUrl}/>{article.cover && <meta property="og:image" content={new URL(article.cover,shareUrl).href}/>}<link rel="canonical" href={shareUrl}/></Head>
        <nav className="article-breadcrumb" aria-label="Jejak halaman"><Link href="/">Beranda</Link><ChevronRight size={14}/><Link href="/artikel">Artikel</Link><ChevronRight size={14}/><span>{article.title}</span></nav>
        <article className="article-detail" data-reveal>
            <header className="article-detail-heading"><ArticleTags tags={article.tags.slice(0,3)}/><h1>{article.title}</h1><ArticleMeta article={article}/><ShareButtons url={shareUrl} title={article.title}/></header>
            {article.cover && <figure className="article-hero-image"><img src={article.cover} alt={article.title} width={1200} height={675} fetchPriority="high"/></figure>}
            <div className="article-reading-main"><ArticleBody content={article.content}/><footer className="article-reading-footer"><span className="article-eyebrow">TOPIK ARTIKEL</span><ArticleTags tags={article.tags}/><ShareButtons url={shareUrl} title={article.title}/></footer></div>
        </article>
        {related.length>0 && <section className="article-related" data-reveal><header><div><span className="article-eyebrow">LANJUTKAN MEMBACA</span><h2>Artikel lainnya</h2></div><Link href="/artikel">Semua artikel<ArrowRight size={16}/></Link></header><div className="article-grid">{related.map(item=><ArticleCard key={item.id} article={item}/>)}</div></section>}
    </ShopLayout>;
}
