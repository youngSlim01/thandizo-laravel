import { Head, router, useForm, usePage } from '@inertiajs/react';
import { brandSubtitle, PRODUCT_TAGLINE } from '@/lib/brand';
import type { SharedProps } from '@/types';
import type { FormEvent } from 'react';
import { ThandizoMark } from '@/components/thandizo-mark';

// Mesmo ecrã e mesmas classes CSS do login Next.js (app/login/page.tsx).
// A diferença: o formulário é enviado ao Laravel (POST /login), que valida a
// palavra-passe e abre a sessão. Os erros voltam em form.errors.
export default function Login() {
    const { flash, brand } = usePage<SharedProps>().props;
    const form = useForm({ email: '', password: '', remember: true });

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
        <main className="thandizo-login">
            <Head title="Entrar" />
            <section className="thandizo-login-visual">
                <div className="thandizo-login-visual-bg" aria-hidden="true" />
                <div className="thandizo-login-overlay" aria-hidden="true" />

                <div className="thandizo-brand thandizo-brand-light">
                    <span className="thandizo-brand-icon"><ThandizoMark /></span>
                    <span>
                        <strong>THANDIZO</strong>
                        <small>{PRODUCT_TAGLINE.toUpperCase()}</small>
                    </span>
                </div>

                <div className="thandizo-login-message">
                    <span className="thandizo-accent" />
                    <h1>Conectando<br />pessoas a<br />oportunidades</h1>
                    <p>Uma plataforma digital para uma assistência mais eficiente, transparente e digna.</p>

                    <div className="thandizo-features">
                        <div><span>♙</span><strong>Beneficiários</strong><small>Registo e gestão segura</small></div>
                        <div><span>▣</span><strong>E-Vouchers</strong><small>Distribuição e uso flexível</small></div>
                        <div><span>⌂</span><strong>Agrodealeres</strong><small>Compras em múltiplos pontos</small></div>
                        <div><span>▥</span><strong>Monitoria</strong><small>Dados para melhores decisões</small></div>
                    </div>
                </div>

                <div className="thandizo-login-footer-message">
                    <span className="thandizo-accent" />
                    <p>ASSISTÊNCIA HOJE,<br />COMUNIDADES MAIS RESILIENTES AMANHÃ.</p>
                </div>
            </section>

            <section className="thandizo-login-panel">
                <div className="thandizo-language">🇲🇿 <span>Português (PT)</span><span>⌄</span></div>

                <div className="thandizo-login-card">
                    <div className="thandizo-card-brand">
                        <span className="thandizo-brand-icon"><ThandizoMark /></span>
                        <span>
                            <strong>THANDIZO</strong>
                            <small>{brandSubtitle(brand).toUpperCase()}</small>
                        </span>
                    </div>

                    <div className="thandizo-welcome">
                        <h2>Bem-vindo(a)</h2>
                        <p>Faça o seu login para aceder ao sistema.</p>
                    </div>

                    <form onSubmit={submit} className="thandizo-login-form">
                        <label htmlFor="email">Email ou nome de utilizador</label>
                        <div className="thandizo-input-wrap">
                            <span>♙</span>
                            <input
                                id="email"
                                type="email"
                                autoComplete="username"
                                placeholder="Ex.: nome@organizacao.org"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                required
                            />
                        </div>

                        <label htmlFor="password">Palavra-passe</label>
                        <div className="thandizo-input-wrap">
                            <span>⌑</span>
                            <input
                                id="password"
                                type="password"
                                autoComplete="current-password"
                                placeholder="Introduza a sua palavra-passe"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                required
                            />
                        </div>

                        <div className="thandizo-login-options">
                            <label className="thandizo-check">
                                <input
                                    type="checkbox"
                                    checked={form.data.remember}
                                    onChange={(e) => form.setData('remember', e.target.checked)}
                                />
                                <span>Manter sessão iniciada</span>
                            </label>
                            <button type="button" className="thandizo-link" onClick={recoverPassword}>Esqueceu a palavra-passe?</button>
                        </div>

                        {error && <div className="error-box">{error}</div>}
                        {!error && flash.success && <div className="success-box">{flash.success}</div>}

                        <button className="thandizo-login-button" type="submit" disabled={form.processing}>
                            {form.processing ? 'A ENTRAR…' : <>Entrar <span>→</span></>}
                        </button>
                    </form>

                    <div className="thandizo-divider"><span>ou</span></div>

                    <div className="thandizo-card-footer">
                        <div>
                            {brand.client && <strong>◉ {brand.client}</strong>}
                            {brand.tagline && <p>{brand.tagline}</p>}
                        </div>
                        <div className="thandizo-version">
                            <strong>THANDIZO v2.0</strong>
                            <span>© 2026. Todos os direitos reservados.</span>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    );
}
