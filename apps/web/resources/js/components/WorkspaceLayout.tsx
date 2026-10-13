import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Brand from './Brand';

export default function WorkspaceLayout({title,description,role='owner',canCreate=true,children}:{title:string;description?:string;role?:'owner'|'admin';canCreate?:boolean;children:ReactNode}) {
    const flash=usePage().props.flash as {success?:string;error?:string};
    const links=role==='admin'?[['/admin','Ringkasan'],['/admin/verifications','Verifikasi owner']]:[['/owner','Ringkasan'],['/owner/profile','Profil & verifikasi'],...(canCreate?[['/owner/stores/create','Buat toko']]:[])];
    return <><Head title={title}/><div className="workspace"><header className="workspace-header"><Brand/><nav>{links.map(([url,label])=><Link key={url} href={url}>{label}</Link>)}<Link href="/logout" method="post" as="button">Keluar</Link></nav></header><main className="workspace-content"><div className="section-heading"><div><div className="eyebrow">{role==='admin'?'ADMIN PLATFORM':'RUANG OWNER'}</div><h1>{title}</h1>{description&&<p>{description}</p>}</div></div>{flash?.success&&<div className="notice" role="status">{flash.success}</div>}{flash?.error&&<div className="notice" role="alert">{flash.error}</div>}{children}</main></div></>;
}
