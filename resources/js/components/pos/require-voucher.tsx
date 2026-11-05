import { router } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import { useTransaction } from './transaction';

// Os passos depois do voucher não fazem sentido sem um voucher validado (por
// exemplo depois de recarregar a página, que apaga a venda em curso).
export function RequireVoucher({ children, cart = false }: { children: ReactNode; cart?: boolean }) {
    const transaction = useTransaction();
    const noVoucher = !transaction.voucher && !transaction.result;
    const noCart = cart && transaction.cart.length === 0 && !transaction.result;

    useEffect(() => {
        if (noVoucher) router.visit('/pos/voucher', { replace: true });
        else if (noCart) router.visit('/pos/products', { replace: true });
    }, [noVoucher, noCart]);

    return noVoucher || noCart ? null : <>{children}</>;
}
