// Amounts in MT: no decimals when the value is whole, two otherwise.
export function formatMT(value: number | string | null | undefined): string {
  const amount = Number(value ?? 0);
  const hasCents = Math.round(amount * 100) % 100 !== 0;
  return `${amount.toLocaleString('pt-MZ', {
    minimumFractionDigits: hasCents ? 2 : 0,
    maximumFractionDigits: 2,
  })} MT`;
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '';
  return new Date(value).toLocaleString('pt-MZ', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function formatTime(value: string | null | undefined): string {
  if (!value) return '';
  return new Date(value).toLocaleTimeString('pt-MZ', { hour: '2-digit', minute: '2-digit' });
}

// Data aaaa-mm-dd em dd/mm/aaaa (sem passar por fusos horários).
export function formatDate(value: string | null | undefined): string {
    if (!value) return '—';
    const [y, m, d] = value.slice(0, 10).split('-');
    return d && m && y ? `${d}/${m}/${y}` : value;
}
