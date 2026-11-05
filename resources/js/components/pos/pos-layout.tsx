import { router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { firstError } from '@/lib/action';
import type { SharedProps } from '@/types';
import { TransactionProvider } from './transaction';

export function signOut() {
    router.post('/logout');
}

// Layout de todas as páginas do POS. É persistente (ver app.tsx): a venda em
// curso sobrevive entre os passos. Sem POS escolhido, pede primeiro o
// terminal em que o operador está a trabalhar.
export function PosLayout({ children }: { children: ReactNode }) {
    const user = usePage<SharedProps>().props.auth.user;
    const [error, setError] = useState<string | null>(null);

    if (user && !user.pos) {
        const choose = (posId: string) => router.post('/pos/terminal', { pos_id: posId }, {
            preserveScroll: true, onError: (errors) => setError(firstError(errors)),
        });
        return (
            <main className="pos-shell">
                <section className="pos-content">
                    <div className="eyebrow">{user.agrodealer_name}</div>
                    <h1>Escolher POS</h1>
                    {user.pos_list.length === 0 ? (
                        <div className="error-box">A sua loja não tem nenhum POS ativo. Contacte o MEAL.</div>
                    ) : (
                        <>
                            <p>Indique em que terminal está a trabalhar.</p>
                            <div className="quick-list">
                                {user.pos_list.map((pos) => (
                                    <button key={pos.id} className="quick-link" onClick={() => choose(pos.id)}>
                                        {pos.code}{pos.label ? ` — ${pos.label}` : ''}
                                    </button>
                                ))}
                            </div>
                        </>
                    )}
                    {error && <div className="error-box">{error}</div>}
                    <button className="secondary-button" onClick={signOut}>SAIR</button>
                </section>
            </main>
        );
    }

    return <TransactionProvider>{children}</TransactionProvider>;
}

// Volta a pedir o terminal (para quem tem mais de um POS).
export function changePos() {
    router.delete('/pos/terminal');
}
