import type { CSSProperties } from 'react';

export type SectionKey='banner'|'benefits'|'flash_sale'|'trending'|'catalog'|'leaderboard'|'guide';
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
export type ShopDesign={welcome_ad?:WelcomeAd;customer_service?:CustomerService;accent:string;logo:string|null;colors?:ThemeColors;carousel?:CarouselSettings;banners:DesignBanner[];flash_product_ids:string[];flash_starts_at:string|null;flash_ends_at:string|null;trending_mode:'automatic'|'manual';trending_product_ids:string[];trending_category_ids?:string[];sections:{key:SectionKey;enabled:boolean;title:string|null}[]};
export type StoreBranding={design?:ShopDesign;design_revision?:number;gaming_banner_title?:string|null;gaming_banner_subtitle?:string|null;gaming_banner_image?:string|null;gaming_flash_category?:string|null;gaming_flash_ends_at?:string|null;[key:string]:unknown};
export type DesignProduct={id:string;name:string;code:string;category:string;sale_price:number;active:boolean};
export const sectionLabels:Record<SectionKey,string>={banner:'Banner utama',benefits:'Keunggulan toko',flash_sale:'Flash sale',trending:'Trending',catalog:'Katalog produk',leaderboard:'Leaderboard & akun',guide:'Cara memesan'};
export const themePresets = {
    midnight:{name:'Midnight',accent:'#9c80f6',colors:{secondary:'#f4b65b',background:'#0c101c',surface:'#171d2d',text:'#f2f3fa',muted:'#9ca5b9',border:'#293143'}},
    daylight:{name:'Daylight',accent:'#4f46e5',colors:{secondary:'#0891b2',background:'#f5f7fb',surface:'#ffffff',text:'#17213a',muted:'#61718a',border:'#dce2ed'}},
    forest:{name:'Forest',accent:'#137d68',colors:{secondary:'#b66a17',background:'#f3f8f5',surface:'#ffffff',text:'#193b32',muted:'#60766d',border:'#d5e3dc'}},
} satisfies Record<string,{name:string;accent:string;colors:ThemeColors}>;
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
export function designStyle(branding:StoreBranding|null):CSSProperties {
    const color=branding?.design?.accent,style:Record<string,string>={};
    if(color && validColor(color)){style['--sf-accent']=color;style['--sf-accent-ink']=colorInk(color);}
    const variables:Record<keyof ThemeColors,string>={secondary:'--sf-secondary',background:'--sf-bg',surface:'--sf-card',text:'--sf-text',muted:'--sf-muted',border:'--sf-line'};
    const colors=branding?.design?.colors;
    if(colors)for(const key of Object.keys(variables) as (keyof ThemeColors)[]){if(validColor(colors[key]))style[variables[key]]=colors[key];}
    return style as CSSProperties;
}
