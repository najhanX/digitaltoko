import definitions from '../packageCatalog.json';

export type PackageDefinition = {key:string;name:string;category:string;plan:string;source:string;description:string;features:string[]};
export const packages: PackageDefinition[] = definitions;
export const packageName = (category:string, _plan:string) => packages.find(item => item.category === category)?.name ?? ({bot:'Bot WhatsApp (paket lama)',web:'Web Topup (paket lama)'} as Record<string,string>)[category] ?? 'Paket toko';
export const packageOfferName = (category:string, plan:string, days:number) => packageName(category,plan)+' · '+days+' hari';
export const packageOptions = Object.fromEntries(packages.map(item => [item.key,item.name+' · '+item.description]));
