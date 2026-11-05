import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PasswordFields } from '@/components/password-fields';

// Página aberta a partir do link do email de recuperação.
export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    }

    const error = form.errors.email ?? form.errors.password ?? form.errors.password_confirmation;

    return (
        <main className="admin-shell first-password">
            <Head title="Escolher nova palavra-passe" />
            <div className="brand">THANDIZO</div>
            <h1>Escolher nova palavra-passe</h1>
            <p className="muted-text">Conta: {email}</p>
            <form className="admin-form change-password" onSubmit={save}>
                <PasswordFields form={form} />
                {error && <div className="error-box">{error}</div>}
                <button className="primary-button" type="submit" disabled={form.processing}>{form.processing ? 'A GUARDAR…' : 'GUARDAR PALAVRA-PASSE'}</button>
            </form>
            <Link href="/login" className="quick-link">← Voltar ao login</Link>
        </main>
    );
}
