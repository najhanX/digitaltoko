import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowLeft, CheckCircle2, Zap } from 'lucide-react';
import { ShopLayout, money, type Shop } from '../../components/Commerce';
import GameArt from '../../components/GameArt';
import CheckoutForm, { type CheckoutOptions, type CheckoutProduct } from '../../components/CheckoutForm';
import { FilterBar, Pagination, useServerList, type ListFilters, type PageMeta } from '../../components/ListControls';
import type { StoreCategory } from '../../components/NebulaCategories';

type Props={store:Shop;category:StoreCategory;products:CheckoutProduct[];selectedProduct:CheckoutProduct|null;filters:ListFilters;pagination:PageMeta}&CheckoutOptions;
export default function Category({store,category,products,selectedProduct,filters,pagination,...options}:Props) {
    const [selected,setSelected]=useState<CheckoutProduct|null>(selectedProduct);
    useEffect(()=>setSelected(selectedProduct),[category.id,selectedProduct?.id]);
    const list=useServerList(filters,['products','filters','pagination']);
    return <ShopLayout store={store} title={category.name} showTitle={false}>
        <Link className="ng-back-link" href="/"><ArrowLeft size={16}/>Semua kategori</Link>
        <section className="ng-category-hero"><GameArt name={category.name} image={category.image}/><div><span className="ng-eyebrow">{category.service}</span><h1>{category.name}</h1><p>{options.destinationProfile?.type==='premium'?'Pilih produk, lalu isi nomor WhatsApp penerima bukti. Nomor tersebut juga menjadi tujuan pesanan aplikasi premium.':'Pilih nominal, lengkapi tujuan sekali untuk kategori ini, lalu lanjutkan pembayaran.'} Tidak perlu login jika toko mengizinkan pembelian guest.</p><span className="ng-category-promise"><Zap size={15}/>Proses otomatis<CheckCircle2 size={15}/>Status dapat dilacak</span></div></section>
        <CheckoutForm key={category.id} product={selected} {...options} nominals={<><FilterBar storefront embedded filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari nominal atau nama produk…"/><Pagination compact meta={pagination} onPage={list.go} busy={list.busy} label="Navigasi atas nominal"/>{products.length?<div className="ng-nominal-grid">{products.map(product=><button className={'ng-nominal'+(selected?.id===product.id?' selected':'')} key={product.id} type="button" aria-pressed={selected?.id===product.id} disabled={!product.available} onClick={()=>setSelected(product)}><span><strong>{product.name}</strong><small>{product.kind==='postpaid'?'Total biaya admin':product.available?'Tersedia':'Tidak tersedia'}</small></span><b>{money(product.price)}</b>{selected?.id===product.id && <CheckCircle2 size={16}/>}</button>)}</div>:<p>Tidak ada nominal yang cocok. Coba kata kunci lain.</p>}<Pagination meta={pagination} onPage={list.go} busy={list.busy} label="Halaman nominal"/></>}/>
    </ShopLayout>;
}
