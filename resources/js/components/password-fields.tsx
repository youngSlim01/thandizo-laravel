import type { InertiaFormProps } from '@inertiajs/react';

type PasswordData = { password: string; password_confirmation: string };

// Nova palavra-passe + repetição, como o ChangePasswordForm do sistema Next.js.
export function PasswordFields<T extends PasswordData>({ form }: { form: InertiaFormProps<T> }) {
    const data = form.data as PasswordData;
    const set = form.setData as unknown as (key: keyof PasswordData, value: string) => void;
    return (
        <>
            <label className="form-label" htmlFor="pw-new">Nova palavra-passe</label>
            <input id="pw-new" className="input" type="password" value={data.password} onChange={(e) => set('password', e.target.value)} autoComplete="new-password" required />
            <label className="form-label" htmlFor="pw-confirm">Repetir a nova palavra-passe</label>
            <input id="pw-confirm" className="input" type="password" value={data.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} autoComplete="new-password" required />
            <div className="muted-text small-note">Mínimo 8 caracteres. Não a partilhe com ninguém.</div>
        </>
    );
}
