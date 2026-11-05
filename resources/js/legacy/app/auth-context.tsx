import { router, usePage } from '@inertiajs/react';
import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';
import type { SharedProps } from '@/types';

// Camada de compatibilidade da migração: os ecrãs vindos do Next.js usam
// useAuth() como antes, mas a sessão e o perfil vêm agora do Laravel (props
// partilhadas do Inertia, ver HandleInertiaRequests). Só o POS escolhido fica
// no browser (localStorage), como no sistema antigo.

export type Operator = {
    id: string;
    fullName: string;
    role: string;
    agrodealerId: string | null;
    agrodealerName: string | null;
    mustChangePassword: boolean;
    provinceId: string | null;
    provinceName: string | null;
};

export type Pos = { id: string; code: string; label: string | null };

export const SELLER_ROLES = ['AGRODEALER_OPERATOR', 'AGRODEALER_MANAGER'];
export const USER_ADMIN_ROLES = ['SYSTEM_ADMIN', 'MEAL_MANAGER'];
export const HOUSEHOLD_ROLES = ['SYSTEM_ADMIN', 'MEAL_MANAGER', 'MEAL_OFFICER'];
export const BACK_OFFICE_ROLES = Array.from(new Set([...USER_ADMIN_ROLES, ...HOUSEHOLD_ROLES, 'FINANCE_OFFICER']));

const POS_STORAGE_KEY = 'voucher-link.pos-id';

function readStoredPosId(): string | null {
    try { return window.localStorage.getItem(POS_STORAGE_KEY); } catch { return null; }
}

function storePosId(posId: string | null) {
    try {
        if (posId) window.localStorage.setItem(POS_STORAGE_KEY, posId);
        else window.localStorage.removeItem(POS_STORAGE_KEY);
    } catch { /* sem localStorage: o POS escolhe-se de novo */ }
}

type AuthValue = {
    loading: boolean;
    session: { user: { email: string } } | null;
    operator: Operator | null;
    posList: Pos[];
    pos: Pos | null;
    profileError: string | null;
    signIn: (email: string, password: string) => Promise<void>;
    signOut: () => Promise<void>;
    selectPos: (posId: string) => void;
    refreshProfile: () => Promise<void>;
};

const PosChoice = createContext<{ chosen: string | null; choose: (id: string | null) => void } | null>(null);

// Mantém a escolha do POS entre ecrãs (o layout do POS é persistente).
export function AuthProvider({ children }: { children: ReactNode }) {
    const [chosen, setChosen] = useState<string | null>(readStoredPosId);
    const value = useMemo(() => ({ chosen, choose: (id: string | null) => { storePosId(id); setChosen(id); } }), [chosen]);
    return <PosChoice.Provider value={value}>{children}</PosChoice.Provider>;
}

export function useAuth(): AuthValue {
    const { auth } = usePage<SharedProps>().props;
    const choice = useContext(PosChoice);
    const [localChoice, setLocalChoice] = useState<string | null>(readStoredPosId);
    const chosen = choice ? choice.chosen : localChoice;
    const choose = choice ? choice.choose : (id: string | null) => { storePosId(id); setLocalChoice(id); };

    const user = auth.user;
    const operator: Operator | null = user && {
        id: user.id,
        fullName: user.full_name,
        role: user.role,
        agrodealerId: user.agrodealer_id,
        agrodealerName: user.agrodealer_name,
        mustChangePassword: false,
        provinceId: user.province_id,
        provinceName: user.province,
    };
    const posList: Pos[] = user?.pos_list ?? [];
    const pos = posList.find((p) => p.id === chosen) ?? (posList.length === 1 ? posList[0] : null);

    return {
        loading: false,
        session: user ? { user: { email: user.email } } : null,
        operator,
        posList,
        pos,
        profileError: null,
        signIn: async () => { /* o login é feito pela página /login do Laravel */ },
        signOut: async () => { storePosId(null); router.post('/logout'); },
        selectPos: (posId: string) => choose(posList.some((p) => p.id === posId) ? posId : null),
        refreshProfile: async () => { router.reload(); },
    };
}
