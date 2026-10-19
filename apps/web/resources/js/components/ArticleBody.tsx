import { createElement, type CSSProperties, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, BookOpen, CalendarDays, UserRound } from 'lucide-react';

export type ArticleNode = {type:string;text?:string;attrs?:Record<string,string|number|null>;marks?:{type:string;attrs?:Record<string,string|null>}[];content?:ArticleNode[]};
export type ArticleSummary = {id:string;title:string;slug:string;author_name:string;excerpt:string|null;cover:string|null;tags:string[];published_at:string|null};
export function articleDate(date:string|null) {
    return date?new Intl.DateTimeFormat('id-ID',{day:'numeric',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'Asia/Jakarta'}).format(new Date(date))+' WIB':'Belum terbit';
}
export function ArticleMeta({article}:{article:Pick<ArticleSummary,'author_name'|'published_at'>}) {
    return <div className="article-meta"><span><UserRound size={14}/>{article.author_name}</span><time dateTime={article.published_at ?? undefined}><CalendarDays size={14}/>{articleDate(article.published_at)}</time></div>;
}
export function ArticleTags({tags,active}:{tags:string[];active?:string}) {
    return <div className="article-tags">{tags.map((tag,index)=><Link key={tag} href={'/artikel?tag='+encodeURIComponent(tag)} className={'article-tag tag-'+index%5+(active===tag?' selected':'')}>#{tag}</Link>)}</div>;
}
export function ArticleCard({article,featured=false}:{article:ArticleSummary;featured?:boolean}) {
    return <article className={'article-card'+(featured?' article-featured':'')} data-reveal>
        <Link href={'/artikel/'+article.slug} className="article-card-cover" aria-label={'Baca '+article.title}>{article.cover?<img src={article.cover} alt={article.title} width={720} height={405} loading="lazy"/>:<span><BookOpen size={44}/><small>{article.tags[0] ?? 'Cerita pilihan'}</small></span>}</Link>
        <div className="article-card-copy"><ArticleTags tags={article.tags.slice(0,2)}/><h2><Link href={'/artikel/'+article.slug}>{article.title}</Link></h2><p>{article.excerpt}</p><ArticleMeta article={article}/><Link className="article-read" href={'/artikel/'+article.slug}>Baca artikel<ArrowUpRight size={16}/></Link></div>
    </article>;
}
function render(node:ArticleNode,index:number):ReactNode {
    if(node.type==='text') {
        let value:ReactNode=node.text;
        for(const mark of node.marks ?? []) {
            if(mark.type==='bold')value=<strong>{value}</strong>;
            else if(mark.type==='italic')value=<em>{value}</em>;
            else if(mark.type==='underline')value=<u>{value}</u>;
            else if(mark.type==='strike')value=<s>{value}</s>;
            else if(mark.type==='link')value=<a href={mark.attrs?.href ?? undefined} target="_blank" rel="noopener noreferrer nofollow">{value}</a>;
            else if(mark.type==='textStyle')value=<span style={{fontSize:mark.attrs?.fontSize ?? undefined,fontFamily:mark.attrs?.fontFamily ?? undefined,color:mark.attrs?.color ?? undefined,backgroundColor:mark.attrs?.backgroundColor ?? undefined,lineHeight:mark.attrs?.lineHeight ?? undefined}}>{value}</span>;
        }
        return <span key={index}>{value}</span>;
    }
    if(node.type==='hardBreak')return <br key={index}/>;
    if(node.type==='horizontalRule')return <hr key={index}/>;
    if(node.type==='image')return <figure key={index}><img src={String(node.attrs?.src ?? '')} alt={String(node.attrs?.alt ?? '')} title={String(node.attrs?.title ?? '')} loading="lazy"/>{node.attrs?.title && <figcaption>{node.attrs.title}</figcaption>}</figure>;
    const children=node.content?.map(render);
    if(node.type==='doc')return <div key={index}>{children}</div>;
    const tag=({paragraph:'p',heading:'h'+(node.attrs?.level ?? 2),bulletList:'ul',orderedList:'ol',listItem:'li',blockquote:'blockquote'} as Record<string,string>)[node.type];
    if(!tag)return null;
    const style:CSSProperties=node.attrs?.textAlign?{textAlign:node.attrs.textAlign as CSSProperties['textAlign']}:{};
    return createElement(tag,{key:index,style,...(tag==='ol'?{start:Number(node.attrs?.start ?? 1)}:{})},children?.length?children:tag==='p'?<br/>:null);
}
export default function ArticleBody({content}:{content:ArticleNode}) {return <div className="article-prose">{render(content,0)}</div>;}
