import { randomBytes } from 'node:crypto';

export type BrowseQuery={type:'browse';view:'services'|'categories'|'products';page:number;service?:string;category?:string};
export type MenuAction=BrowseQuery|'services'|'menu'|'products'|'status'|'balance'|{type:'products';page:number;search:string};
export type MenuScope={chatAliases?:readonly string[];senderAliases?:readonly string[]};
type Entry={chats:string[];senders:string[];action:MenuAction;expires:number};
const bindings=(id:string,aliases:readonly string[]=[])=>[...new Set([id,...aliases])].slice(0,3);
const intersects=(stored:readonly string[],current:readonly string[])=>stored.some(id=>current.includes(id));
export class MenuTokens {
    private entries=new Map<string,Entry>();
    constructor(private now:()=>number=Date.now) {}
    issue(chat:string,sender:string,action:MenuAction,scope:MenuScope={}):string {
        for (const [id,e] of this.entries) if(e.expires<=this.now()) this.entries.delete(id);
        if (this.entries.size>=5000) this.entries.delete(this.entries.keys().next().value!);
        const id='dt:'+randomBytes(18).toString('hex');
        this.entries.set(id,{chats:bindings(chat,scope.chatAliases),senders:bindings(sender,scope.senderAliases),action,expires:this.now()+900_000});
        return id;
    }
    consume(id:string,chat:string,sender:string,scope:MenuScope={}):MenuAction|null {
        const entry=this.entries.get(id);
        if(!entry||entry.expires<=this.now()||!intersects(entry.chats,bindings(chat,scope.chatAliases))||!intersects(entry.senders,bindings(sender,scope.senderAliases))) return null;
        this.entries.delete(id);
        return entry.action;
    }
}
