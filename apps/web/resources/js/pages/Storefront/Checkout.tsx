import { ShopLayout, type Shop } from '../../components/Commerce';
import CheckoutForm, { type CheckoutOptions, type CheckoutProduct } from '../../components/CheckoutForm';
export default function Checkout({store,product,...options}:{store:Shop;product:CheckoutProduct}&CheckoutOptions) {
    return <ShopLayout store={store} title="Lengkapi pesanan"><CheckoutForm product={product} {...options}/></ShopLayout>;
}
