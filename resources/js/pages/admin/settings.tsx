import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { firstError } from '@/lib/action';
import { PRODUCT_TAGLINE } from '@/lib/brand';
import type { SharedProps } from '@/types';

type Logo = { id: string; position: number; name: string; url: string };
type Props = { logos: Logo[]; maxLogos: number };

// Configuração da instalação: o cliente (definido no .env) e os logótipos
// impressos nos cartões, carregados aqui pelo administrador.
export default function Settings({ logos, maxLogos }: Props) {
    const { brand, flash } = usePage<SharedProps>().props;
    const form = useForm<{ logo: File | null }>({ logo: null });
    const fileInput = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | null>(null);

    function upload(event: FormEvent) {
        event.preventDefault();
        form.post('/admin/settings/logos', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { form.reset(); if (fileInput.current) fileInput.current.value = ''; },
        });
    }

    function change(method: 'put' | 'delete', url: string) {
        setError(null);
        const options = { preserveScroll: true, onError: (errors: Record<string, string>) => setError(firstError(errors)) };
        if (method === 'put') router.put(url, {}, options);
        else router.delete(url, options);
    }

    const full = logos.length >= maxLogos;

    return (
        <AdminLayout>
            <Head title="Configuração" />
            <div className="admin-page-heading"><div><div className="eyebrow">ADMINISTRAÇÃO</div><h1>Configuração</h1>
                <p>O cliente desta instalação e os logótipos impressos nos cartões.</p></div></div>
            {flash.success && <div className="success-box">{flash.success}</div>}
            {error && <div className="error-box">{error}</div>}

            <section className="admin-card">
                <span className="section-label">Cliente</span>
                <div className="finance-info-list">
                    <div><span>Produto</span><strong>THANDIZO · {PRODUCT_TAGLINE}</strong></div>
                    <div><span>Organização</span><strong>{brand.client ?? '—'}</strong></div>
                    <div><span>Programa</span><strong>{brand.programme ?? '—'}</strong></div>
                    <div><span>Frase do cliente</span><strong>{brand.tagline ?? '—'}</strong></div>
                </div>
                <p className="muted-text small-note">Estes dados vêm da configuração da instalação (THANDIZO_CLIENT_NAME, THANDIZO_PROGRAMME_NAME e THANDIZO_CLIENT_TAGLINE no .env).</p>
            </section>

            <h2>Logótipos do cartão</h2>
            <p className="muted-text">
                Até {maxLogos} logótipos, impressos da esquerda para a direita na faixa verde do cartão, com 8 mm de altura.
                Use PNG, JPG ou WebP até 1 MB, de preferência com fundo transparente e pelo menos 300 px de altura.
            </p>

            <div className="card-logo-list">
                {logos.map((logo, index) => (
                    <div className="card card-logo" key={logo.id}>
                        <div className="card-logo-image"><img src={logo.url} alt={`Logótipo ${logo.position}`} /></div>
                        <div className="muted-text small-note">{logo.position}. {logo.name}</div>
                        <div className="batch-actions">
                            {index > 0 && <button className="quick-link" onClick={() => change('put', `/admin/settings/logos/${logo.id}/move-left`)}>← Para a esquerda</button>}
                            <button className="quick-link danger" onClick={() => change('delete', `/admin/settings/logos/${logo.id}`)}>Retirar</button>
                        </div>
                    </div>
                ))}
                {logos.length === 0 && <p className="muted-text">Ainda não há logótipos: os cartões mostram só o nome do programa.</p>}
            </div>

            {full ? (
                <div className="info-box">O cartão já tem {maxLogos} logótipos. Retire um para carregar outro.</div>
            ) : (
                <form className="admin-form" onSubmit={upload}>
                    <label className="form-label" htmlFor="logo-file">Novo logótipo</label>
                    <input id="logo-file" ref={fileInput} className="input" type="file" accept="image/png,image/jpeg,image/webp"
                        onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)} />
                    {form.errors.logo && <div className="error-box">{form.errors.logo}</div>}
                    <button className="primary-button" disabled={form.processing || !form.data.logo}>{form.processing ? 'A CARREGAR…' : 'CARREGAR LOGÓTIPO'}</button>
                </form>
            )}
        </AdminLayout>
    );
}
