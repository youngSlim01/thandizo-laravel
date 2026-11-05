import { callRpc } from './supabase';

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

// The logged-in operator's own sales from the last 24 hours.
export function listMyRecentSales(limit = 50): Promise<RecentSale[]> {
  return callRpc<RecentSale[]>('list_my_recent_transactions', { p_limit: limit });
}

export function isToday(value: string): boolean {
  return new Date(value).toDateString() === new Date().toDateString();
}
