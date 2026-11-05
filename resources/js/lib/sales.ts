// Venda do próprio operador nas últimas 24 horas (PosService::recent).
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
};

export function isToday(value: string): boolean {
    return new Date(value).toDateString() === new Date().toDateString();
}
