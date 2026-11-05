import type { CSSProperties } from 'react';

export type SectionKey='quick_order'|'banner'|'benefits'|'flash_sale'|'trending'|'catalog'|'leaderboard'|'guide';
export type DesignBanner={id:string;title:string;subtitle:string|null;image:string|null;href:string;button:string|null;enabled:boolean;overlay:boolean;link_type?:'url'|'category'|'none';category?:string|null};
export type ThemeColors={secondary:string;background:string;surface:string;text:string;muted:string;border:string};
export type CarouselSettings={autoplay:boolean;interval:number};
export type WelcomeAd={enabled:boolean;title:string;message:string|null;image:string|null;href:string|null;button:string|null};
export type CustomerService={enabled:boolean;phone:string|null;label:string;message:string|null};
export const defaultWelcomeAd:WelcomeAd={enabled:false,title:'Promo pilihan untuk Anda',message:'Temukan penawaran dan produk pilihan toko kami.',image:null,href:'#flash-sale',button:'Lihat promo'};
export const defaultCustomerService:CustomerService={enabled:false,phone:'',label:'Chat CS',message:'Halo, saya ingin bertanya tentang produk di toko ini.'};
export function customerServiceUrl(settings:CustomerService):string|null {
    if(!settings.enabled || !settings.phone)return null;
    let phone=settings.phone.replace(/[\s+-]/g,'');if(phone.startsWith('0'))phone='62'+phone.slice(1);
    return /^628[0-9]{7,13}$/.test(phone)?'https://wa.me/'+phone+'?text='+encodeURIComponent(settings.message ?? ''):null;
}
export type ShopDesign={welcome_ad?:WelcomeAd;customer_service?:CustomerService;accent:string;logo:string|null;favicon?:string|null;colors?:ThemeColors;carousel?:CarouselSettings;banners:DesignBanner[];flash_product_ids:string[];flash_discounts?:Record<string,{type:'fixed'|'percent';value:string}>;flash_starts_at:string|null;flash_ends_at:string|null;trending_mode:'automatic'|'manual';trending_product_ids:string[];trending_category_ids?:string[];sections:{key:SectionKey;enabled:boolean;title:string|null}[]};
export type StoreBranding={design?:ShopDesign;design_revision?:number;gaming_banner_title?:string|null;gaming_banner_subtitle?:string|null;gaming_banner_image?:string|null;gaming_flash_category?:string|null;gaming_flash_ends_at?:string|null;[key:string]:unknown};
export type DesignProduct={id:string;name:string;code:string;category:string;sale_price:number;active:boolean};
export const sectionLabels:Record<SectionKey,string>={quick_order:'Quick Order',banner:'Banner utama',benefits:'Keunggulan toko',flash_sale:'Flash sale',trending:'Trending',catalog:'Katalog produk',leaderboard:'Tautan leaderboard',guide:'Cara memesan'};
export const themePresetGroups = {light:'Terang',dark:'Gelap',pastel:'Pastel'} as const;
export const themePresets = {
    midnight:{name:'Midnight',group:'dark',accent:'#9c80f6',colors:{secondary:'#f4b65b',background:'#0c101c',surface:'#171d2d',text:'#f2f3fa',muted:'#9ca5b9',border:'#293143'}},
    daylight:{name:'Daylight',group:'light',accent:'#4f46e5',colors:{secondary:'#0891b2',background:'#f5f7fb',surface:'#ffffff',text:'#17213a',muted:'#61718a',border:'#dce2ed'}},
    forest:{name:'Forest',group:'light',accent:'#137d68',colors:{secondary:'#b66a17',background:'#f3f8f5',surface:'#ffffff',text:'#193b32',muted:'#60766d',border:'#d5e3dc'}},
    ocean:{name:'Ocean',group:'light',accent:'#0e7490',colors:{secondary:'#6d28d9',background:'#f0f9fc',surface:'#ffffff',text:'#153344',muted:'#526879',border:'#cfe5ed'}},
    sapphire:{name:'Sapphire',group:'light',accent:'#1d4ed8',colors:{secondary:'#0f766e',background:'#f3f7ff',surface:'#ffffff',text:'#172b4d',muted:'#52627b',border:'#d7e2f4'}},
    ruby:{name:'Ruby',group:'light',accent:'#be123c',colors:{secondary:'#7c3aed',background:'#fff5f7',surface:'#ffffff',text:'#421c2a',muted:'#795567',border:'#efd7df'}},
    amber:{name:'Amber',group:'light',accent:'#a65309',colors:{secondary:'#0f766e',background:'#fff9ed',surface:'#ffffff',text:'#402c17',muted:'#75604a',border:'#ecdfc5'}},
    espresso:{name:'Espresso',group:'light',accent:'#79513b',colors:{secondary:'#9f1239',background:'#faf6f2',surface:'#ffffff',text:'#33261f',muted:'#746052',border:'#e5d9d0'}},
    graphite:{name:'Graphite',group:'light',accent:'#334155',colors:{secondary:'#0369a1',background:'#f5f6f8',surface:'#ffffff',text:'#18212f',muted:'#596575',border:'#d9dfe6'}},
    obsidian:{name:'Obsidian',group:'dark',accent:'#e4e4e7',colors:{secondary:'#fbbf24',background:'#101012',surface:'#1c1c20',text:'#fafafa',muted:'#aaaab5',border:'#34343c'}},
    deep_ocean:{name:'Deep Ocean',group:'dark',accent:'#38bdf8',colors:{secondary:'#a5b4fc',background:'#071722',surface:'#102735',text:'#e8f4fc',muted:'#98b7c9',border:'#274252'}},
    night_forest:{name:'Night Forest',group:'dark',accent:'#4ade80',colors:{secondary:'#fcd34d',background:'#0a1913',surface:'#142b20',text:'#edf9f1',muted:'#a0bdab',border:'#2d4939'}},
    amethyst:{name:'Amethyst',group:'dark',accent:'#c084fc',colors:{secondary:'#f9a8d4',background:'#190f25',surface:'#2a1c3b',text:'#f7efff',muted:'#bca7cf',border:'#49345d'}},
    ember:{name:'Ember',group:'dark',accent:'#fb923c',colors:{secondary:'#fcd34d',background:'#20130e',surface:'#342219',text:'#fff4e8',muted:'#cbb09a',border:'#583c2c'}},
    cyber:{name:'Cyber',group:'dark',accent:'#a3e635',colors:{secondary:'#22d3ee',background:'#10160d',surface:'#202b19',text:'#f1f8e9',muted:'#b0bda0',border:'#3c4c30'}},
    arctic:{name:'Arctic',group:'dark',accent:'#5eead4',colors:{secondary:'#93c5fd',background:'#0b181b',surface:'#142b30',text:'#eafaFA',muted:'#a0bec4',border:'#2d484f'}},
    lavender:{name:'Lavender',group:'pastel',accent:'#6d28d9',colors:{secondary:'#be185d',background:'#f5f0ff',surface:'#fefcff',text:'#302043',muted:'#6f5d85',border:'#e4d8f4'}},
    rose:{name:'Rose',group:'pastel',accent:'#be185d',colors:{secondary:'#7e22ce',background:'#fff0f5',surface:'#fffafd',text:'#452133',muted:'#7b566a',border:'#f0d4e2'}},
    mint:{name:'Mint',group:'pastel',accent:'#0f766e',colors:{secondary:'#a16207',background:'#ecfaf4',surface:'#fafffd',text:'#193d32',muted:'#4f7065',border:'#cde9dd'}},
    peach:{name:'Peach',group:'pastel',accent:'#b54720',colors:{secondary:'#9d174d',background:'#fff2e9',surface:'#fffcf9',text:'#472b22',muted:'#795d52',border:'#efd9ca'}},
    sky:{name:'Sky',group:'pastel',accent:'#0369a1',colors:{secondary:'#7c3aed',background:'#edf7ff',surface:'#fbfdff',text:'#1b364b',muted:'#526b7e',border:'#d0e5f4'}},
    butter:{name:'Butter',group:'pastel',accent:'#854d0e',colors:{secondary:'#0f766e',background:'#fffae5',surface:'#fffef5',text:'#43351a',muted:'#726448',border:'#e9dfba'}},
    periwinkle:{name:'Periwinkle',group:'pastel',accent:'#4338ca',colors:{secondary:'#0e7490',background:'#eff1ff',surface:'#fcfcff',text:'#282951',muted:'#606285',border:'#daddf5'}},
    sand:{name:'Sand',group:'pastel',accent:'#8c5b28',colors:{secondary:'#365f3c',background:'#f7f1e8',surface:'#fffdf9',text:'#3e3125',muted:'#75634e',border:'#e6d9c6'}},
} satisfies Record<string,{name:string;group:keyof typeof themePresetGroups;accent:string;colors:ThemeColors}>;
export const colorLabels:Record<keyof ThemeColors,string>={secondary:'Secondary / aksen kedua',background:'Background web',surface:'Kartu & panel',text:'Teks utama',muted:'Teks pendukung',border:'Garis / border'};
export const validColor=(value:string)=>/^#[0-9a-f]{6}$/i.test(value);
export function validBannerUrl(value:string):boolean {
    if(/[\\\u0000-\u0020\u007f]/.test(value))return false;
    if(/^(?:#[a-zA-Z0-9_-]+|\/(?!\/)[a-zA-Z0-9/?=&%._#-]*)$/.test(value))return true;
    try{const url=new URL(value);return url.protocol==='https:' && !!url.hostname && !url.username && !url.password;}catch{return false;}
}
export function bannerHref(banner:DesignBanner):string|null {
    if(banner.link_type==='none')return null;
    if(banner.link_type==='category')return banner.category?'/?category='+encodeURIComponent(banner.category)+'#catalog':null;
    return validBannerUrl(banner.href)?banner.href:null;
}
export function colorInk(color:string):string {
    const parts=[1,3,5].map(start=>parseInt(color.slice(start,start+2),16)/255).map(value=>value<=0.04045?value/12.92:((value+0.055)/1.055)**2.4);
    const luminance=parts[0]*0.2126+parts[1]*0.7152+parts[2]*0.0722;
    return (luminance+0.05)/0.05>1.05/(luminance+0.05)?'#101523':'#ffffff';
}
export function storefrontViewport(template:string|undefined, background?:string) {
    const fallback=['nebula','promo'].includes(template ?? '')?'#0c101c':['atlas','category'].includes(template ?? '')?'#f5f7fa':'#f8fafc';
    const color=background && validColor(background)?background:fallback;
    return {background:color,colorScheme:colorInk(color)==='#ffffff'?'dark':'light'};
}
export function designStyle(branding:{design?:Pick<ShopDesign,'accent'|'colors'>}|null):CSSProperties {
    const color=branding?.design?.accent,style:Record<string,string>={};
    if(color && validColor(color)){style['--sf-accent']=color;style['--sf-accent-ink']=colorInk(color);}
    const variables:Record<keyof ThemeColors,string>={secondary:'--sf-secondary',background:'--sf-bg',surface:'--sf-card',text:'--sf-text',muted:'--sf-muted',border:'--sf-line'};
    const colors=branding?.design?.colors;
    if(colors)for(const key of Object.keys(variables) as (keyof ThemeColors)[]){if(validColor(colors[key]))style[variables[key]]=colors[key];}
    return style as CSSProperties;
}
