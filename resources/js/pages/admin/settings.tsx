import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, type ChangeEvent, type FormEvent, type ReactNode } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { firstError } from '@/lib/action';
import { formatMT } from '@/lib/format';
import type { SharedProps } from '@/types';

type Logo = { id: string; position: number; name: string; url: string };
type Client = { client: string | null; programme: string | null; tagline: string | null };
type Tier = { id: string; name: string; amount: number; description: string | null; is_default: boolean; requires_household_code: boolean; status: 'ACTIVE' | 'INACTIVE'; households: number };
type Props = { client: Client; canEditOrganization: boolean; logos: Logo[]; maxLogos: number; tiers: Tier[]; settlementDeadlineDays: number };

// Cartão de uma secção: título, frase, conteúdo e, quando há, os botões no
// fundo, à direita.
function Section({ title, text, action, footer, children }: { title: string; text: ReactNode; action?: ReactNode; footer?: ReactNode; children: ReactNode }) {
    return (
        <section className="sc-card">
            <div className="sc-head">
                <div><h2>{title}</h2><p>{text}</p></div>
                {action}
            </div>
            <div className="sc-body">{children}</div>
            {footer && <div className="sc-foot">{footer}</div>}
        </section>
    );
}

function SaveButton({ busy, dirty }: { busy: boolean; dirty: boolean }) {
    return <button type="submit" className="sc-save" disabled={busy || !dirty}>{busy ? 'A guardar…' : 'Guardar'}</button>;
}

function ClientForm({ client, canEditOrganization }: { client: Client; canEditOrganization: boolean }) {
    const form = useForm({ name: client.client ?? '', programme: client.programme ?? '', tagline: client.tagline ?? '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put('/admin/settings/client', { preserveScroll: true });
    }

    const error = form.errors.name ?? form.errors.programme ?? form.errors.tagline ?? (form.errors as Record<string, string>).rule;

    return (
        <form onSubmit={save}>
            <Section title="Cliente" text="Aparece junto do nome Thandizo nos ecrãs, no recibo, no cartão e nos PDFs."
                footer={<SaveButton busy={form.processing} dirty={form.isDirty} />}>
                <div className="form-grid-2">
                    <div><label className="form-label" htmlFor="client-name">Organização</label>
                        {canEditOrganization
                            ? <input id="client-name" className="input" maxLength={120} placeholder="Ex.: Save the Children"
                                value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            : <div id="client-name" className="sc-locked">{client.client ?? <em>A definir pelo administrador do sistema</em>}</div>}</div>
                    <div><label className="form-label" htmlFor="client-programme">Programa</label>
                        <input id="client-programme" className="input" maxLength={120} placeholder="Ex.: Projecto LINK GCF"
                            value={form.data.programme} onChange={(e) => form.setData('programme', e.target.value)} /></div>
                </div>
                <label className="form-label" htmlFor="client-tagline">Frase do cliente (opcional)</label>
                <input id="client-tagline" className="input" maxLength={160} placeholder="Mostrada na página de entrada"
                    value={form.data.tagline} onChange={(e) => form.setData('tagline', e.target.value)} />
                {error && <div className="error-box">{error}</div>}
            </Section>
        </form>
    );
}

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
        <form className="sc-subform" onSubmit={save}>
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
                <div className="muted-text small-note">Os agregados deste escalão podem não ter CODIGO AF: o sistema gera um (ex.: TZ-MUT-000001), que é definitivo.</div>
            )}
            {changingAmount && tier.households > 0 && (
                <div className="warning-box">O novo valor vale para as próximas ativações e recargas dos {tier.households} agregados deste escalão. O que já está nos cartões não muda.</div>
            )}
            {error && <div className="error-box">{error}</div>}
            <div className="sc-actions">
                <button type="button" className="sc-cancel" onClick={onDone} disabled={form.processing}>Cancelar</button>
                <SaveButton busy={form.processing} dirty={form.isDirty} />
            </div>
        </form>
    );
}

