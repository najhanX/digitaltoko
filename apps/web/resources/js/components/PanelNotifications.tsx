import { router } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { Bell, Check, CheckCheck, CheckCircle2, Info, TriangleAlert, X, XCircle } from 'lucide-react';

export type PanelNotice = {id:string;title:string;message:string;level:'success'|'info'|'warning'|'error';url:string|null;store_id:string|null;created_at:string;read_at:string|null;recipient?:string};
export type NotificationSummary = {recipient:string;guard:'web'|'staff';unread_count:number;latest_id:string|null};
type NoticeFeed = {recipient:string;items:PanelNotice[];unread_count:number;next_cursor:string|null;total:number};
const emptyFeed = (recipient:string):NoticeFeed => ({recipient,items:[],unread_count:0,next_cursor:null,total:0});
type NoticeRuntime = {push:(notice:PanelNotice)=>void};
const NoticeContext = createContext<NoticeRuntime>({push:()=>{}});
const icons = {success:CheckCircle2,info:Info,warning:TriangleAlert,error:XCircle};

function Toast({notice,onClose}:{notice:PanelNotice;onClose:()=>void}) {
    const [paused,setPaused] = useState(false);
    const remaining = useRef(notice.level==='error'?10000:6500);
    const closeRef = useRef(onClose);
    closeRef.current=onClose;
    useEffect(()=>{
        if(paused)return;
        const started=Date.now();
        const timer=setTimeout(()=>closeRef.current(),remaining.current);
        return()=>{clearTimeout(timer);remaining.current=Math.max(0,remaining.current-(Date.now()-started));};
    },[paused]);
    const Icon=icons[notice.level] ?? Info;
    return <article className={'panel-toast '+notice.level} onMouseEnter={()=>setPaused(true)} onMouseLeave={()=>setPaused(false)} onFocusCapture={()=>setPaused(true)} onBlurCapture={event=>{if(!event.currentTarget.contains(event.relatedTarget))setPaused(false);}}>
        <span className="notice-icon"><Icon size={21}/></span><div><strong>{notice.title}</strong><p>{notice.message}</p></div><button type="button" className="toast-close" aria-label={'Tutup notifikasi '+notice.title} onClick={onClose}><X size={17}/></button>
    </article>;
}

export function PanelNotificationProvider({children,initialSummary,initialNotice}:{children:ReactNode;initialSummary?:NotificationSummary|null;initialNotice?:PanelNotice}) {
    const [toasts,setToasts]=useState<PanelNotice[]>([]);
    const scope=useRef(initialSummary?.recipient ?? null);
    const seen=useRef(new Set<string>());
    const stack=useRef<HTMLDivElement>(null);
    const push=useCallback((notice:PanelNotice)=>{
        if(!scope.current || notice.recipient!==scope.current || seen.current.has(notice.id))return;
        seen.current.add(notice.id);
        if(seen.current.size>200)seen.current.delete(seen.current.values().next().value!);
        setToasts(current=>[...current,notice].slice(-3));
    },[]);
    useEffect(()=>{
        if(initialNotice)push(initialNotice);
        const stopFlash=router.on('flash',event=>{
            const notice=event.detail.flash?.panelNotice as PanelNotice|undefined;
            if(notice)push(notice);
        });
        const stopNavigate=router.on('navigate',event=>{
            const summary=event.detail.page.props.notificationSummary as NotificationSummary|null|undefined;
            const key=summary?.recipient ?? null;
            if(key!==scope.current){scope.current=key;seen.current.clear();setToasts([]);}
        });
        return()=>{stopFlash();stopNavigate();};
    },[initialNotice,push]);
    const visible=toasts.length>0;
    useEffect(()=>{
        const element=stack.current;
        if(!visible || !element?.showPopover)return;
        element.showPopover();
        return()=>{if(element.matches(':popover-open'))element.hidePopover();};
    },[visible]);
    return <NoticeContext.Provider value={{push}}>{children}{visible && <div ref={stack} popover="manual" className="panel-surface panel-toast-stack" aria-label="Notifikasi tindakan" aria-live="polite" aria-relevant="additions">
        {toasts.map(notice=><Toast key={notice.id} notice={notice} onClose={()=>setToasts(current=>current.filter(item=>item.id!==notice.id))}/>)}</div>}</NoticeContext.Provider>;
}

async function noticeRequest(url:string,body?:Record<string,string>) {
    const cookie=document.cookie.split('; ').find(value=>value.startsWith('XSRF-TOKEN='));
    const token=cookie?decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)):'';
    const response=await fetch(url,{credentials:'same-origin',method:body?'POST':'GET',headers:{Accept:'application/json',...(body?{'Content-Type':'application/json','X-XSRF-TOKEN':token}:{})},...(body?{body:JSON.stringify(body)}:{})});
    if(!response.ok)throw new Error('Notification request failed');
    return response.json();
}

