// Relationship of the person who receives the card for the representative.
// Stored as the code; the household data never changes.
export const RELATIONSHIPS: { code: string; label: string }[] = [
  { code: 'CONJUGE', label: 'Cônjuge' },
  { code: 'FILHO', label: 'Filho/a' },
  { code: 'PAI_MAE', label: 'Pai/Mãe' },
  { code: 'IRMAO', label: 'Irmão/Irmã' },
  { code: 'NETO', label: 'Neto/a' },
  { code: 'OUTRO_FAMILIAR', label: 'Outro familiar' },
];

export function relationshipLabel(code: string | null | undefined) {
  return RELATIONSHIPS.find((r) => r.code === code)?.label ?? code ?? '';
}
