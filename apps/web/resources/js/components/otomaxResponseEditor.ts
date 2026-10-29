export type ResponseCapture={field:string;prefix:string|null;suffix:string|null;required:boolean};
export type ResponseRule = {name:string;event:string;outcome:string;active:boolean;template?:string;matcher?:'template'|'boundaries'|'regex';keyword?:string;regex?:string;captures?:ResponseCapture[];case_sensitive?:boolean;priority?:number};
export type ResponseConfig={text_status_map?:Record<string,string>;message_field:string;ref_field:string;status_field:string;price_field:string;status_map:Record<string,string>;rules:ResponseRule[]};
export const responseFields:Record<string,string>={id_transaksi:'ID transaksi supplier',kode_produk:'Kode produk',tujuan:'Tujuan',status:'Status teks',sn:'SN / referensi hasil',refid:'RefID pesanan',harga:'Harga beli',saldo_awal:'Saldo awal',saldo_akhir:'Saldo akhir',saldo:'Saldo',tanggal:'Tanggal',jam:'Jam',keterangan:'Keterangan',amount:'Nominal',nama_pelanggan:'Nama pelanggan tagihan',tagihan:'Tagihan pokok',admin:'Admin tagihan',total_tagihan:'Total tagihan termasuk admin',periode:'Periode tagihan'};
export const responseEvents:Record<string,string> = {
    transaction:'Balasan awal cek tagihan / transaksi', callback:'Callback / laporan susulan',
    status:'Balasan cek status', balance:'Balasan cek saldo', ticket:'Balasan tiket deposit',
};
export const responseOutcomes:Record<string,string> = {success:'Berhasil',failed:'Gagal pasti',pending:'Sedang diproses'};

export function responseRuleSelection(rules:ResponseRule[], index:number): string | null {
    const rule=rules[index];
    return rule?JSON.stringify({index,name:rule.name,event:rule.event,signature:JSON.stringify([rule.matcher??'template',rule.template,rule.keyword,rule.regex,rule.captures]),template:rule.template}):null;
}

export function restoreResponseRule(rules:ResponseRule[], saved:string|null): number {
    if(!saved||!rules.length)return 0;
    try {
        const selection=JSON.parse(saved);
        if(!selection||typeof selection.name!=='string'||typeof selection.event!=='string')return 0;
        const exact=rules.findIndex(rule=>rule.name===selection.name&&rule.event===selection.event&&(selection.signature?JSON.stringify([rule.matcher??'template',rule.template,rule.keyword,rule.regex,rule.captures])===selection.signature:rule.template===selection.template));
        if(exact>=0)return exact;
        const renamedTemplate=rules.findIndex(rule=>rule.name===selection.name&&rule.event===selection.event);
        return renamedTemplate>=0?renamedTemplate:0;
    } catch {return 0;}
}

export function newResponseRule(preset?:ResponseRule): ResponseRule {
    return preset?{...preset,captures:preset.captures?.map(capture=>({...capture}))}:{name:'Penangkap baru',event:'transaction',outcome:'pending',active:true,template:'',matcher:'boundaries',keyword:'',captures:[],case_sensitive:false,priority:0};
}

export function incompleteResponseRule(rule:ResponseRule): boolean {
    if(!rule.name.trim())return true;
    if(rule.matcher==='boundaries')return !rule.keyword?.trim()||!rule.captures?.length||rule.captures.some(c=>(c.prefix??'')===''&&(c.suffix??'')==='')||new Set(rule.captures.map(c=>c.field)).size!==rule.captures.length;
    if(rule.matcher==='regex')return !rule.regex?.trim();
    return !rule.template?.trim();
}
export type CaptureSpan={field:string;value:string;start:number;length:number};
export function responseSegments(text:string,captures:CaptureSpan[]):{text:string;field?:string}[]{
    const chars=Array.from(text),segments:{text:string;field?:string}[]=[];let cursor=0;
    for(const capture of [...captures].sort((a,b)=>a.start-b.start||b.length-a.length)){
        if(!Number.isInteger(capture.start)||!Number.isInteger(capture.length)||capture.start<cursor||capture.length<=0||capture.start+capture.length>chars.length)continue;
        if(capture.start>cursor)segments.push({text:chars.slice(cursor,capture.start).join('')});
        segments.push({text:chars.slice(capture.start,capture.start+capture.length).join(''),field:capture.field});cursor=capture.start+capture.length;
    }
    if(cursor<chars.length)segments.push({text:chars.slice(cursor).join('')});return segments;
}

export function depositResponseExample(): ResponseRule {
    return {name:'Instruksi tiket deposit',event:'ticket',outcome:'pending',active:true,matcher:'boundaries',keyword:'Silakan Transfer Rp.',case_sensitive:false,priority:0,captures:[{field:'amount',prefix:'Silakan Transfer Rp. ',suffix:' (HARUS SAMA)',required:true}]};
}
