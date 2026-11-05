// Dados partilhados com todas as páginas (ver HandleInertiaRequests).

export type UserRole =
    | 'AGRODEALER_OPERATOR'
    | 'AGRODEALER_MANAGER'
    | 'MEAL_OFFICER'
    | 'MEAL_MANAGER'
    | 'FINANCE_OFFICER'
    | 'SYSTEM_ADMIN';

export type PosRef = { id: string; code: string; label: string | null };

export type AuthUser = {
    id: string;
    full_name: string;
    email: string;
    role: UserRole;
    role_label: string;
    province: string | null;
    province_id: string | null;
    agrodealer_id: string | null;
    agrodealer_name: string | null;
    pos_list: PosRef[];
    pos: PosRef | null;
};

export type NavSection = { href: string; label: string; icon: string };

export type Credentials = {
    full_name: string;
    role_label: string;
    email: string;
    password: string;
    kind: 'created' | 'reset';
};

export type SharedProps = {
    auth: { user: AuthUser | null };
    navigation: NavSection[];
    flash: { success: string | null; error: string | null; credentials: Credentials | null };
};
