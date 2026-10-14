import { useEffect, useState } from 'react';
import { Moon, Sun, Monitor } from 'lucide-react';
type Theme = 'light' | 'dark' | 'system';
export default function ThemeToggle() {
    const [theme,setTheme]=useState<Theme>('system');
    useEffect(()=>{
        const media=window.matchMedia('(prefers-color-scheme: dark)');
        const apply=()=>{let saved:Theme='system';try{const value=localStorage.getItem('digitaltoko.panel.theme');if(value==='light'||value==='dark')saved=value;}catch{}setTheme(saved);document.documentElement.dataset.panelTheme=saved==='system'?(media.matches?'dark':'light'):saved;};
        apply();media.addEventListener('change',apply);window.addEventListener('storage',apply);window.addEventListener('panel-theme',apply);
        return ()=>{media.removeEventListener('change',apply);window.removeEventListener('storage',apply);window.removeEventListener('panel-theme',apply);};
    },[]);
    return <div className="theme-switch" role="group" aria-label="Tema panel">{([['light','Terang',Sun],['dark','Gelap',Moon],['system','Sistem',Monitor]] as const).map(([value,label,Icon])=><button type="button" key={value} title={label} aria-label={'Tema '+label.toLowerCase()} aria-pressed={theme===value} onClick={()=>{setTheme(value);document.documentElement.dataset.panelTheme=value==='system'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):value;try{localStorage.setItem('digitaltoko.panel.theme',value);}catch{}window.dispatchEvent(new Event('panel-theme'));}}><Icon size={16}/></button>)}</div>;
}
