import { Link, usePage } from '@inertiajs/react';
import { House, LayoutGrid, ReceiptText, Trophy, Newspaper } from 'lucide-react';

export default function StorefrontNavigation({template, preview=false}: {template:string; preview?:boolean}) {
    const {url} = usePage();
    const [path, query] = url.split('?');
    const previewPage = new URLSearchParams(query ?? '').get('page') ?? 'home';
    const links = [
        {key:'home', label:'Beranda', icon:House, path:'/'},
        {key:'catalog', label:'Produk', icon:LayoutGrid, path:'/#catalog'},
        {key:'lookup', label:'Cek pesanan', icon:ReceiptText, path:'/cek-pesanan'},
        ...(!preview?[{key:'articles', label:'Artikel', icon:Newspaper, path:'/artikel'}]:[]),
        {key:'leaderboard', label:'Leaderboard', icon:Trophy, path:'/leaderboard'},
    ];
    return <nav className="sf-nav sf-store-navigation" aria-label="Navigasi toko">
        {links.map(item => {
            const selected = preview ? previewPage === item.key : item.key === 'home' ? path === '/' : item.key === 'catalog' ? path.startsWith('/kategori/') || path === '/checkout' : item.key === 'articles' ? path.startsWith('/artikel') : path === item.path;
            return <Link key={item.key} href={preview?`/preview/storefront?template=${template}&page=${item.key}`:item.path} className={selected?'selected':''} aria-current={selected?'page':undefined}><item.icon size={15} aria-hidden="true"/><span>{item.label}</span></Link>;
        })}
    </nav>;
}
