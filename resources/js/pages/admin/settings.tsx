import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { firstError } from '@/lib/action';
import type { SharedProps } from '@/types';

type Logo = { id: string; position: number; name: string; url: string };
type Client = { client: string | null; programme: string | null; tagline: string | null };
type Props = { client: Client; canEditOrganization: boolean; logos: Logo[]; maxLogos: number };

function ClientForm({ client, canEditOrganization }: { client: Client; canEditOrganization: boolean }) {
    const form = useForm({ name: client.client ?? '', programme: client.programme ?? '', tagline: client.tagline ?? '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put('/admin/settings/client', { preserveScroll: true });
    }

    const error = form.errors.name ?? form.errors.programme ?? form.errors.tagline ?? (form.errors as Record<string, string>).rule;

    return (
        <form className="admin-card admin-form" onSubmit={save}>
            <span className="section-label">Cliente</span>
            <p className="muted-text small-note">
                Aparece junto do nome Thandizo: o nome da organização nos cabeçalhos, no recibo e nos PDFs;
                o programa no cartão e no rodapé do menu; a frase na página de entrada. Deixe vazio o que não se aplica.
            </p>
            <div className="form-grid-2">
                <div><label className="form-label" htmlFor="client-name">Organização</label>
                    <input id="client-name" className="input" maxLength={120} placeholder="Ex.: Save the Children" disabled={!canEditOrganization}
                        value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    {!canEditOrganization && <div className="muted-text small-note">Só o administrador do sistema muda o nome da organização.</div>}</div>
                <div><label className="form-label" htmlFor="client-programme">Programa</label>
                    <input id="client-programme" className="input" maxLength={120} placeholder="Ex.: Projecto LINK GCF"
                        value={form.data.programme} onChange={(e) => form.setData('programme', e.target.value)} /></div>
            </div>
            <label className="form-label" htmlFor="client-tagline">Frase do cliente (opcional)</label>
            <input id="client-tagline" className="input" maxLength={160} placeholder="Mostrada na página de entrada, por baixo do nome da organização"
                value={form.data.tagline} onChange={(e) => form.setData('tagline', e.target.value)} />
            {error && <div className="error-box">{error}</div>}
            <div className="admin-form-actions">
                <button type="submit" className="primary-button" disabled={form.processing || !form.isDirty}>{form.processing ? 'A GUARDAR…' : 'GUARDAR CLIENTE'}</button>
            </div>
        </form>
    );
}

// Configuração da instalação: o cliente e os logótipos impressos nos cartões.
// O gestor MEAL muda o programa, a frase e os logótipos; o nome da
// organização só o administrador do sistema.
export default function Settings({ client, canEditOrganization, logos, maxLogos }: Props) {
    const { flash } = usePage<SharedProps>().props;
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

            <ClientForm client={client} canEditOrganization={canEditOrganization} />

            <h2>Logótipos do cartão</h2>
            <p className="muted-text">
                Até {maxLogos} logótipos, impressos da esquerda para a direita na faixa verde do cartão, com 8 mm de altura.
                Use PNG, JPG ou WebP até 1 MB, de preferência com fundo transparente e pelo menos 300 px de altura.
            </p>
            <div className="warning-box">Os cartões já impressos não mudam: só os próximos lotes saem com os logótipos novos. Evite trocá-los a meio de uma distribuição.</div>

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
