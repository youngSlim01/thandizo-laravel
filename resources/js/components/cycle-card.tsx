import { useForm } from '@inertiajs/react';
import { useCallback, useEffect, useState, type ChangeEvent, type FormEvent } from 'react';
import { GeoFilter, geoTitle, useGeography, type GeoValue } from '@/components/geo-filter';
import { useAction } from '@/lib/action';
import { exportCycleReport, period, printCycleReport, type CycleSummary } from '@/lib/cycle-report';
import { formatDateTime, formatMT } from '@/lib/format';
import { errorText, requestJson } from '@/lib/http';

// Cartão de um ciclo do programa, com o relatório (PDF / Excel). Quem gere
// os ciclos (gestor MEAL, administrador) também recarrega e fecha daqui; as
// Finanças só lêem.

export type Cycle = {
    cycle_id: string; code: string; name: string; description: string | null; status: 'OPEN' | 'CLOSED'; starts_on: string | null;
    ends_on: string | null; opened_at: string; closed_at: string | null; households: number; allocated: number;
};
type Preview = {
    to_allocate: number; total_amount: number; left_out: Record<string, number>;
    by_tier: { tier: string; amount: number; households: number; total_amount: number }[];
    left_out_rows: { household_code: string; reason: string }[];
};
type ClosePreview = {
    households_with_balance: number; unused_amount: number;
    households: { household_code: string; representative_name: string; community: string | null; district: string | null; balance: number }[];
};
const REASON: Record<string, string> = {
    UNKNOWN_HOUSEHOLD: 'CODIGO AF que não existe',
    HOUSEHOLD_INACTIVE: 'agregado inativo',
    NO_DELIVERED_CARD: 'ainda sem cartão entregue (recebe na distribuição de cartões)',
    ALREADY_IN_CYCLE: 'já recebeu neste ciclo',
    BALANCE_LEFT: 'ainda tem saldo',
};

// Todos os CODIGO AF que aparecem num texto ou em qualquer célula de um ficheiro.
const CODE = /\b\d{4}AF\d{6}\b/gi;
const codesIn = (text: string) => Array.from(new Set((text.match(CODE) ?? []).map((c) => c.toUpperCase())));

async function codesFromFile(file: File): Promise<string[]> {
    if (!file.name.toLowerCase().endsWith('.xlsx')) return codesIn(await file.text());
    const { readSheet } = await import('read-excel-file/browser');
    const rows = (await readSheet(file)) as unknown as unknown[][];
    return codesIn(rows.map((r) => r.map((c) => String(c ?? '')).join(' ')).join('\n'));
}

