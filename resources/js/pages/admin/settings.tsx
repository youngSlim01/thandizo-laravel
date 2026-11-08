import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { firstError } from '@/lib/action';
import { formatMT } from '@/lib/format';
import type { SharedProps } from '@/types';

type Logo = { id: string; position: number; name: string; url: string };
type Client = { client: string | null; programme: string | null; tagline: string | null };
type Tier = { id: string; name: string; amount: number; description: string | null; is_default: boolean; requires_household_code: boolean; status: 'ACTIVE' | 'INACTIVE'; households: number };
type Props = { client: Client; canEditOrganization: boolean; logos: Logo[]; maxLogos: number; tiers: Tier[]; settlementDeadlineDays: number };

function TierForm({ tier, onDone }: { tier: Tier | null; onDone: () => void }) {
    const form = useForm({
        name: tier?.name ?? '', amount: tier ? String(tier.amount) : '', description: tier?.description ?? '',
        requires_household_code: tier?.requires_household_code ?? true,
    });
    const options = { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } };

    function save(event: FormEvent) {
        event.preventDefault();
        if (tier) form.put(`/admin/settings/tiers/${tier.id}`, options);
        else form.post('/admin/settings/tiers', options);
    }

    const error = form.errors.name ?? form.errors.amount ?? form.errors.description ?? (form.errors as Record<string, string>).rule;
    const changingAmount = tier !== null && form.data.amount !== '' && Number(form.data.amount.replace(',', '.')) !== tier.amount;

    return (
        <form className="admin-form tier-form" onSubmit={save}>
            <strong>{tier ? `Editar ${tier.name}` : 'Novo escalão'}</strong>
            <div className="form-grid-2">
                <div><label className="form-label" htmlFor="tier-name">Nome</label>
                    <input id="tier-name" className="input" maxLength={80} placeholder="Ex.: Grupo A — muito vulnerável"
                        value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></div>
                <div><label className="form-label" htmlFor="tier-amount">Valor por ativação e recarga (MT)</label>
                    <input id="tier-amount" className="input" inputMode="decimal" placeholder="Ex.: 7500"
                        value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /></div>
            </div>
            <label className="form-label" htmlFor="tier-description">Quem fica neste escalão (opcional)</label>
            <input id="tier-description" className="input" maxLength={255} placeholder="Ex.: agregados chefiados por mulheres com mais de 5 membros"
                value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
            <label className="checkbox-row">
                <input type="checkbox" checked={form.data.requires_household_code}
                    onChange={(e) => form.setData('requires_household_code', e.target.checked)} />
                Exige CODIGO AF
            </label>
            {!form.data.requires_household_code && (
                <div className="muted-text small-note">
                    Os agregados deste escalão podem não ter CODIGO AF: o sistema gera um (ex.: TZ-MUT-000001), que é definitivo.
                </div>
            )}
            {changingAmount && tier.households > 0 && (
                <div className="warning-box">
                    O novo valor vale para as próximas ativações e recargas dos {tier.households} agregados deste escalão. O que já está nos cartões não muda.
                </div>
            )}
            {error && <div className="error-box">{error}</div>}
            <div className="admin-form-actions">
                <button type="button" className="secondary-button" onClick={onDone} disabled={form.processing}>CANCELAR</button>
                <button type="submit" className="primary-button" disabled={form.processing || !form.isDirty}>{form.processing ? 'A GUARDAR…' : 'GUARDAR ESCALÃO'}</button>
            </div>
        </form>
    );
}

// Prazo das Finanças: dias para validar uma reconciliação depois de a
// receberem; passado o prazo, o painel das Finanças avisa.
function SettlementDeadline({ days }: { days: number }) {
    const form = useForm({ days: String(days) });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put('/admin/settings/settlement-deadline', { preserveScroll: true });
    }

    const error = form.errors.days ?? (form.errors as Record<string, string>).rule;

    return (
        <form className="admin-card admin-form" onSubmit={save}>
            <span className="section-label">Prazo de validação das reconciliações</span>
            <p className="muted-text small-note">Dias que as Finanças têm para validar uma reconciliação depois de a receberem do MEAL. Passado o prazo, o painel das Finanças avisa.</p>
            <div className="form-grid-2">
                <div><label className="form-label" htmlFor="settlement-days">Prazo (dias)</label>
                    <input id="settlement-days" className="input" type="number" min={1} max={90} value={form.data.days}
                        onChange={(e) => form.setData('days', e.target.value)} /></div>
            </div>
            {error && <div className="error-box">{error}</div>}
            <div className="admin-form-actions">
                <button type="submit" className="primary-button" disabled={form.processing || !form.isDirty}>{form.processing ? 'A GUARDAR…' : 'GUARDAR PRAZO'}</button>
            </div>
        </form>
    );
}