export default function NotificationBell({summary,preview=false}:{summary?:NotificationSummary|null;preview?:boolean}) {
    const {push}=useContext(NoticeContext);
    const [open,setOpen]=useState(false);
    const [unread,setUnread]=useState(summary?.unread_count ?? 0);
    const [feed,setFeed]=useState<NoticeFeed>(()=>emptyFeed(summary?.recipient ?? ''));
    const [filter,setFilter]=useState<'all'|'unread'>('all');
    const [busy,setBusy]=useState(false);
    const [error,setError]=useState('');
    const root=useRef<HTMLDivElement>(null), trigger=useRef<HTMLButtonElement>(null), list=useRef<HTMLDivElement>(null), sentinel=useRef<HTMLDivElement>(null);
    const busyRef=useRef(false), mounted=useRef(false), sequence=useRef(0), latest=useRef(summary?.latest_id ?? null);
    const openRef=useRef(open), filterRef=useRef(filter), failedCursor=useRef<string|null>(null);
    openRef.current=open;filterRef.current=filter;
    const guard=summary?.guard ?? 'web';
    const recipient=summary?.recipient ?? null;
    useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;sequence.current++;};},[]);
    const load=useCallback(async(cursor:string|null,selected:'all'|'unread',silent=false)=>{
        if(preview || !recipient)return;
        const ticket=++sequence.current;
        busyRef.current=true;
        if(!silent){setBusy(true);setError('');}
        try {
            const query=new URLSearchParams({guard,filter:selected});
            if(cursor)query.set('cursor',cursor);
            const result=await noticeRequest('/panel/notifications?'+query);
            const data=result.data as NoticeFeed;
            if(!mounted.current || sequence.current!==ticket || data.recipient!==recipient)return;
            setUnread(data.unread_count);
            if(!silent){
                setFeed(current=>cursor?{...data,items:[...new Map([...current.items,...data.items].map(notice=>[notice.id,notice])).values()]}:data);
            }
            if(silent && data.items.length && data.items[0].id!==latest.current){
                const previous=latest.current;
                latest.current=data.items[0].id;
                if(previous && data.items.some(item=>item.id===previous)){
                    for(const notice of data.items.slice(0,data.items.findIndex(item=>item.id===previous)).reverse()){
                        if(!notice.read_at)push({...notice,recipient});
                    }
                }
            }
        } catch {
            if(mounted.current && sequence.current===ticket && !silent){failedCursor.current=cursor;setError('Riwayat belum dapat dimuat. Silakan coba kembali.');}
        } finally {
            if(mounted.current && sequence.current===ticket){busyRef.current=false;setBusy(false);}
        }
    },[guard,recipient,preview,push]);
    useEffect(()=>{
        setUnread(summary?.unread_count ?? 0);
    },[summary?.unread_count]);
    useEffect(()=>{
        sequence.current++;busyRef.current=false;setBusy(false);setOpen(false);setError('');
        setFeed(emptyFeed(recipient ?? ''));latest.current=summary?.latest_id ?? null;
    },[recipient]);
    useEffect(()=>{
        if(!recipient || preview)return;
        const timer=setInterval(()=>{if(!document.hidden && !openRef.current && !busyRef.current)void load(null,'all',true);},30000);
        return()=>clearInterval(timer);
    },[load,recipient,preview]);
    useEffect(()=>{
        if(!open || busy || error || !feed.next_cursor || !list.current || !sentinel.current)return;
        const cursor=feed.next_cursor;
        const observer=new IntersectionObserver(entries=>{
            if(entries.some(entry=>entry.isIntersecting) && !busyRef.current)void load(cursor,filterRef.current);
        },{root:list.current,rootMargin:'0px 0px 100px 0px'});
        observer.observe(sentinel.current);
        return()=>observer.disconnect();
    },[open,busy,error,feed.next_cursor,feed.items.length,load]);
    const close=useCallback(()=>{
        sequence.current++;busyRef.current=false;setBusy(false);setOpen(false);
    },[]);
    useEffect(()=>{
        if(!open)return;
        const outside=(event:PointerEvent)=>{if(!root.current?.contains(event.target as Node))close();};
        const keyboard=(event:KeyboardEvent)=>{
            if(event.key==='Escape'){event.preventDefault();close();trigger.current?.focus();}
        };
        document.addEventListener('pointerdown',outside);document.addEventListener('keydown',keyboard);
        return()=>{document.removeEventListener('pointerdown',outside);document.removeEventListener('keydown',keyboard);};
    },[open,close]);
    async function read(id?:string,url?:string|null) {
        if(busyRef.current || !recipient)return;
        busyRef.current=true;setBusy(true);setError('');
        const ticket=++sequence.current;
        try {
            const result=await noticeRequest(id?'/panel/notifications/'+id+'/read':'/panel/notifications/read-all',{guard});
            if(!mounted.current || ticket!==sequence.current)return;
            setUnread(result.data.unread_count);
            setFeed(current=>({...current,unread_count:result.data.unread_count,
                items:filterRef.current==='unread'?current.items.filter(notice=>id && notice.id!==id):current.items.map(notice=>(!id || notice.id===id)?{...notice,read_at:notice.read_at ?? result.data.read_at}:notice),
                ...(filterRef.current==='unread'?{total:result.data.unread_count,...(!id?{next_cursor:null}:{})}:{}),
            }));
            if(url){close();router.visit(url);}
        } catch {if(mounted.current && ticket===sequence.current){failedCursor.current=null;setError('Notifikasi belum dapat ditandai dibaca. Coba kembali.');}}
        finally {if(mounted.current && ticket===sequence.current){busyRef.current=false;setBusy(false);}}
    }
    function start(selected:'all'|'unread') {
        setFeed(emptyFeed(recipient ?? ''));failedCursor.current=null;
        if(list.current)list.current.scrollTop=0;
        void load(null,selected);
    }
    function choose(selected:'all'|'unread') {setFilter(selected);filterRef.current=selected;start(selected);}
    return <div className="notification-center" ref={root}>
        <button ref={trigger} type="button" className={'notification-bell '+(open?'selected':'')} aria-label={'Notifikasi'+(unread?' · '+unread+' belum dibaca':'')} aria-expanded={open} aria-controls="panel-notification-dropdown" onClick={()=>{if(open)close();else{setOpen(true);start(filter);}}}><Bell size={20}/>{unread>0 && <span className="notification-count" aria-hidden="true">{unread>99?'99+':unread}</span>}</button>
        {open && <section id="panel-notification-dropdown" className="notification-dropdown" aria-label="Riwayat notifikasi" aria-busy={busy}>
            <header><div><h2>Notifikasi</h2><p>{unread?unread+' belum dibaca':'Semua notifikasi sudah dibaca'}</p></div><button type="button" className="icon-button" aria-label="Tutup riwayat notifikasi" onClick={()=>{close();trigger.current?.focus();}}><X size={17}/></button></header>
            <div className="notification-tools"><div><button type="button" aria-pressed={filter==='all'} onClick={()=>choose('all')} disabled={busy}>Semua</button><button type="button" aria-pressed={filter==='unread'} onClick={()=>choose('unread')} disabled={busy}>Belum dibaca</button></div><button className="notification-read-all" type="button" onClick={()=>void read()} disabled={busy || !unread || preview}><CheckCheck size={15}/>Tandai semua</button></div>
            <div className="notification-list" ref={list} tabIndex={0} aria-label="Daftar notifikasi">
                {!busy && !error && !feed.items.length && !feed.next_cursor?<div className="notification-empty"><Bell size={30}/><strong>{filter==='unread'?'Tidak ada notifikasi belum dibaca':'Belum ada notifikasi'}</strong><p>Hasil tindakan dan pembaruan toko akan muncul di sini.</p></div>:feed.items.map(notice=>{
                    const Icon=icons[notice.level] ?? Info;
                    return <article key={notice.id} className={'notification-item '+notice.level+(!notice.read_at?' unread':'')}><span className="notice-icon"><Icon size={18}/></span><div><div className="notification-item-title"><strong>{notice.title}</strong>{!notice.read_at && <span className="notification-dot" aria-label="Belum dibaca"/>}</div><p>{notice.message}</p><time dateTime={notice.created_at}>{new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Jakarta'}).format(new Date(notice.created_at))}</time><div className="notification-item-actions">{notice.url && <button type="button" disabled={busy} onClick={()=>void read(notice.id,notice.url)}>Buka halaman</button>}{!notice.read_at && <button type="button" disabled={busy} onClick={()=>void read(notice.id)}><Check size={13}/>Tandai dibaca</button>}</div></div></article>;
                })}
                {busy && <div className="notification-load-status" role="status"><span className="notification-loading"/>Memuat notifikasi…</div>}
                {error && <div className="notification-load-status notification-load-error" role="alert"><TriangleAlert size={18}/><p>{error}</p><button className="text-link" type="button" disabled={busy} onClick={()=>void load(failedCursor.current,filter)}>Coba kembali</button></div>}
                {!busy && !error && feed.items.length>0 && !feed.next_cursor && <p className="notification-end">Semua notifikasi sudah dimuat</p>}
                <div ref={sentinel} className="notification-sentinel" aria-hidden="true"/>
            </div>
            <footer><span>{feed.items.length} dari {feed.total} notifikasi{filter==='unread'?' belum dibaca':''}</span></footer>
        </section>}
    </div>;
}
