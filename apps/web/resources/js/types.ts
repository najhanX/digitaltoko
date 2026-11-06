export type StoreSummary = { id:string; name:string; slug:string; category:string; plan:string; status:string; template:string; url:string; expires_at:string|null; sandbox?:boolean; is_trial?:boolean; starts_at?:string|null; billing_status?:string|null; admin_suspended_at?:string|null; admin_suspension_reason?:string|null };

export type WorkspaceContext = {
    role: 'owner'|'staff'|'admin'; viewer: {name: string};
    stores: Pick<StoreSummary,'id'|'name'|'category'|'plan'|'status'|'admin_suspended_at'|'admin_suspension_reason'|'sandbox'>[];
    currentStoreId: string|null; permissions: string[]; canCreate: boolean; verificationStatus?: string; identityRequired?: boolean; platformDomain?: string;
};
