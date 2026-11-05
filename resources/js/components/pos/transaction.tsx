import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';
import { RequestError, requestJson } from '@/lib/http';

// Estado de uma venda no POS.
//
// O browser nunca decide dinheiro: guarda o voucher, o carrinho e o desconto
// pedido, pede a pré-visualização ao servidor, mostra-a ao agregado e
// confirma com os valores mostrados. O servidor recusa a confirmação
// (PREVIEW_OUTDATED) se alguma coisa mudou entretanto.

export type Product = {
    id: string;
    code: string;
    name: string;
    unit: string;
    listPrice: number; // só para orientar; o preço da venda é o do servidor
};

export type CartItem = Product & { quantity: number };

export type DiscountType = 'none' | 'PERCENTAGE' | 'FIXED_AMOUNT';

export type Voucher = { code: string; householdCode: string; representativeName: string; balance: number };

export type Preview = {
    subtotal: number; discount_amount: number; total_amount: number; voucher_amount: number;
    copayment_amount: number; balance_before: number; balance_after: number;
};

export type Receipt = {
    transaction_id: string; transaction_code: string; status: string; confirmed_at: string;
    agrodealer_name: string; pos_code: string; operator_name: string;
    voucher_code: string; household_code: string; representative_name: string;
    items: { product_code: string; product_name: string; unit: string; quantity: number; unit_price: number; line_total: number }[];
    subtotal: number; discount_amount: number; total_amount: number; voucher_amount: number; copayment_amount: number;
    voucher_balance_after: number | null; voided: boolean; voided_at: string | null; void_reason: string | null;
};

export type ConfirmResult = { success: boolean; duplicate: boolean; transaction_id: string; transaction_code: string; receipt: Receipt };

type TransactionValue = {
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
    clearCart: () => void;
    setDiscount: (type: DiscountType, value: number) => void;
    runPreview: () => Promise<Preview>;
    confirm: () => Promise<ConfirmResult>;
    resetTransaction: () => void;
};

function newIdempotencyKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? `POS-${crypto.randomUUID()}`
        : `POS-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

const TransactionContext = createContext<TransactionValue | null>(null);

export function TransactionProvider({ children }: { children: ReactNode }) {
    const [voucher, setVoucherState] = useState<Voucher | null>(null);
    const [cart, setCart] = useState<CartItem[]>([]);
    const [discountType, setDiscountType] = useState<DiscountType>('none');
    const [discountValue, setDiscountValue] = useState(0);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [result, setResult] = useState<ConfirmResult | null>(null);
    // Uma chave por venda: repetir depois de uma falha de rede usa a mesma, e
    // o servidor devolve a venda já feita em vez de debitar duas vezes.
    const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey);

    function setVoucher(next: Voucher) {
        setVoucherState(next);
        setPreview(null);
    }

    function addProduct(product: Product) {
        setCart((current) => current.some((item) => item.id === product.id)
            ? current.map((item) => (item.id === product.id ? { ...item, quantity: item.quantity + 1 } : item))
            : [...current, { ...product, quantity: 1 }]);
        setPreview(null);
    }

    function changeQuantity(productId: string, delta: number) {
        setCart((current) => current
            .map((item) => (item.id === productId ? { ...item, quantity: Math.max(0, item.quantity + delta) } : item))
            .filter((item) => item.quantity > 0));
        setPreview(null);
    }

    function clearCart() {
        setCart([]);
        setPreview(null);
    }

    function setDiscount(type: DiscountType, value: number) {
        setDiscountType(type);
        setDiscountValue(type === 'none' ? 0 : Math.max(0, value));
        setPreview(null);
    }

    const estimatedSubtotal = useMemo(() => cart.reduce((sum, item) => sum + item.listPrice * item.quantity, 0), [cart]);

    function sale() {
        return {
            voucher_code: voucher?.code ?? '',
            items: cart.map((item) => ({ product_id: item.id, quantity: item.quantity })),
            discount_type: discountType === 'none' ? null : discountType,
            discount_value: discountType === 'none' ? 0 : discountValue,
        };
    }

    async function runPreview(): Promise<Preview> {
        const data = await requestJson<Preview>('POST', '/pos/preview', sale());
        setPreview(data);
        return data;
    }

    async function confirm(): Promise<ConfirmResult> {
        if (!preview) throw new Error('Calcule o pagamento antes de confirmar.');
        try {
            const data = await requestJson<ConfirmResult>('POST', '/pos/sales', {
                idempotency_key: idempotencyKey,
                ...sale(),
                expected_total: preview.total_amount,
                expected_voucher_amount: preview.voucher_amount,
            });
            setResult(data);
            return data;
        } catch (err) {
            // Nada foi gravado: calcula de novo para o operador mostrar os novos
            // valores ao agregado antes de confirmar outra vez.
            if (err instanceof RequestError && err.code === 'PREVIEW_OUTDATED') {
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
            setVoucher, addProduct, changeQuantity, clearCart, setDiscount, runPreview, confirm, resetTransaction,
        }}>
            {children}
        </TransactionContext.Provider>
    );
}

export function useTransaction(): TransactionValue {
    const context = useContext(TransactionContext);
    if (!context) throw new Error('useTransaction só funciona dentro do PosLayout.');
    return context;
}
