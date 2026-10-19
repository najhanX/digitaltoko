export function paymentSecondsRemaining(expiresAt:string,now:number):number|null {
    const deadline=Date.parse(expiresAt);
    return Number.isFinite(deadline)?Math.max(0,Math.ceil((deadline-now)/1000)):null;
}

export function paymentClock(seconds:number):string {
    const value=Math.max(0,Math.floor(seconds));
    const hours=Math.floor(value/3600),minutes=Math.floor(value%3600/60),remainder=value%60;
    return [...(hours?[String(hours).padStart(2,'0')]:[]),String(minutes).padStart(2,'0'),String(remainder).padStart(2,'0')].join(':');
}
