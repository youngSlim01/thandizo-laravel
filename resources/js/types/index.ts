// Dados partilhados com todas as páginas (ver HandleInertiaRequests).

export type UserRole =
    | 'AGRODEALER_OPERATOR'
    | 'AGRODEALER_MANAGER'
    | 'MEAL_OFFICER'
    | 'MEAL_MANAGER'
    | 'FINANCE_OFFICER'
    | 'SYSTEM_ADMIN';

export type AuthUser = {
    id: string;
    full_name: string;
    email: string;
    role: UserRole;
    role_label: string;
    can: { view_districts: boolean; view_products: boolean };
};

export type SharedProps = {
    auth: { user: AuthUser | null };
    flash: { success: string | null; error: string | null };
};
