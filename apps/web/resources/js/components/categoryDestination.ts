export type DestinationProfile = {type:'game'|'premium'|'phone'|'pln'|'other';columns:number;category_id:string;format:string;label:string};

export function categoryDestination(profile:DestinationProfile, id:string, server:string):string {
    if(profile.columns!==2 || profile.format==='id')return id;
    if(profile.format==='parentheses')return id+'('+server+')';
    if(profile.format==='pipe')return id+'|'+server;
    if(profile.format==='space')return id+' '+server;
    return id+server;
}
