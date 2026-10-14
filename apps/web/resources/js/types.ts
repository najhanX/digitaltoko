export type StoreSummary = { id:string; name:string; slug:string; category:string; plan:string; status:string; template:string; url:string; expires_at:string|null; is_trial?:boolean; starts_at?:string|null; billing_status?:string|null };

export type WorkspaceContext = {
    role: 'owner'|'staff'|'admin'; viewer: {name: string};
    stores: Pick<StoreSummary,'id'|'name'|'category'|'plan'|'status'>[];
    currentStoreId: string|null; permissions: string[]; canCreate: boolean; verificationStatus?: string; platformDomain?: string;
};