// Escalões do voucher: cada agregado recebe o valor do seu escalão.
function Tiers({ tiers, onAction }: { tiers: Tier[]; onAction: (method: 'put', url: string, data?: Record<string, string>) => void }) {
    const [editing, setEditing] = useState<Tier | 'new' | null>(null);

    return (
        <>
            <h2>Escalões do voucher</h2>
            <p className="muted-text">
                Cada agregado pertence a um escalão e recebe o valor desse escalão quando o cartão é ativado e em cada recarga do ciclo.
                Na importação, a coluna VALOR escolhe o escalão; sem ela, o agregado fica no escalão padrão. Dois escalões ativos não podem ter o mesmo valor.
                Num escalão que não exige CODIGO AF, o agregado sem código recebe um gerado pelo sistema.
            </p>
            <div className="tier-table">
                <table className="import-errors">
                    <thead><tr><th>Escalão</th><th>Valor</th><th>CODIGO AF</th><th>Agregados ativos</th><th>Estado</th><th /></tr></thead>
                    <tbody>
                        {tiers.map((tier) => (
                            <tr key={tier.id} className={tier.status === 'ACTIVE' ? undefined : 'muted-row'}>
                                <td><strong>{tier.name}</strong>{tier.is_default && <span className="badge tier-default">padrão</span>}
                                    {tier.description && <div className="muted-text small-note">{tier.description}</div>}</td>
                                <td>{formatMT(tier.amount)}</td>
                                <td>{tier.requires_household_code ? 'Obrigatório' : 'Gerado se faltar'}</td>
                                <td>{tier.households}</td>
                                <td>{tier.status === 'ACTIVE' ? 'Ativo' : 'Desativado'}</td>
                                <td className="batch-actions">
                                    <button className="quick-link" onClick={() => setEditing(tier)}>Editar</button>
                                    {tier.status === 'ACTIVE' && !tier.is_default && (
                                        <button className="quick-link" onClick={() => onAction('put', `/admin/settings/tiers/${tier.id}/default`)}>Tornar padrão</button>
                                    )}
                                    {!tier.is_default && (tier.status === 'ACTIVE'
                                        ? <button className="quick-link danger" onClick={() => onAction('put', `/admin/settings/tiers/${tier.id}/status`, { status: 'INACTIVE' })}>Desativar</button>
                                        : <button className="quick-link" onClick={() => onAction('put', `/admin/settings/tiers/${tier.id}/status`, { status: 'ACTIVE' })}>Reativar</button>)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {editing === null
                ? <button className="secondary-button" onClick={() => setEditing('new')}>+ NOVO ESCALÃO</button>
                : <TierForm key={editing === 'new' ? 'new' : editing.id} tier={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
        </>
    );
}

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

// Configuração da instalação: o cliente, os escalões do voucher e os
// logótipos impressos nos cartões.
// O gestor MEAL muda o programa, a frase e os logótipos; o nome da
// organização só o administrador do sistema.
export default function Settings({ client, canEditOrganization, logos, maxLogos, tiers, settlementDeadlineDays }: Props) {
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

    function change(method: 'put' | 'delete', url: string, data: Record<string, string> = {}) {
        setError(null);
        const options = { preserveScroll: true, onError: (errors: Record<string, string>) => setError(firstError(errors)) };
        if (method === 'put') router.put(url, data, options);
        else router.delete(url, options);
    }

    const full = logos.length >= maxLogos;

    return (
        <AdminLayout>
            <Head title="Configuração" />
            <div className="admin-page-heading"><div><div className="eyebrow">ADMINISTRAÇÃO</div><h1>Configuração</h1>
                <p>O cliente desta instalação, os escalões do voucher e os logótipos impressos nos cartões.</p></div></div>
            {flash.success && <div className="success-box">{flash.success}</div>}
            {error && <div className="error-box">{error}</div>}

            <ClientForm client={client} canEditOrganization={canEditOrganization} />

            <Tiers tiers={tiers} onAction={change} />

            <SettlementDeadline days={settlementDeadlineDays} />

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
