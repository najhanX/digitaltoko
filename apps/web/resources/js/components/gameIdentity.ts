export type GameProfile={game:string;name:string;id_type:'numeric'|'text'|'riot';id_label:string;server_label:string|null;server_options:string[];category_id:string;destination_format:string};
export type GameAccount={id:string;server:string;nickname:string;destination:string;verification:string;expires_at:number};

export function gameDestination(profile:GameProfile,id:string,server:string):string {
    if(!profile.server_label || profile.destination_format==='id')return id;
    if(profile.destination_format==='parentheses')return id+'('+server+')';
    if(profile.destination_format==='pipe')return id+'|'+server;
    if(profile.destination_format==='space')return id+' '+server;
    return id+server;
}
export function gameFieldsReady(profile:GameProfile,id:string,server:string):boolean {
    const valid=profile.id_type==='numeric'?/^[0-9]{4,24}$/.test(id):profile.id_type==='riot'?/^[^\x00-\x1f\x7f#]{2,40}#[a-zA-Z0-9]{2,10}$/u.test(id):/^[a-zA-Z0-9_.-]{3,64}$/.test(id);
    return valid && (!profile.server_label || (profile.server_options.length?profile.server_options.includes(server):/^[0-9]{3,8}$/.test(server)));
}
