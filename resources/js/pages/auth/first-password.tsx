import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PasswordFields } from '@/components/password-fields';
import type { SharedProps } from '@/types';

// Enquanto a conta tiver a palavra-passe que outra pessoa escolheu, só este
// ecrã está disponível (EnsureAccountIsUsable). Igual ao ForcePasswordChange
// do sistema Next.js.
export default function FirstPassword() {
    const { auth } = usePage<SharedProps>().props;
    const form = useForm({ password: '', password_confirmation: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put('/password/first', { onFinish: () => form.reset() });
    }

    const error = form.errors.password ?? form.errors.password_confirmation;

    return (
        <main className="admin-shell first-password">
            <Head title="Defina a sua palavra-passe" />
            <div className="brand">THANDIZO</div>
            <h1>Defina a sua palavra-passe</h1>
            <p className="muted-text">
                Olá, {auth.user?.full_name}. A palavra-passe que recebeu é temporária. Escolha uma nova para continuar.
            </p>
            <form className="admin-form change-password" onSubmit={save}>
                <PasswordFields form={form} />
                {error && <div className="error-box">{error}</div>}
                <button className="primary-button" type="submit" disabled={form.processing}>{form.processing ? 'A GUARDAR…' : 'GUARDAR E CONTINUAR'}</button>
            </form>
            <Link href="/logout" method="post" as="button" className="quick-link">Sair</Link>
        </main>
    );
}
