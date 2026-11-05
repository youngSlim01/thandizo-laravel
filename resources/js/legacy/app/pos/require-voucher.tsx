
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTransaction } from './transaction-context';

// Steps after the voucher screen make no sense without a validated voucher
// (for example after a page refresh, which clears the sale state).
export function RequireVoucher({ children, cart = false }: { children: React.ReactNode; cart?: boolean }) {
  const transaction = useTransaction();
  const router = useRouter();
  const missing = !transaction.voucher || (cart && transaction.cart.length === 0 && !transaction.result);

  useEffect(() => {
    if (!transaction.voucher && !transaction.result) router.replace('/pos/voucher');
    else if (cart && transaction.cart.length === 0 && !transaction.result) router.replace('/pos/products');
  }, [transaction.voucher, transaction.cart.length, transaction.result, cart, router]);

  if (missing && !transaction.result) return null;
  return <>{children}</>;
}
