import { Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { formatMT } from '@/lib/format';

// Peças comuns aos painéis (MEAL, Finanças): ícones, números, barras e
// painéis de alerta, com as classes cd-* de app.css.

export type Alert = { key: string; label: string; value: number; href: string; money?: boolean; level?: 'critical' | 'attention' | 'info' };

export type IconName = 'users' | 'wallet' | 'clock' | 'store' | 'alert' | 'flag' | 'people' | 'map' | 'list' | 'chart' | 'layers' | 'calendar' | 'check' | 'inbox';

export function Icon({ name }: { name: IconName }) {
  const paths: Record<IconName, ReactElement> = {
    users: <><circle cx="9" cy="8" r="3" /><circle cx="17" cy="9" r="2.4" /><path d="M3 20a6 6 0 0 1 12 0M14 17a5 5 0 0 1 7 3" /></>,
    wallet: <><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H20v14H5.5A2.5 2.5 0 0 1 3 16.5v-9Z" /><path d="M16 12h5v4h-5a2 2 0 1 1 0-4Z" /></>,
    clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    store: <><path d="M4 10v10h16V10" /><path d="M3 10 5 4h14l2 6" /><path d="M9 20v-5h6v5" /></>,
    alert: <><path d="M12 3 2 20h20L12 3Z" /><path d="M12 10v4M12 17h.01" /></>,
    flag: <><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16h.01" /></>,
    people: <><circle cx="8" cy="8" r="3" /><circle cx="16" cy="8" r="3" /><path d="M2 20a6 6 0 0 1 12 0M10 20a6 6 0 0 1 12 0" /></>,
    map: <><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11Z" /><circle cx="12" cy="10" r="2.5" /></>,
    list: <><path d="M9 6h12M9 12h12M9 18h12" /><path d="M4 6h.01M4 12h.01M4 18h.01" /></>,
    chart: <><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" /></>,
    layers: <><path d="m12 3 9 5-9 5-9-5 9-5Z" /><path d="m3 13 9 5 9-5" /></>,
    check: <><rect x="4" y="3" width="16" height="18" rx="2" /><path d="m8 12 3 3 5-6" /></>,
    inbox: <><path d="M3 13h5l2 3h4l2-3h5" /><path d="M5 5h14l2 8v6H3v-6l2-8Z" /></>,
    calendar: <><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M7 2v4M17 2v4M3 9h18" /></>,
  };
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.9} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {paths[name]}
    </svg>
  );
}

export const pct = (share: number) => `${Math.round(share * 100)}%`;
export const share = (part: number, whole: number) => (whole > 0 ? part / whole : 0);
export const number = (n: number) => n.toLocaleString('pt-PT');

export function Bar({ value, tone = 'green' }: { value: number; tone?: 'green' | 'amber' | 'grey' }) {
  return <div className={`cd-bar cd-bar-${tone}`}><i style={{ width: `${Math.min(100, Math.round(value * 100))}%` }} /></div>;
}

// Pastilha de percentagem: vermelho abaixo de 50%, âmbar até 70%, verde acima.
export function Pill({ value, children }: { value: number; children?: string }) {
  const tone = value < 0.5 ? 'red' : value < 0.7 ? 'amber' : 'green';
  return <span className={`cd-pill cd-pill-${tone}`}>{children ?? pct(value)}</span>;
}

// Alertas numa só lista, por gravidade; só o que tem valor.
export function AlertList({ alerts, empty = 'Nada a assinalar.' }: { alerts: Alert[]; empty?: string }) {
  const shown = alerts.filter((a) => a.value > 0);
  return (
    <div className="cd-alerts">
      {shown.map((a) => (
        <Link key={a.key} href={a.href} className={`cd-alert-item cd-alert-${a.level ?? 'attention'}`}>
          <b aria-hidden="true">{a.level === 'info' ? 'i' : '!'}</b>
          <span><strong>{a.money ? formatMT(a.value) : number(a.value)}</strong> {a.label.charAt(0).toLowerCase() + a.label.slice(1)}</span>
          <em aria-hidden="true">›</em>
        </Link>
      ))}
      {shown.length === 0 && <p className="cd-note">{empty}</p>}
    </div>
  );
}

// Anel com o total ao centro e a parte destacada (0 a 1).
export function Donut({ part, total, label }: { part: number; total: number; label: string }) {
  const value = Math.max(0, Math.min(1, part)) * 100;
  return (
    <svg viewBox="0 0 42 42" className="cd-donut" role="img" aria-label={`${number(total)} ${label}`}>
      <circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--brand-border)" strokeWidth="6" />
      <circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--brand)" strokeWidth="6" strokeDasharray={`${value} ${100 - value}`} transform="rotate(-90 21 21)" />
      <text x="21" y="21.5" textAnchor="middle" fontSize="7" fontWeight="800" fill="var(--text)">{number(total)}</text>
      <text x="21" y="27.5" textAnchor="middle" fontSize="3.6" fill="var(--muted)">{label}</text>
    </svg>
  );
}
