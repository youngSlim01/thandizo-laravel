import { usePage } from '@inertiajs/react';
import type { Brand, SharedProps } from '@/types';

// O produto chama-se sempre THANDIZO; a organização cliente aparece junto do
// nome. Sem cliente configurado, fica a descrição do produto.
export const PRODUCT_TAGLINE = 'E-voucher assistance platform';

export function useBrand(): Brand {
    return usePage<SharedProps>().props.brand;
}

// Linha por baixo de THANDIZO no cartão: o programa, ou a organização.
export function cardSubtitle(brand: Brand): string {
    return brand.programme ?? brand.client ?? '';
}

// Linha por baixo de THANDIZO nos cabeçalhos.
export function brandSubtitle(brand: Brand): string {
    return brand.client ?? PRODUCT_TAGLINE;
}
