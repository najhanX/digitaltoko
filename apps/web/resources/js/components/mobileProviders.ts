export type MobileProvider={id:string;name:string;prefixes:string[]};
export function mobileNumber(value:string):string {
    if(!/^[+\d\s()-]*$/.test(value))return '';
    const number=value.replace(/[\s()-]/g,'').replace(/^\+/, '');
    return number.startsWith('62')?'0'+number.slice(2):number;
}
export function mobileProvider(value:string,providers:MobileProvider[]):MobileProvider|null {
    const phone=mobileNumber(value);
    return providers.find(provider=>provider.prefixes.some(prefix=>phone.startsWith(prefix))) ?? null;
}
export function validMobileNumber(value:string):boolean {return /^08\d{8,13}$/.test(mobileNumber(value));}
