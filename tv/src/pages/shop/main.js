import { startPage } from '@/app.js';
import { shopAdd, shopBook, shopCart, shopCheckout, shopOrder } from '@/features/shop.js';

startPage({ components: { shopAdd, shopBook, shopCart, shopCheckout, shopOrder } });
