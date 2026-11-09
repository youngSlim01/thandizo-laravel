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
    username?: string | null;
    password: string;
    kind: 'created' | 'reset';
};

// Cliente desta instalação, mostrado junto do nome THANDIZO (config/thandizo.php).
export type Brand = { client: string | null; programme: string | null; tagline: string | null; logos: string[] };

export type AppNotification = { key: string; level: 'critical' | 'attention' | 'info'; label: string; value: number; href: string };

export type SharedProps = {
    auth: { user: AuthUser | null };
    brand: Brand;
    navigation: NavSection[];
    notifications: AppNotification[];
    flash: { success: string | null; error: string | null; credentials: Credentials | null };
};