function Recharge({ cycle, onClose }: { cycle: Cycle; onClose: () => void }) {
    const geo = useGeography();
    const [mode, setMode] = useState<'zone' | 'list'>('zone');
    const [place, setPlace] = useState<GeoValue>({});
    const [codesText, setCodesText] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [selection, setSelection] = useState<{ household_ids: string[] | null; household_codes: string[] | null }>({ household_ids: null, household_codes: null });
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const action = useAction();

    async function check() {
        setChecking(true); setError(null); setPreview(null);
        try {
            let ids: string[] | null = null;
            let codes: string[] | null = null;
            if (mode === 'zone') {
                const r = await requestJson<{ rows: { id: string }[] }>('POST', '/admin/households/search', { filters: { ...place, status: 'ACTIVE' }, limit: 20000, offset: 0 });
                ids = r.rows.map((x) => x.id);
            } else {
                codes = codesIn(codesText);
            }
            if ((ids?.length ?? 0) + (codes?.length ?? 0) === 0) { setError('Nenhum agregado escolhido.'); return; }
            const chosen = { household_ids: ids, household_codes: codes };
            setSelection(chosen);
            setPreview(await requestJson<Preview>('POST', `/admin/cycles/${cycle.cycle_id}/allocation/preview`, chosen));
        } catch (err) { setError(errorText(err)); } finally { setChecking(false); }
    }

    async function file(event: ChangeEvent<HTMLInputElement>) {
        const f = event.target.files?.[0];
        event.target.value = '';
        if (!f) return;
        try { setCodesText((await codesFromFile(f)).join('\n')); setPreview(null); } catch (err) { setError(errorText(err)); }
    }

    const busy = checking || action.busy;
    const shownError = error ?? action.error;

    return (
        <div className="admin-form cycle-recharge">
            <div className="row-between"><strong>Recarregar agregados — {cycle.name}</strong><button className="quick-link" onClick={onClose}>Fechar</button></div>
            <p className="muted-text">Cada agregado escolhido recebe no cartão que já tem o valor do seu escalão. Quem ainda não tem cartão entregue recebe-o na distribuição de cartões (e entra no ciclo nesse momento).</p>
            <div className="tab-row" role="tablist">
                <button role="tab" aria-selected={mode === 'zone'} className={mode === 'zone' ? 'tab active' : 'tab'} onClick={() => { setMode('zone'); setPreview(null); }}>Por zona</button>
                <button role="tab" aria-selected={mode === 'list'} className={mode === 'list' ? 'tab active' : 'tab'} onClick={() => { setMode('list'); setPreview(null); }}>Lista de CODIGO AF</button>
            </div>
            {mode === 'zone' ? (
                <>
                    <GeoFilter geo={geo} value={place} onChange={(v) => { setPlace(v); setPreview(null); }} idPrefix="cycle-geo" />
                    <div className="muted-text small-note">Zona: {geoTitle(geo, place) || 'todo o programa'} · só agregados ativos.</div>
                </>
            ) : (
                <>
                    <textarea className="input cycle-codes" rows={6} value={codesText} onChange={(e) => { setCodesText(e.target.value); setPreview(null); }}
                        placeholder="Cole os CODIGO AF (um por linha, ou separados por espaço) ou leia um ficheiro" aria-label="Lista de CODIGO AF" />
                    <div className="row-between">
                        <span className="muted-text small-note">{codesIn(codesText).length} código(s) reconhecido(s)</span>
                        <label className="secondary-button file-button">LER FICHEIRO (.xlsx / .csv)<input type="file" accept=".xlsx,.csv,.txt" hidden onChange={file} aria-label="Ficheiro com CODIGO AF" /></label>
                    </div>
                </>
            )}
            {shownError && <div className="error-box">{shownError}</div>}
            <button className="secondary-button" onClick={check} disabled={busy}>{checking ? 'A VERIFICAR…' : 'VER QUEM RECEBE'}</button>
            {preview && (
                <>
                    <div className="info-box">
                        <strong>{preview.to_allocate} agregado(s)</strong> vão receber, no total, {formatMT(preview.total_amount)}.
                        {preview.by_tier.length > 0 && (
                            <ul>{preview.by_tier.map((t) => <li key={t.tier}>{t.tier}: {t.households} × {formatMT(t.amount)} = {formatMT(t.total_amount)}</li>)}</ul>
                        )}
                        {Object.keys(preview.left_out).length > 0 && (
                            <ul>{Object.entries(preview.left_out).map(([k, v]) => <li key={k}>{v} ficam de fora: {REASON[k] ?? k}</li>)}</ul>
                        )}
                    </div>
                    {preview.left_out_rows.length > 0 && (
                        <details><summary>Ver quem fica de fora</summary>
                            <ul className="cycle-left-out">{preview.left_out_rows.map((r) => <li key={r.household_code + r.reason}>{r.household_code} — {REASON[r.reason] ?? r.reason}</li>)}</ul>
                        </details>
                    )}
                    <button className="primary-button" onClick={() => action.run('post', `/admin/cycles/${cycle.cycle_id}/allocation`, selection, onClose)}
                        disabled={busy || preview.to_allocate === 0}>
                        {action.busy ? 'A RECARREGAR…' : `RECARREGAR ${preview.to_allocate} AGREGADO(S)`}
                    </button>
                </>
            )}
        </div>
    );
}

function CloseCycle({ cycle, onClose }: { cycle: Cycle; onClose: () => void }) {
    const [preview, setPreview] = useState<ClosePreview | null>(null);
    const [confirmText, setConfirmText] = useState('');
    const [loadError, setLoadError] = useState<string | null>(null);
    const action = useAction();

    useEffect(() => {
        requestJson<ClosePreview>('GET', `/admin/cycles/${cycle.cycle_id}/closing`).then(setPreview).catch((err) => setLoadError(errorText(err)));
    }, [cycle.cycle_id]);

    const error = loadError ?? action.error;

    return (
        <div className="admin-form">
            <div className="row-between"><strong>Fechar {cycle.name}</strong><button className="quick-link" onClick={onClose}>Cancelar</button></div>
            {!preview && !error && <p>A verificar saldos…</p>}
            {preview && (
                <div className="warning-box">
                    {preview.households_with_balance === 0
                        ? 'Todos os agregados usaram o saldo. Pode fechar.'
                        : <>Ainda há <strong>{preview.households_with_balance} agregado(s)</strong> com saldo, no total de <strong>{formatMT(preview.unused_amount)}</strong>.
                            Ao fechar, esse saldo <strong>expira</strong>: deixa de poder ser usado e fica no relatório como não utilizado. Não pode ser desfeito.</>}
                </div>
            )}
            {preview && preview.households.length > 0 && (
                <details><summary>Ver os agregados com saldo</summary>
                    <ul className="cycle-left-out">{preview.households.slice(0, 300).map((h) => <li key={h.household_code}>{h.household_code} · {h.representative_name} · {h.community ?? '—'}, {h.district ?? '—'} · {formatMT(h.balance)}</li>)}</ul>
                </details>
            )}
            <label className="form-label" htmlFor="close-confirm">Escreva {cycle.code} para confirmar</label>
            <input id="close-confirm" className="input" value={confirmText} onChange={(e) => setConfirmText(e.target.value)} />
            {error && <div className="error-box">{error}</div>}
            <button className="danger-button" onClick={() => action.run('post', `/admin/cycles/${cycle.cycle_id}/closing`, {}, onClose)}
                disabled={action.busy || !preview || confirmText.trim().toUpperCase() !== cycle.code}>
                {action.busy ? 'A FECHAR…' : 'FECHAR CICLO'}
            </button>
        </div>
    );
}

