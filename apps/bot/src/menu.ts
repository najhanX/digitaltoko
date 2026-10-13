import { randomBytes } from 'node:crypto';

export type MenuAction='products'|'status'|'balance';
type Entry={chat:string;sender:string;action:MenuAction;expires:number};
export class MenuTokens {
    private entries=new Map<string,Entry>();
    constructor(private now:()=>number=Date.now) {}
    issue(chat:string,sender:string,action:MenuAction):string {
        for (const [id,e] of this.entries) if(e.expires<=this.now()) this.entries.delete(id);
        if (this.entries.size>=5000) this.entries.delete(this.entries.keys().next().value!);
        const id='dt:'+randomBytes(18).toString('hex');
        this.entries.set(id,{chat,sender,action,expires:this.now()+900_000});
        return id;
    }
    consume(id:string,chat:string,sender:string):MenuAction|null {
        const entry=this.entries.get(id);
        if(!entry||entry.expires<=this.now()||entry.chat!==chat||entry.sender!==sender) return null;
        this.entries.delete(id);
        return entry.action;
    }
}