// Escalões do voucher: cada agregado recebe o valor do seu escalão.
function Tiers({ tiers, onAction }: { tiers: Tier[]; onAction: (method: 'put', url: string, data?: Record<string, string>) => void }) {
    const [editing, setEditing] = useState<Tier | 'new' | null>(null);

    return (
        <Section title="Escalões do voucher"
            text={<>Cada agregado recebe o valor do seu escalão em cada activação e recarga.{' '}
                <details className="sc-more"><summary>Saber mais</summary>
                    Na importação, a coluna VALOR escolhe o escalão com esse valor; sem ela, o agregado fica no escalão padrão.
                    Dois escalões activos não podem ter o mesmo valor. Num escalão que não exige CODIGO AF, o agregado sem código
                    recebe um gerado pelo sistema, que é definitivo. Mudar o valor de um escalão só conta para as próximas activações e recargas.
                </details></>}
            action={editing === null && <button type="button" className="sc-add" onClick={() => setEditing('new')}>+ Novo escalão</button>}>
            <div className="sc-table">
                <table>
                    <thead><tr><th>Escalão</th><th>Valor</th><th>CODIGO AF</th><th>Agregados</th><th>Estado</th><th /></tr></thead>
                    <tbody>
                        {tiers.map((tier) => (
                            <tr key={tier.id} className={tier.status === 'ACTIVE' ? undefined : 'muted-row'}>
                                <td><strong>{tier.name}</strong>{tier.is_default && <span className="badge tier-default">padrão</span>}
                                    {tier.description && <small>{tier.description}</small>}</td>
                                <td>{formatMT(tier.amount)}</td>
                                <td>{tier.requires_household_code ? 'Obrigatório' : 'Gerado se faltar'}</td>
                                <td>{tier.households}</td>
                                <td>{tier.status === 'ACTIVE' ? 'Activo' : 'Desactivado'}</td>
                                <td className="sc-row-actions">
                                    <button type="button" className="sc-link" onClick={() => setEditing(tier)}>Editar</button>
                                    {tier.status === 'ACTIVE' && !tier.is_default && (
                                        <button type="button" className="sc-link" onClick={() => onAction('put', `/admin/settings/tiers/${tier.id}/default`)}>Tornar padrão</button>
                                    )}
                                    {!tier.is_default && (tier.status === 'ACTIVE'
                                        ? <button type="button" className="sc-link danger" onClick={() => onAction('put', `/admin/settings/tiers/${tier.id}/status`, { status: 'INACTIVE' })}>Desactivar</button>
                                        : <button type="button" className="sc-link" onClick={() => onAction('put', `/admin/settings/tiers/${tier.id}/status`, { status: 'ACTIVE' })}>Reactivar</button>)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {editing !== null && <TierForm key={editing === 'new' ? 'new' : editing.id} tier={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
        </Section>
    );
}

// Logótipos: um espaço por posição no cartão. Só o primeiro espaço livre
// recebe um logótipo novo (entra sempre à direita dos que já existem).
function Logos({ logos, maxLogos, onAction }: { logos: Logo[]; maxLogos: number; onAction: (method: 'put' | 'delete', url: string) => void }) {
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    function upload(event: ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        setError(null);
        router.post('/admin/settings/logos', { logo: file }, {
            forceFormData: true, preserveScroll: true,
            onStart: () => setBusy(true), onFinish: () => setBusy(false),
            onError: (errors: Record<string, string>) => setError(errors.logo ?? firstError(errors)),
        });
    }

    return (
        <Section title="Logótipos do cartão"
            text="Até 3, da esquerda para a direita na faixa verde do cartão. PNG, JPG ou WebP até 1 MB, de preferência com fundo transparente.">
            <div className="sc-logos">
                {Array.from({ length: maxLogos }, (_, i) => {
                    const logo = logos[i];
                    if (logo) {
                        return (
                            <div key={logo.id} className="sc-slot sc-slot-filled">
                                <img src={logo.url} alt={`Logótipo ${i + 1}`} />
                                <small>{i + 1}. {logo.name}</small>
                                <div className="sc-row-actions">
                                    {i > 0 && <button type="button" className="sc-link" onClick={() => onAction('put', `/admin/settings/logos/${logo.id}/move-left`)}>← Para a esquerda</button>}
                                    <button type="button" className="sc-link danger" onClick={() => onAction('delete', `/admin/settings/logos/${logo.id}`)}>Retirar</button>
                                </div>
                            </div>
                        );
                    }
                    const next = i === logos.length;
                    return next ? (
                        <label key={i} className="sc-slot sc-slot-next">
                            <b>{busy ? 'A carregar…' : '+ Carregar'}</b>Logótipo {i + 1}
                            <input type="file" accept="image/png,image/jpeg,image/webp" hidden onChange={upload} disabled={busy} />
                        </label>
                    ) : (
                        <div key={i} className="sc-slot">Logótipo {i + 1}</div>
                    );
                })}
            </div>
            {error && <div className="error-box">{error}</div>}
            <div className="warning-box">Os cartões já impressos não mudam: só os próximos lotes saem com os logótipos novos.</div>
        </Section>
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
        <form onSubmit={save}>
            <Section title="Prazo das Finanças"
                text="Dias para as Finanças validarem uma reconciliação depois de a receberem do MEAL. Passado o prazo, o painel das Finanças avisa."
                footer={<SaveButton busy={form.processing} dirty={form.isDirty} />}>
                <div className="sc-inline">
                    <input id="settlement-days" className="input" type="number" min={1} max={90} aria-label="Prazo em dias"
                        value={form.data.days} onChange={(e) => form.setData('days', e.target.value)} />
                    <span>dias</span>
                </div>
                {error && <div className="error-box">{error}</div>}
            </Section>
        </form>
    );
}

// Configuração da instalação: cliente, escalões do voucher, logótipos do
// cartão e prazo das Finanças. O gestor MEAL muda tudo excepto o nome da
// organização, que só o administrador do sistema muda.
export default function Settings({ client, canEditOrganization, logos, maxLogos, tiers, settlementDeadlineDays }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [error, setError] = useState<string | null>(null);

    function change(method: 'put' | 'delete', url: string, data: Record<string, string> = {}) {
        setError(null);
        const options = { preserveScroll: true, onError: (errors: Record<string, string>) => setError(firstError(errors)) };
        if (method === 'put') router.put(url, data, options);
        else router.delete(url, options);
    }

    return (
        <AdminLayout>
            <Head title="Configuração" />
            <div className="admin-page-heading"><div><div className="eyebrow">ADMINISTRAÇÃO</div><h1>Configuração</h1>
                <p>Cliente, escalões do voucher, logótipos do cartão e prazo das Finanças.</p></div></div>
            {flash.success && <div className="success-box">{flash.success}</div>}
            {error && <div className="error-box">{error}</div>}

            <ClientForm client={client} canEditOrganization={canEditOrganization} />
            <Tiers tiers={tiers} onAction={change} />
            <Logos logos={logos} maxLogos={maxLogos} onAction={change} />
            <SettlementDeadline days={settlementDeadlineDays} />
        </AdminLayout>
    );
}