// Nome (a identificação, ex.: "AA - Janela 1"), descrição e datas. Num ciclo
// fechado as datas já não mudam.
function EditCycle({ cycle, onClose }: { cycle: Cycle; onClose: () => void }) {
    const form = useForm({ name: cycle.name, description: cycle.description ?? '', starts_on: cycle.starts_on ?? '', ends_on: cycle.ends_on ?? '' });
    const open = cycle.status === 'OPEN';

    function save(event: FormEvent) {
        event.preventDefault();
        form.put(`/admin/cycles/${cycle.cycle_id}`, { preserveScroll: true, onSuccess: onClose });
    }

    const error = form.errors.name ?? form.errors.description ?? form.errors.starts_on ?? form.errors.ends_on ?? (form.errors as Record<string, string>).rule;

    return (
        <form className="admin-form" onSubmit={save}>
            <div className="row-between"><strong>Editar {cycle.code}</strong><button type="button" className="quick-link" onClick={onClose}>Cancelar</button></div>
            <CycleFields form={form} datesDisabled={!open} idPrefix={`edit-${cycle.cycle_id}`} />
            {!open && <div className="muted-text small-note">Ciclo fechado: as datas ficam como foram.</div>}
            {error && <div className="error-box">{error}</div>}
            <button className="primary-button" disabled={form.processing || !form.isDirty}>{form.processing ? 'A GUARDAR…' : 'GUARDAR CICLO'}</button>
        </form>
    );
}

type CycleForm = { data: { name: string; description: string; starts_on: string; ends_on: string }; setData: (key: 'name' | 'description' | 'starts_on' | 'ends_on', value: string) => void };

// Campos de um ciclo, iguais ao abrir e ao editar.
export function CycleFields({ form, datesDisabled = false, idPrefix }: { form: CycleForm; datesDisabled?: boolean; idPrefix: string }) {
    return (
        <div className="form-grid-2">
            <div><label className="form-label" htmlFor={`${idPrefix}-name`}>Nome do ciclo</label>
                <input id={`${idPrefix}-name`} className="input" maxLength={100} placeholder="Ex.: AA - Janela 1" value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)} /></div>
            <div><label className="form-label" htmlFor={`${idPrefix}-description`}>Âmbito (opcional)</label>
                <input id={`${idPrefix}-description`} className="input" maxLength={255} placeholder="Ex.: Acções antecipadas à seca, Out–Dez" value={form.data.description}
                    onChange={(e) => form.setData('description', e.target.value)} /></div>
            <div><label className="form-label" htmlFor={`${idPrefix}-start`}>Início</label>
                <input id={`${idPrefix}-start`} type="date" className="input" disabled={datesDisabled} value={form.data.starts_on}
                    onChange={(e) => form.setData('starts_on', e.target.value)} /></div>
            <div><label className="form-label" htmlFor={`${idPrefix}-end`}>Fim previsto</label>
                <input id={`${idPrefix}-end`} type="date" className="input" disabled={datesDisabled} value={form.data.ends_on}
                    onChange={(e) => form.setData('ends_on', e.target.value)} /></div>
        </div>
    );
}

