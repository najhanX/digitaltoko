import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
export default function SandboxIndicator({initial}:{initial:boolean}) {
    const [active,setActive]=useState(initial);
    useEffect(()=>router.on('navigate',event=>setActive(Boolean(event.detail.page.props.storeSandbox))),[]);
    return active?<div className="sandbox-indicator" role="status"><strong>Sandbox · Pengujian</strong><span>Transaksi ini memakai lingkungan uji.</span></div>:null;
}
