// Regra do programa, garantida pelo servidor (COPAYMENT_LIMIT_EXCEEDED): o
// copagamento não pode passar de 20% do total da compra. O POS só a usa para
// avisar o operador de até onde o voucher chega.
export const COPAYMENT_LIMIT = 0.2;

// Maior total que o saldo permite: o voucher paga pelo menos 80%.
export function maxPurchase(balance: number): number {
  return Math.floor((balance / (1 - COPAYMENT_LIMIT)) * 100) / 100;
}
