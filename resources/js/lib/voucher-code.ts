// Os códigos dos cartões são do tipo VCH-MUT-000123-7: o último dígito é um
// dígito de controlo Luhn do número de série (CardService), por isso a
// maioria dos erros de escrita apanha-se antes de consultar o servidor.
const CARD_CODE = /^VCH-[A-Z]{3}-(\d{6})-(\d)$/;

export function luhnCheckDigit(digits: string): number {
  let sum = 0;
  let double = true;
  for (let i = digits.length - 1; i >= 0; i--) {
    let d = Number(digits[i]);
    if (double) { d *= 2; if (d > 9) d -= 9; }
    sum += d;
    double = !double;
  }
  return (10 - (sum % 10)) % 10;
}

// Falso só para um código de cartão cujo dígito de controlo não bate certo.
// Outros códigos ficam para o servidor decidir.
export function cardCodeLooksValid(code: string): boolean {
  const match = CARD_CODE.exec(code.trim().toUpperCase());
  return !match || luhnCheckDigit(match[1]) === Number(match[2]);
}
