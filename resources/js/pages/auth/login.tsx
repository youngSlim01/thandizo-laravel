import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ThandizoMark } from '@/components/thandizo-mark';
import type { SharedProps } from '@/types';

function UserIcon() {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 21c.8-4 4-6.5 8-6.5s7.2 2.5 8 6.5" /></svg>
    );
}

function LockIcon() {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2" /><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" /><path d="M12 14.5v2.5" /></svg>
    );
}

function EyeIcon({ open }: { open: boolean }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" /><circle cx="12" cy="12" r="3" />
            {!open && <path d="M4 20 20 4" />}
        </svg>
    );
}

// Entrada no Thandizo. O formulário é enviado ao Laravel (POST /login), que
// valida a palavra-passe e abre a sessão; os erros voltam em form.errors.
// A sessão fica sempre lembrada neste aparelho (remember).
export default function Login() {
    const { flash, brand } = usePage<SharedProps>().props;
    const form = useForm({ login: '', password: '', remember: true });
    const [showPassword, setShowPassword] = useState(false);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    // Envia o link de recuperação para o email da conta escrita no campo
    // (nome de utilizador ou e-mail).
    function recoverPassword() {
        form.clearErrors();
        router.post('/forgot-password', { login: form.data.login.trim() }, {
            preserveState: true,
            onError: (errors) => form.setError('login', errors.login),
        });
    }

    const error = form.errors.login ?? form.errors.password;
    const client = [brand.client, brand.programme].filter(Boolean).join(' · ');

    return (
        <main className="tl-page">
            <Head title="Entrar" />
            <section className="tl-card">
                <div className="tl-photo">
                    <div className="tl-photo-shade" aria-hidden="true" />
                    <div className="tl-logo"><ThandizoMark /><span>Thandizo</span></div>
                    <div className="tl-message">
                        <span className="tl-accent" aria-hidden="true" />
                        <h1>Empowering programmes.<br />Improving lives.</h1>
                    </div>
                </div>

                <div className="tl-panel">
                    <div className="tl-panel-inner">
                        <h2>Entrar na plataforma</h2>
                        <form onSubmit={submit} className="tl-form">
                            <label className="tl-field">
                                <span className="tl-field-icon"><UserIcon /></span>
                                <input id="login" type="text" autoComplete="username" autoCapitalize="none" spellCheck={false}
                                    placeholder="Nome de utilizador ou e-mail" aria-label="Nome de utilizador ou e-mail"
                                    value={form.data.login} onChange={(e) => form.setData('login', e.target.value)}
                                    aria-invalid={Boolean(form.errors.login)} required />
                            </label>
                            <label className="tl-field">
                                <span className="tl-field-icon"><LockIcon /></span>
                                <input id="password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" placeholder="Palavra-passe"
                                    aria-label="Palavra-passe" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)}
                                    aria-invalid={Boolean(form.errors.password)} required />
                                <button type="button" className="tl-eye" onClick={() => setShowPassword((visible) => !visible)}
                                    aria-label={showPassword ? 'Ocultar palavra-passe' : 'Mostrar palavra-passe'} aria-pressed={showPassword}>
                                    <EyeIcon open={!showPassword} />
                                </button>
                            </label>
                            <button type="button" className="tl-recover" onClick={recoverPassword}>Esqueceu a sua palavra-passe?</button>

                            {error && <div className="tl-alert tl-alert-error" role="alert">{error}</div>}
                            {!error && flash.success && <div className="tl-alert tl-alert-success" role="status">{flash.success}</div>}

                            <button className="tl-submit" type="submit" disabled={form.processing}>
                                {form.processing ? 'A entrar…' : <>Entrar <span aria-hidden="true">→</span></>}
                            </button>
                        </form>

                        <footer className="tl-footer">
                            <span>Thandizo v1.0</span>
                            <span aria-hidden="true">|</span>
                            <span>© {new Date().getFullYear()}. Todos os direitos reservados.</span>
                            {client && <small className="tl-footer-client">{client}</small>}
                        </footer>
                    </div>
                </div>
            </section>
        </main>
    );
}
