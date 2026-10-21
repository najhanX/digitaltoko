import { useRef, useState } from 'react';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import type { JSONContent } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TextStyleKit } from '@tiptap/extension-text-style';
import TextAlign from '@tiptap/extension-text-align';
import Image from '@tiptap/extension-image';
import { AlignCenter, AlignJustify, AlignLeft, AlignRight, Bold, ImagePlus, Italic, Link2, List, ListOrdered, Quote, Redo2, RemoveFormatting, Strikethrough, Underline, Undo2 } from 'lucide-react';
import type { ArticleNode } from './ArticleBody';

const extensions=[StarterKit.configure({heading:{levels:[2,3,4]},code:false,codeBlock:false,link:{openOnClick:false,autolink:false}}),TextStyleKit,TextAlign.configure({types:['heading','paragraph']}),Image.configure({allowBase64:false})];
export const emptyArticle:ArticleNode={type:'doc',content:[{type:'paragraph'}]};
export default function ArticleEditor({value,onChange,onUpload,busy}:{value:ArticleNode;onChange:(doc:ArticleNode)=>void;onUpload:(file:File)=>Promise<string|null>;busy:boolean}) {
    const upload=useRef<HTMLInputElement>(null);
    const [linkOpen,setLinkOpen]=useState(false),[href,setHref]=useState('');
    const editor=useEditor({extensions,content:value as JSONContent,immediatelyRender:false,
        editorProps:{attributes:{class:'article-editor-content article-prose',role:'textbox','aria-label':'Isi artikel','aria-multiline':'true'}},
        onUpdate:({editor})=>onChange(editor.getJSON() as ArticleNode)});
    const state=useEditorState({editor,selector:({editor})=>editor?{bold:editor.isActive('bold'),italic:editor.isActive('italic'),underline:editor.isActive('underline'),strike:editor.isActive('strike'),bullet:editor.isActive('bulletList'),ordered:editor.isActive('orderedList'),quote:editor.isActive('blockquote'),heading:editor.getAttributes('heading').level as number|undefined,style:editor.getAttributes('textStyle') as Record<string,string>,alignment:editor.getAttributes('paragraph').textAlign ?? editor.getAttributes('heading').textAlign,undo:editor.can().undo(),redo:editor.can().redo()}:null});
    if(!editor)return <div className="article-editor-loading">Menyiapkan editor…</div>;
    const actions=[
        {label:'Tebal',icon:Bold,active:state?.bold,action:()=>editor.chain().focus().toggleBold().run()},
        {label:'Miring',icon:Italic,active:state?.italic,action:()=>editor.chain().focus().toggleItalic().run()},
        {label:'Garis bawah',icon:Underline,active:state?.underline,action:()=>editor.chain().focus().toggleUnderline().run()},
        {label:'Coret',icon:Strikethrough,active:state?.strike,action:()=>editor.chain().focus().toggleStrike().run()},
        {label:'Daftar poin',icon:List,active:state?.bullet,action:()=>editor.chain().focus().toggleBulletList().run()},
        {label:'Daftar nomor',icon:ListOrdered,active:state?.ordered,action:()=>editor.chain().focus().toggleOrderedList().run()},
        {label:'Kutipan',icon:Quote,active:state?.quote,action:()=>editor.chain().focus().toggleBlockquote().run()},
    ];
    return <div className="article-editor">
        <div className="article-editor-toolbar" role="group" aria-label="Format artikel">
            <label className="sr-only" htmlFor="article-text-format">Jenis teks</label><select id="article-text-format" aria-label="Jenis teks" value={state?.heading ?? 'paragraph'} onChange={event=>{const level=Number(event.target.value);if(level)editor.chain().focus().setHeading({level:level as 2|3|4}).run();else editor.chain().focus().setParagraph().run();}}><option value="paragraph">Paragraf</option><option value="2">Judul besar</option><option value="3">Subjudul</option><option value="4">Judul kecil</option></select>
            <select aria-label="Ukuran huruf" value={state?.style.fontSize ?? ''} onChange={event=>{if(event.target.value)editor.chain().focus().setFontSize(event.target.value).run();else editor.chain().focus().unsetFontSize().run();}}><option value="">Ukuran</option>{[12,14,16,18,20,24,28,32,40,48].map(size=><option key={size} value={size+'px'}>{size} px</option>)}</select>
            <select aria-label="Jenis huruf" value={state?.style.fontFamily ?? ''} onChange={event=>{if(event.target.value)editor.chain().focus().setFontFamily(event.target.value).run();else editor.chain().focus().unsetFontFamily().run();}}><option value="">Font toko</option>{['Arial','Georgia','Verdana','monospace'].map(font=><option key={font}>{font}</option>)}</select>
            {actions.map(item=><button type="button" key={item.label} title={item.label} aria-label={item.label} aria-pressed={!!item.active} disabled={busy} className={item.active?'selected':''} onMouseDown={event=>event.preventDefault()} onClick={item.action}><item.icon size={16}/></button>)}
            {[['left',AlignLeft,'Rata kiri'],['center',AlignCenter,'Rata tengah'],['right',AlignRight,'Rata kanan'],['justify',AlignJustify,'Rata penuh']].map(([align,Icon,label])=>{const Glyph=Icon as typeof AlignLeft;return <button type="button" key={String(align)} title={String(label)} aria-label={String(label)} className={state?.alignment===align?'selected':''} onMouseDown={event=>event.preventDefault()} onClick={()=>editor.chain().focus().setTextAlign(String(align)).run()}><Glyph size={16}/></button>;})}
            <select aria-label="Jarak baris" value={state?.style.lineHeight ?? ''} onChange={event=>{if(event.target.value)editor.chain().focus().setLineHeight(event.target.value).run();else editor.chain().focus().unsetLineHeight().run();}}><option value="">Spasi</option>{['1','1.25','1.5','1.75','2','2.5','3'].map(space=><option key={space}>{space}</option>)}</select>
            <button type="button" title="Atur tautan" aria-label="Atur tautan" onMouseDown={event=>event.preventDefault()} onClick={()=>{setHref(editor.getAttributes('link').href ?? '');setLinkOpen(!linkOpen);}}><Link2 size={16}/></button>
            <button type="button" title="Sisipkan gambar" aria-label="Sisipkan gambar" disabled={busy} onClick={()=>upload.current?.click()}><ImagePlus size={16}/></button>
            <button type="button" title="Hapus format" aria-label="Hapus format" onMouseDown={event=>event.preventDefault()} onClick={()=>editor.chain().focus().unsetAllMarks().clearNodes().run()}><RemoveFormatting size={16}/></button>
            <button type="button" title="Urungkan" aria-label="Urungkan" disabled={!state?.undo} onClick={()=>editor.chain().focus().undo().run()}><Undo2 size={16}/></button><button type="button" title="Ulangi" aria-label="Ulangi" disabled={!state?.redo} onClick={()=>editor.chain().focus().redo().run()}><Redo2 size={16}/></button>
        </div>
        {linkOpen && <div className="article-link-popover" role="group" aria-label="Sisipkan tautan"><input aria-label="URL tautan" placeholder="https://contoh.com atau /kategori/…" value={href} maxLength={2048} onChange={event=>setHref(event.target.value)} onKeyDown={event=>{if(event.key==='Enter')event.preventDefault();}}/><button type="button" className="button small" onClick={()=>{if(href.trim())editor.chain().focus().extendMarkRange('link').setLink({href:href.trim()}).run();else editor.chain().focus().unsetLink().run();setLinkOpen(false);}}>Terapkan</button><button type="button" className="button secondary small" onClick={()=>setLinkOpen(false)}>Tutup</button></div>}
        <EditorContent editor={editor}/><div className="article-editor-hint">Enter untuk paragraf baru · Shift+Enter untuk baris baru · Pilih teks untuk mengubah format.</div>
        <input ref={upload} type="file" hidden accept="image/jpeg,image/png,image/webp" onChange={async event=>{const file=event.target.files?.[0];event.target.value='';if(file){const src=await onUpload(file);if(src)editor.chain().focus().setImage({src,alt:file.name.replace(/\.[^.]+$/,'')}).run();}}}/>
    </div>;
}
