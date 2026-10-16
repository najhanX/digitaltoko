import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { CheckCircle2, ReceiptText, ShieldCheck } from 'lucide-react';
import { money } from './Commerce';
import { FormErrors } from './CatalogForms';
import GameAccountFields from './GameAccountFields';
import type { GameAccount, GameProfile } from './gameIdentity';

export type CheckoutProduct={id:string;name:string;code:string;kind:string;price:number;available:boolean;description:string|null};
export type CheckoutCustomer={name:string;phone:string;email:string|null;balance:number}|null;
export type CheckoutOptions={customer:CheckoutCustomer;allowGuest:boolean;gateway:string|null;feeMarkup:string;gameProfile?:GameProfile|null};
export default function CheckoutForm({product,customer,allowGuest,gateway,feeMarkup,nominals,gameProfile}:{product:CheckoutProduct|null;nominals?:ReactNode}&CheckoutOptions) {
    const form=useForm({idempotency_key:'',purpose:'product',product_id:product?.id ?? '',phone:customer?.phone ?? '',email:customer?.email ?? '',destination:'',payment:'gateway',expected_price:product?.price ?? 0,inquiry_id:'',game_verification:''});
    const [gameAccount,setGameAccount]=useState<GameAccount|null>(null);
    const [inquiry,setInquiry]=useState<{id:string;amount:number;customer_name:string;period:string}|null>(null);
    const [checking,setChecking]=useState(false),[inquiryError,setInquiryError]=useState('');
    const request=useRef<AbortController|null>(null);
    useEffect(()=>{request.current?.abort();setChecking(false);setInquiry(null);setInquiryError('');form.setData(data=>({...data,product_id:product?.id ?? '',expected_price:product?.price ?? 0,inquiry_id:'',idempotency_key:crypto.randomUUID()}));return()=>request.current?.abort();},[product?.id,product?.price,form.data.destination,form.data.phone,form.data.payment]);
    async function checkBill(){
        if(!product)return;
        const controller=new AbortController();request.current?.abort();request.current=controller;setChecking(true);setInquiryError('');
        try {
            const cookie=document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN='));
            const response=await fetch('/checkout/inquiry',{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':cookie?decodeURIComponent(cookie.slice(11)):''},body:JSON.stringify({product_id:product.id,destination:form.data.destination,phone:form.data.phone,idempotency_key:crypto.randomUUID()})});
            const result=await response.json();if(controller.signal.aborted)return;
            if(!response.ok)throw Error(Object.values(result.errors??{}).flat()[0] as string ?? 'Tagihan belum dapat diperiksa.');
            setInquiry(result.data);form.setData(data=>({...data,inquiry_id:result.data.id,expected_price:result.data.amount}));
        }catch(error){if(!controller.signal.aborted)setInquiryError(error instanceof Error?error.message:'Pemeriksaan tagihan gagal.');}
        finally{if(!controller.signal.aborted)setChecking(false);}
    }
    const valid=product?.available && (!gameProfile || !!form.data.game_verification) && (product.kind!=='postpaid' || !!inquiry) && (form.data.payment!=='gateway' || !!gateway);
    return <form className="ng-checkout-layout" onSubmit={event=>{event.preventDefault();if(valid && form.data.idempotency_key)form.post('/checkout');}}>
        <div className="ng-checkout-steps">
            <section className="sf-form-card ng-checkout-step"><h2><b>1</b>Masukkan data tujuan</h2><p>Pastikan ID atau nomor tujuan benar sebelum membayar.</p>{gameProfile?<GameAccountFields key={gameProfile.category_id+gameProfile.game+gameProfile.destination_format} profile={gameProfile} disabled={form.processing} onChange={(destination,account)=>{setGameAccount(account);form.setData(data=>({...data,destination,game_verification:account?.verification ?? ''}));}}/>:<><label>Tujuan / ID pelanggan<input required maxLength={255} value={form.data.destination} placeholder={product?.kind==='premium'?'Nomor WhatsApp atau identitas penerima':'ID game beserta server, atau nomor tujuan sesuai produk'} onChange={event=>form.setData('destination',event.target.value)}/></label><small>Jika game memakai server/zone, masukkan sesuai format tujuan produk yang disediakan toko.</small></>}</section>
            <section className="sf-form-card ng-checkout-step"><h2><b>2</b>Pilih produk / nominal</h2>{nominals ?? (product && <div className="ng-selected-product"><CheckCircle2 size={20}/><span><strong>{product.name}</strong><small>{product.code}</small></span><b>{money(product.price)}</b></div>)}</section>
            <section className="sf-form-card ng-checkout-step"><h2><b>3</b>Pilih pembayaran</h2><div className="ng-payment-options"><label className={form.data.payment==='gateway'?'selected':''}><input type="radio" name="payment" checked={form.data.payment==='gateway'} onChange={()=>form.setData('payment','gateway')}/><span><strong>{gateway==='midtrans'?'QRIS Midtrans':gateway==='custom'?'QRIS toko':'QRIS belum tersedia'}</strong><small>Invoice dan QR pembayaran ditampilkan setelah pemesanan.</small></span></label>{customer && <label className={form.data.payment==='wallet'?'selected':''}><input type="radio" name="payment" checked={form.data.payment==='wallet'} onChange={()=>form.setData('payment','wallet')}/><span><strong>Saldo pelanggan</strong><small>{money(customer.balance)}</small></span></label>}</div><p>{gateway==='custom'?'Kode unik ditampilkan di invoice.':feeMarkup!=='0'?'Biaya Midtrans sesuai tarif toko: '+feeMarkup+'. Rincian tersedia pada invoice.':'Biaya gateway ditanggung toko.'}</p></section>
            <section className="sf-form-card ng-checkout-step"><h2><b>4</b>Kontak penerima bukti</h2><div className="ng-contact-fields"><label>Nomor WhatsApp wajib<input required type="tel" readOnly={!!customer} value={form.data.phone} placeholder="08xxxxxxxxxx" onChange={event=>form.setData('phone',event.target.value)}/></label><label>Email (opsional)<input type="email" readOnly={!!customer} value={form.data.email} placeholder="nama@email.com" onChange={event=>form.setData('email',event.target.value)}/></label></div><p>Bukti dan pembaruan pesanan dikirim ke kontak ini. Simpan nomor pesanan untuk mengecek status tanpa login.</p></section>
        </div>
        <aside className="ng-checkout-summary sf-form-card"><ReceiptText size={27}/><h2>Ringkasan pesanan</h2>{product?<><strong>{product.name}</strong><p>{product.description}</p><dl>{gameAccount && <div><dt>Nickname</dt><dd>{gameAccount.nickname}</dd></div>}<div><dt>Tujuan</dt><dd>{gameAccount?gameAccount.id+(gameAccount.server?' ('+gameAccount.server+')':''):form.data.destination || 'Belum diisi'}</dd></div><div><dt>Pembayaran</dt><dd>{form.data.payment==='wallet'?'Saldo pelanggan':'QRIS'}</dd></div></dl><div className="sf-total"><span>{product.kind==='postpaid'?'Tagihan':'Harga produk'}</span><strong>{product.kind==='postpaid' && !inquiry?'Periksa dahulu':money(inquiry?.amount ?? product.price)}</strong></div>{!product.available && <p className="feedback error">Produk sedang tidak tersedia. Pilih nominal lainnya.</p>}</>:<p>Pilih produk untuk melihat ringkasan pesanan.</p>}
            <FormErrors errors={form.errors}/>
            {product?.kind==='postpaid' && <><button type="button" className="button secondary" disabled={checking || !form.data.destination || !form.data.phone} onClick={checkBill}>{checking?'Memeriksa…':'Periksa tagihan'}</button>{inquiryError && <p className="feedback error" role="alert">{inquiryError}</p>}{inquiry && <p className="feedback success">{inquiry.customer_name} · {inquiry.period} · {money(inquiry.amount)}. Konfirmasi dalam 5 menit.</p>}</>}
            {!customer && !allowGuest?<p>Toko mewajibkan akun pelanggan. <Link className="ng-button" href="/account/login">Masuk / daftar</Link></p>:<button className="ng-button" disabled={form.processing || !valid}>{form.processing?'Menyiapkan pesanan…':form.data.payment==='wallet'?'Beli dengan saldo':'Pesan sekarang'}</button>}
            <small><ShieldCheck size={15}/>Harga dan ketersediaan diperiksa kembali sebelum pesanan dibuat. Total beserta biaya ditampilkan pada invoice.</small><p>{customer?'Transaksi gagal yang telah dibayar dikembalikan ke saldo pelanggan sesuai komponen refund invoice.':'Transaksi guest yang gagal ditindaklanjuti toko melalui WhatsApp untuk pembelian ulang atau refund manual.'}</p>{!customer && <Link className="text-link" href="/account/login">Masuk untuk memakai saldo pelanggan</Link>}
        </aside>
    </form>;
}
