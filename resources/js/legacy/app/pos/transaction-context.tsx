
import { createContext, useContext, useMemo, useState } from 'react';
import { useAuth } from '../auth-context';
import { callRpc } from '../../lib/supabase';
import { errorCode } from '../../lib/errors';

// State of one POS sale.
//
// The browser never decides money: it keeps the voucher, the cart and the
// requested discount, asks the database for a preview, shows that preview to
// the household, and confirms with the previewed amounts. The database
// rejects the confirmation (PREVIEW_OUTDATED) if anything changed.

export type Product = {
  id: string;
  code: string;
  name: string;
  unit: string;
  listPrice: number; // shown for guidance only; the server prices the sale
};

export type CartItem = Product & { quantity: number };

export type DiscountType = 'none' | 'PERCENTAGE' | 'FIXED_AMOUNT';

export type Voucher = {
  code: string;
  householdCode: string;
  representativeName: string;
  balance: number;
};

export type Preview = {
  subtotal: number;
  discount_amount: number;
  total_amount: number;
  voucher_amount: number;
  copayment_amount: number;
  balance_before: number;
  balance_after: number;
};

export type Receipt = {
  transaction_id: string;
  transaction_code: string;
  status: string;
  confirmed_at: string;
  agrodealer_name: string;
  pos_code: string;
  operator_name: string;
  voucher_code: string;
  household_code: string;
  representative_name: string;
  items: { product_code: string; product_name: string; unit: string; quantity: number; unit_price: number; line_total: number }[];
  subtotal: number;
  discount_amount: number;
  total_amount: number;
  voucher_amount: number;
  copayment_amount: number;
  voucher_balance_after: number | null;
  voided: boolean;
  voided_at: string | null;
  void_reason: string | null;
};

export type ConfirmResult = {
  success: boolean;
  duplicate: boolean;
  transaction_id: string;
  transaction_code: string;
  receipt: Receipt;
};

type TransactionContextValue = {
  voucher: Voucher | null;
  cart: CartItem[];
  discountType: DiscountType;
  discountValue: number;
  estimatedSubtotal: number;
  preview: Preview | null;
  result: ConfirmResult | null;
  setVoucher: (voucher: Voucher) => void;
  addProduct: (product: Product) => void;
  changeQuantity: (productId: string, delta: number) => void;
  setDiscount: (type: DiscountType, value: number) => void;
  runPreview: () => Promise<Preview>;
  confirm: () => Promise<ConfirmResult>;
  resetTransaction: () => void;
};

function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return `POS-${crypto.randomUUID()}`;
  }
  return `POS-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

const TransactionContext = createContext<TransactionContextValue | null>(null);

export function TransactionProvider({ children }: { children: React.ReactNode }) {
  const auth = useAuth();
  const [voucher, setVoucherState] = useState<Voucher | null>(null);
  const [cart, setCart] = useState<CartItem[]>([]);
  const [discountType, setDiscountType] = useState<DiscountType>('none');
  const [discountValue, setDiscountValue] = useState(0);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [result, setResult] = useState<ConfirmResult | null>(null);
  // One key per sale: retries after a network error reuse it, so the
  // database returns the original sale instead of debiting twice.
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);

  // Any change to the sale makes the previous preview invalid.
  function invalidate() {
    setPreview(null);
  }

  function setVoucher(next: Voucher) {
    setVoucherState(next);
    invalidate();
  }

  function addProduct(product: Product) {
    setCart((current) => {
      const existing = current.find((item) => item.id === product.id);
      if (existing) {
        return current.map((item) => (item.id === product.id ? { ...item, quantity: item.quantity + 1 } : item));
      }
      return [...current, { ...product, quantity: 1 }];
    });
    invalidate();
  }

  function changeQuantity(productId: string, delta: number) {
    setCart((current) => current
      .map((item) => (item.id === productId ? { ...item, quantity: Math.max(0, item.quantity + delta) } : item))
      .filter((item) => item.quantity > 0));
    invalidate();
  }

  function setDiscount(type: DiscountType, value: number) {
    setDiscountType(type);
    setDiscountValue(type === 'none' ? 0 : Math.max(0, value));
    invalidate();
  }

  const estimatedSubtotal = useMemo(
    () => cart.reduce((sum, item) => sum + item.listPrice * item.quantity, 0),
    [cart],
  );

  function saleParams() {
    return {
      p_voucher_code: voucher?.code ?? '',
      p_pos_id: auth.pos?.id ?? null,
      p_items: cart.map((item) => ({ product_id: item.id, quantity: item.quantity })),
      p_discount_type: discountType === 'none' ? null : discountType,
      p_discount_value: discountType === 'none' ? 0 : discountValue,
    };
  }

  async function runPreview(): Promise<Preview> {
    const params = saleParams();
    const data = await callRpc<Preview>('preview_voucher_redemption', {
      p_voucher_code: params.p_voucher_code,
      p_agrodealer_id: auth.operator?.agrodealerId ?? null,
      p_pos_id: params.p_pos_id,
      p_operator_id: auth.operator?.id ?? null,
      p_items: params.p_items,
      p_discount_type: params.p_discount_type,
      p_discount_value: params.p_discount_value,
    });
    setPreview(data);
    return data;
  }

  async function confirm(): Promise<ConfirmResult> {
    if (!preview) throw new Error('EXPECTED_AMOUNTS_REQUIRED');
    try {
      const data = await callRpc<ConfirmResult>('confirm_pos_redemption', {
        p_idempotency_key: idempotencyKey,
        ...saleParams(),
        p_expected_total: preview.total_amount,
        p_expected_voucher_amount: preview.voucher_amount,
      });
      setResult(data);
      return data;
    } catch (err) {
      // Nothing was saved: refresh the preview so the operator can show the
      // new amounts to the household before confirming again.
      if (errorCode(err) === 'PREVIEW_OUTDATED') {
        await runPreview().catch(() => setPreview(null));
      }
      throw err;
    }
  }

  function resetTransaction() {
    setVoucherState(null);
    setCart([]);
    setDiscountType('none');
    setDiscountValue(0);
    setPreview(null);
    setResult(null);
    setIdempotencyKey(newIdempotencyKey());
  }

  return (
    <TransactionContext.Provider value={{
      voucher, cart, discountType, discountValue, estimatedSubtotal, preview, result,
      setVoucher, addProduct, changeQuantity, setDiscount, runPreview, confirm, resetTransaction,
    }}>
      {children}
    </TransactionContext.Provider>
  );
}

export function useTransaction() {
  const context = useContext(TransactionContext);
  if (!context) throw new Error('useTransaction must be used inside TransactionProvider');
  return context;
}
