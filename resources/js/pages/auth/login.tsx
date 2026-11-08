import { Head, router, useForm, usePage } from '@inertiajs/react';
import { PRODUCT_TAGLINE } from '@/lib/brand';
import type { SharedProps } from '@/types';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { ThandizoMark } from '@/components/thandizo-mark';

// Mesmo ecrã e mesmas classes CSS do login Next.js (app/login/page.tsx).
// A diferença: o formulário é enviado ao Laravel (POST /login), que valida a
// palavra-passe e abre a sessão. Os erros voltam em form.errors.
export default function Login() {
    const { flash, brand } = usePage<SharedProps>().props;
    const client = [brand.client, brand.programme].filter(Boolean).join(' · ');
    const form = useForm({ email: '', password: '', remember: true });
    const [showPassword, setShowPassword] = useState(false);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    // Envia o link de recuperação para o email escrito no campo.
    function recoverPassword() {
        form.clearErrors();
        router.post('/forgot-password', { email: form.data.email.trim() }, {
            preserveState: true,
            onError: (errors) => form.setError('email', errors.email),
        });
    }

    const error = form.errors.email ?? form.errors.password;

    return (
        <main className="thandizo-login-minimal">
            <Head title="Entrar" />
            <section className="thandizo-login-shell">
                <div className="thandizo-login-photo" aria-label="Comunidade em contexto de desenvolvimento">
                    <div className="thandizo-login-photo-overlay" aria-hidden="true" />
                    <div className="thandizo-photo-brand">
                        <span className="thandizo-brand-icon"><ThandizoMark /></span>
                        <strong>Thandizo</strong>
                    </div>
                    <div className="thandizo-photo-message">
                        <span className="thandizo-photo-accent" />
                        <h1>Empowering programmes.<br />Improving lives.</h1>
                        <p>Uma plataforma integrada para fortalecer programas e melhorar vidas.</p>
                    </div>
                    <div className="thandizo-photo-wave" />
                </div>
                <section className="thandizo-login-form-panel">
                    <div className="thandizo-login-form-inner">
                        <div className="thandizo-form-brand">
                            <span className="thandizo-brand-icon"><ThandizoMark /></span>
                            <strong>Thandizo</strong>
                            <p>{PRODUCT_TAGLINE}</p>
                        </div>
                        {client && (
                            <div className="thandizo-login-client">
                                <span>{client}</span>
                                {brand.tagline && <small>{brand.tagline}</small>}
                            </div>
                        )}
                        <header className="thandizo-login-heading">
                            <h2>Entrar na plataforma</h2>
                            <p>Aceda à sua conta para continuar.</p>
                        </header>
                        <form onSubmit={submit} className="thandizo-login-minimal-form">
                            <label className="thandizo-minimal-field" htmlFor="email">
                                <span className="thandizo-field-icon" aria-hidden="true">♙</span>
                                <input id="email" type="text" autoComplete="username"
                                    placeholder="E-mail"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    aria-invalid={Boolean(form.errors.email)} required />
                            </label>
                            <label className="thandizo-minimal-field" htmlFor="password">
                                <span className="thandizo-field-icon" aria-hidden="true">♙</span>
                                <input id="password" type={showPassword ? 'text' : 'password'}
                                    autoComplete="current-password" placeholder="Palavra-passe"
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                    aria-invalid={Boolean(form.errors.password)} required />
                                <button type="button" className="thandizo-password-toggle"
                                    onClick={() => setShowPassword((visible) => !visible)}
                                    aria-label={showPassword ? 'Ocultar palavra-passe' : 'Mostrar palavra-passe'}
                                    aria-pressed={showPassword}>{showPassword ? '◎' : '◉'}</button>
                            </label>
                            <div className="thandizo-minimal-options">
                                <label className="thandizo-remember">
                                    <input type="checkbox" checked={form.data.remember}
                                        onChange={(e) => form.setData('remember', e.target.checked)} />
                                    <span>Manter sessão iniciada</span>
                                </label>
                                <button type="button" className="thandizo-recovery-link" onClick={recoverPassword}>
                                    Esqueceu a sua palavra-passe?
                                </button>
                            </div>
                            {error && <div className="thandizo-auth-error" role="alert">{error}</div>}
                            {!error && flash.success && <div className="thandizo-auth-success" role="status">{flash.success}</div>}
                            <button className="thandizo-minimal-submit" type="submit" disabled={form.processing}>
                                {form.processing ? 'A entrar…' : <>Entrar <span aria-hidden="true">→</span></>}
                            </button>
                        </form>
                        <footer className="thandizo-login-legal">
                            <span>THANDIZO v2.0</span><span aria-hidden="true">|</span>
                            <span>© {new Date().getFullYear()}. Todos os direitos reservados.</span>
                        </footer>
                    </div>
                </section>
            </section>
        </main>
    );
}
