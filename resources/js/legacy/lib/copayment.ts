// Programme rule, enforced by the database (COPAYMENT_LIMIT_EXCEEDED):
// co-payment may not exceed 20% of the purchase total. The POS only uses this
// to tell the operator in advance how far a voucher can go.
export const COPAYMENT_LIMIT = 0.2;

// Largest total a voucher balance allows: the voucher pays at least 80%.
export function maxPurchase(balance: number): number {
  return Math.floor((balance / (1 - COPAYMENT_LIMIT)) * 100) / 100;
}
