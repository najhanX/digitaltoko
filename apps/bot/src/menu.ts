import type { PurchaseAction } from './commerce.js';
import { randomBytes } from 'node:crypto';

export type BrowseQuery={type:'browse';view:'services'|'categories'|'products';page:number;service?:string;category?:string};
export type MenuAction=PurchaseAction|BrowseQuery|'services'|'menu'|'products'|'status'|'balance'|'topup'|{type:'payment-method';checkoutId:string;payment:'wallet'|'gateway'}|{type:'confirm-checkout';checkoutId:string}|{type:'select-product';code:string}|{type:'product-input';code:string;input:string}|{type:'order-status';number:string}|{type:'products';page:number;search:string};
export type MenuScope={chatAliases?:readonly string[];senderAliases?:readonly string[]};
type Entry={chats:string[];senders:string[];action:MenuAction;expires:number};
const bindings=(id:string,aliases:readonly string[]=[])=>[...new Set([id,...aliases])].slice(0,3);
const intersects=(stored:readonly string[],current:readonly string[])=>stored.some(id=>current.includes(id));
type Checkout={id:string;chats:string[];senders:string[];purchase:PurchaseAction;summary:string[];stage:'payment'|'confirm';messages:unknown[];expires:number};
export class MenuTokens {
    private entries=new Map<string,Entry>();
    private inputs=new Map<string,{chats:string[];senders:string[];code:string;expires:number}>();
    private checkouts=new Map<string,Checkout>();
    constructor(private now:()=>number=Date.now) {}
    private checkout(chat:string,sender:string,scope:MenuScope={}):Checkout|null {
        for(const item of this.checkouts.values())if(intersects(item.chats,bindings(chat,scope.chatAliases))&&intersects(item.senders,bindings(sender,scope.senderAliases)))return item.expires>this.now()?item:null;
        return null;
    }
    checkoutId(chat:string,sender:string,scope:MenuScope={}):string|null{return this.checkout(chat,sender,scope)?.id??null;}
    clearCheckout(chat:string,sender:string,scope:MenuScope={}) {
        for(const [key,item] of this.checkouts)if(intersects(item.chats,bindings(chat,scope.chatAliases))&&intersects(item.senders,bindings(sender,scope.senderAliases)))this.checkouts.delete(key);
    }
    beginCheckout(chat:string,sender:string,purchase:PurchaseAction,summary:string[],scope:MenuScope={}):string {
        this.clearCheckout(chat,sender,scope);
        for(const [key,item] of this.checkouts)if(item.expires<=this.now())this.checkouts.delete(key);
        if(this.checkouts.size>=5000)this.checkouts.delete(this.checkouts.keys().next().value!);
        const id=randomBytes(18).toString('hex');
        this.checkouts.set(chat,{id,chats:bindings(chat,scope.chatAliases),senders:bindings(sender,scope.senderAliases),purchase,summary,stage:'payment',messages:[],expires:this.now()+300_000});
        return id;
    }
    checkoutMessages(chat:string,sender:string,scope:MenuScope={}):unknown[]{return this.checkout(chat,sender,scope)?.messages??[];}
    rememberCheckoutMessage(chat:string,sender:string,key:unknown,scope:MenuScope={}){const item=this.checkout(chat,sender,scope);if(item&&key)item.messages.push(key);}
    paymentPurchase(chat:string,sender:string,id:string,scope:MenuScope={}):PurchaseAction|null {
        const item=this.checkout(chat,sender,scope);
        return item?.id===id && item.stage==='payment' ? item.purchase : null;
    }
    choosePayment(chat:string,sender:string,id:string,payment:'wallet'|'gateway',scope:MenuScope={}):Checkout|null {
        const item=this.checkout(chat,sender,scope);
        if(!item||item.id!==id||item.stage!=='payment')return null;
        item.purchase={...item.purchase,payment};item.stage='confirm';return item;
    }
    confirmCheckout(chat:string,sender:string,id:string,scope:MenuScope={}):PurchaseAction|null {
        const item=this.checkout(chat,sender,scope);
        if(!item||item.id!==id||item.stage!=='confirm')return null;
        this.clearCheckout(chat,sender,scope);return item.purchase;
    }
    waitForProduct(chat:string,sender:string,code:string,scope:MenuScope={}) {
        this.clearProductInput(chat,sender,scope);
        for(const [id,item] of this.inputs)if(item.expires<=this.now())this.inputs.delete(id);
        if(this.inputs.size>=5000)this.inputs.delete(this.inputs.keys().next().value!);
        this.inputs.set(chat,{chats:bindings(chat,scope.chatAliases),senders:bindings(sender,scope.senderAliases),code,expires:this.now()+300_000});
    }
    takeProductInput(chat:string,sender:string,scope:MenuScope={}):{code:string;expired:boolean}|null {
        for(const [id,item] of this.inputs)if(intersects(item.chats,bindings(chat,scope.chatAliases))&&intersects(item.senders,bindings(sender,scope.senderAliases))){
            this.inputs.delete(id);return {code:item.code,expired:item.expires<=this.now()};
        }
        return null;
    }
    clearProductInput(chat:string,sender:string,scope:MenuScope={}) {this.takeProductInput(chat,sender,scope);}
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
