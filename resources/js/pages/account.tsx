import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { AdminLayout, initials } from '@/components/admin-layout';
import { PasswordFields } from '@/components/password-fields';
import type { SharedProps } from '@/types';

type Account = {
    full_name: string; email: string; role_label: string;
    agrodealer: string | null; province: string | null;
    last_login_at: string | null; created_at: string | null;
};

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' }) : '—';
}

// O meu perfil: dados e alteração da palavra-passe (antigo /account).
export default function AccountPage({ account }: { account: Account }) {
    const { navigation, flash } = usePage<SharedProps>().props;
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put('/account/password', { preserveScroll: true, onFinish: () => form.reset() });
    }

    const error = form.errors.current_password ?? form.errors.password ?? form.errors.password_confirmation;

    const body = (
        <div className="account-page">
            <Head title="O meu perfil" />
            <h1>O meu perfil</h1>
            <section className="account-card">
                <div className="account-identity">
                    <span className="account-avatar" aria-hidden="true">{initials(account.full_name)}</span>
                    <div><strong>{account.full_name}</strong><span>{account.role_label}</span></div>
                </div>
                <dl className="account-details">
                    <div><dt>Email</dt><dd>{account.email}</dd></div>
                    <div><dt>Função</dt><dd>{account.role_label}</dd></div>
                    {account.agrodealer && <div><dt>Agrodealer</dt><dd>{account.agrodealer}</dd></div>}
                    {account.province && <div><dt>Província</dt><dd>{account.province}</dd></div>}
                    <div><dt>Último acesso</dt><dd>{formatDateTime(account.last_login_at)}</dd></div>
                    <div><dt>Conta criada em</dt><dd>{formatDateTime(account.created_at)}</dd></div>
                </dl>
            </section>

            <section className="account-card">
                <h2>Alterar palavra-passe</h2>
                {flash.success && <div className="success-box">{flash.success}</div>}
                <form className="admin-form change-password" onSubmit={save}>
                    <label className="form-label" htmlFor="pw-current">Palavra-passe atual</label>
                    <input id="pw-current" className="input" type="password" value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} autoComplete="current-password" required />
                    <PasswordFields form={form} />
                    {error && <div className="error-box">{error}</div>}
                    <button className="primary-button" type="submit" disabled={form.processing}>{form.processing ? 'A GUARDAR…' : 'GUARDAR PALAVRA-PASSE'}</button>
                </form>
            </section>

            <Link href="/logout" method="post" as="button" className="secondary-button account-signout">SAIR DA APLICAÇÃO</Link>
        </div>
    );

    // Quem ainda não tem secções no back office (POS, Finanças) vê o perfil sem menu lateral.
    const layout = (children: ReactNode) => navigation.length > 0
        ? <AdminLayout>{children}</AdminLayout>
        : <main className="admin-shell first-password">{children}<Link href="/" className="quick-link">← Início</Link></main>;

    return layout(body);
}
