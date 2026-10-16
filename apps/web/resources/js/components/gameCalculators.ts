export function winsNeeded(matches:number,current:number,target:number):number {
    if(!Number.isSafeInteger(matches) || matches<=0 || matches>1_000_000_000 || !Number.isFinite(current) || !Number.isFinite(target) || current<0 || current>100 || target<0 || target>100)throw new Error('Isi total pertandingan positif dan persentase antara 0–100.');
    if(target<=current)return 0;
    if(target===100)throw new Error('Win rate 100% tidak dapat dicapai jika sebelumnya sudah pernah kalah.');
    return Math.max(0,Math.ceil((matches*(target-current))/(100-target)-1e-10));
}

export function diamondEstimate(kind:'magic-wheel'|'zodiac',points:number):number {
    const max=kind==='magic-wheel'?200:100;
    if(!Number.isInteger(points) || points<0 || points>max)throw new Error('Poin harus berupa bilangan bulat antara 0 dan '+max+'.');
    const remaining=max-points;
    const batch=kind==='magic-wheel'?270:85;
    const single=kind==='magic-wheel'?60:20;
    return Math.floor(remaining/5)*batch+(remaining%5)*single;
}
