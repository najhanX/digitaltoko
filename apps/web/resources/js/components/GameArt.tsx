import { Gem } from 'lucide-react';
export default function GameArt({name,index=0,small=false,image}:{name:string;index?:number;small?:boolean;image?:string|null}) {
    if(image)return <span className={'ng-game-art ng-category-image'+(image.startsWith('/brand/catalog/')?' ng-category-brand':'')+(small?' ng-art-small':'')}><img src={image} alt={name} width={240} height={300} loading="lazy"/></span>;
    const initials = name.split(/\s+/).filter(word=>!['of','the','&'].includes(word.toLowerCase())).slice(0,2).map(word=>word[0]).join('');
    return <span className={'ng-game-art ng-art-'+index%6+(small?' ng-art-small':'')} aria-hidden="true"><span className="ng-art-orbit"/><Gem className="ng-art-gem" strokeWidth={1}/><b>{initials}</b><small>GAME CREDIT</small></span>;
}