export function CycleCard({ cycle, canManage }: { cycle: Cycle; canManage: boolean }) {
    const [summary, setSummary] = useState<CycleSummary | null>(null);
    const [panel, setPanel] = useState<'none' | 'recharge' | 'close' | 'edit'>('none');
    const [busy, setBusy] = useState<'pdf' | 'xlsx' | null>(null);
    const [error, setError] = useState<string | null>(null);

    // Volta a ler o relatório quando o ciclo muda (recarga ou fecho).
    const load = useCallback(() => {
        requestJson<CycleSummary>('GET', `/cycles/${cycle.cycle_id}/summary`).then(setSummary).catch((err) => setError(errorText(err)));
    }, [cycle.cycle_id]);
    useEffect(() => { load(); }, [load, cycle.status, cycle.households, cycle.allocated]);

    async function report(kind: 'pdf' | 'xlsx') {
        if (!summary) return;
        setBusy(kind); setError(null);
        try { if (kind === 'pdf') await printCycleReport(summary); else await exportCycleReport(summary); }
        catch (err) { setError(errorText(err)); } finally { setBusy(null); }
    }

    const t = summary?.totals;
    const s = summary?.sales;
    const open = cycle.status === 'OPEN';

    return (
        <section className={`admin-card cycle-card${open ? ' open' : ''}`}>
            <div className="row-between">
                <div>
                    <strong>{cycle.name}</strong> <span className="muted-text">{cycle.code}</span>
                    {cycle.description && <div className="small-note">{cycle.description}</div>}
                    <div className="muted-text small-note">{summary ? period(summary) : ''} · aberto {formatDateTime(cycle.opened_at)}{cycle.closed_at ? ` · fechado ${formatDateTime(cycle.closed_at)}` : ''}</div>
                </div>
                <span className={`badge ${open ? 'badge-active' : ''}`}>{open ? 'Aberto' : 'Fechado'}</span>
            </div>
            {error && <div className="error-box">{error}</div>}
            {t && s && (
                <div className="cycle-figures">
                    <div><span>Agregados</span><strong>{t.households.toLocaleString('pt-PT')}</strong><small>{t.delivered} cartão entregue · {t.recharged} recarregados</small></div>
                    <div><span>Atribuído</span><strong>{formatMT(t.allocated)}</strong></div>
                    <div><span>Compraram</span><strong>{t.households_bought.toLocaleString('pt-PT')}</strong><small>{t.households ? Math.round((t.households_bought / t.households) * 100) : 0}% dos agregados</small></div>
                    <div><span>Gasto pelo voucher</span><strong>{formatMT(s.voucher_amount)}</strong><small>+ {formatMT(s.copayment_amount)} copagamento</small></div>
                    <div className={t.unused > 0 ? 'cycle-unused' : ''}><span>{open ? 'Saldo por usar' : 'Não utilizado'}</span><strong>{formatMT(t.unused)}</strong><small>{t.households_with_unused} agregado(s)</small></div>
                </div>
            )}
            <div className="admin-card-actions">
                <button className="quick-link" onClick={() => report('pdf')} disabled={!summary || busy !== null}>{busy === 'pdf' ? 'A gerar…' : open ? 'Relatório provisório (PDF)' : 'Relatório do ciclo (PDF)'}</button>
                <button className="quick-link" onClick={() => report('xlsx')} disabled={!summary || busy !== null}>{busy === 'xlsx' ? 'A gerar…' : 'Excel'}</button>
                {canManage && <button className="quick-link" onClick={() => setPanel(panel === 'edit' ? 'none' : 'edit')}>Editar ciclo</button>}
                {open && canManage && <button className="quick-link" onClick={() => setPanel(panel === 'recharge' ? 'none' : 'recharge')}>Recarregar agregados</button>}
                {open && canManage && <button className="quick-link danger" onClick={() => setPanel(panel === 'close' ? 'none' : 'close')}>Fechar ciclo</button>}
            </div>
            {open && summary && summary.with_unused.length > 0 && (
                <details className="cycle-follow-up"><summary>Seguimento: {summary.with_unused.length} agregado(s) ainda com saldo</summary>
                    <ul className="cycle-left-out">{summary.with_unused.slice(0, 300).map((h) => <li key={h.household_code}>{h.household_code} · {h.representative_name} · {h.community ?? '—'}, {h.district ?? '—'} · {formatMT(h.unused)}</li>)}</ul>
                    {summary.with_unused.length > 300 && <div className="muted-text small-note">… lista completa no Excel.</div>}
                </details>
            )}
            {panel === 'recharge' && <Recharge cycle={cycle} onClose={() => setPanel('none')} />}
            {panel === 'close' && <CloseCycle cycle={cycle} onClose={() => setPanel('none')} />}
            {panel === 'edit' && <EditCycle key={`${cycle.name}|${cycle.description}|${cycle.starts_on}|${cycle.ends_on}`} cycle={cycle} onClose={() => setPanel('none')} />}
        </section>
    );
}

