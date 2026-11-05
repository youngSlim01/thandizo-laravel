
import Link from 'next/link';
import { useAuth } from '../auth-context';

// Name and role of the signed-in user, top right of every back-office screen.
// Opens the profile (details + change password).

export const ROLE_LABELS: Record<string, string> = {
  SYSTEM_ADMIN: 'Administrador do sistema',
  MEAL_MANAGER: 'Gestor MEAL',
  MEAL_OFFICER: 'Oficial MEAL',
  FINANCE_OFFICER: 'Oficial de Finanças',
  AGRODEALER_MANAGER: 'Gestor do agrodealer',
  AGRODEALER_OPERATOR: 'Operador de POS',
};

export const initials = (name: string | null | undefined) =>
  (name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '··';

export function UserMenu({ className = '' }: { className?: string }) {
  const auth = useAuth();
  const role = auth.operator?.role ?? '';
  return (
    <Link href="/account" className={`user-menu ${className}`} title="Abrir o meu perfil">
      <span className="user-menu-avatar" aria-hidden="true">{initials(auth.operator?.fullName)}</span>
      <span className="user-menu-text">
        <strong>{auth.operator?.fullName ?? 'Utilizador'}</strong>
        <small>{ROLE_LABELS[role] ?? role}{auth.operator?.provinceName ? ` · ${auth.operator.provinceName}` : ''}</small>
      </span>
    </Link>
  );
}
