// Venda do próprio operador nas últimas 24 horas (PosService::recent) ou, para
// o gestor do comerciante, da loja no ciclo (MerchantAccountService::sales).
export type RecentSale = {
    transaction_id: string;
    transaction_code: string;
    status: string;
    confirmed_at: string;
    voucher_code: string;
    total_amount: number;
    voucher_amount: number;
    copayment_amount: number;
    can_void: boolean;
    void_deadline: string;
    operator_name?: string | null;
    mine?: boolean;
};

export function isToday(value: string): boolean {
    return new Date(value).toDateString() === new Date().toDateString();
}
