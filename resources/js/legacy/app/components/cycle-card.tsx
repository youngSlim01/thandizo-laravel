
import { FormEvent, useCallback, useEffect, useState } from 'react';
import { GeoFilter, type GeoValue, geoTitle, useGeography } from './geo-filter';
import { callRpc } from '../../lib/supabase';
import { errorMessage } from '../../lib/errors';
import { formatDateTime, formatMT } from '../../lib/format';
import { type CycleSummary, exportCycleReport, period, printCycleReport } from '../../lib/cycle-report';

// Programme cycles: one open at a time. MEAL recharges the chosen households
// (same card, 6,000 MT), follows who still has money, and closes the cycle:
// what is left expires and is reported as unused.

export type Cycle = { cycle_id: string; code: string; name: string; amount: number; status: 'OPEN' | 'CLOSED'; starts_on: string | null;
  ends_on: string | null; opened_at: string; closed_at: string | null; households: number; allocated: number };
type Preview = { to_allocate: number; total_amount: number; amount_each: number; left_out: Record<string, number>;
  left_out_rows: { household_code: string; reason: string }[]; committed: boolean; allocated?: number };
type ClosePreview = { households_with_balance: number; unused_amount: number; households: { household_code: string; representative_name: string; community: string | null; district: string | null; balance: number }[] };

const REASON: Record<string, string> = {
  UNKNOWN_HOUSEHOLD: 'CODIGO AF que não existe',
  HOUSEHOLD_INACTIVE: 'agregado inativo',
  NO_DELIVERED_CARD: 'ainda sem cartão entregue (recebe na distribuição de cartões)',
  ALREADY_IN_CYCLE: 'já recebeu neste ciclo',
  BALANCE_LEFT: 'ainda tem saldo',
};

// Every CODIGO AF found in a text or in any cell of a file.
const CODE = /\b\d{4}AF\d{6}\b/gi;
async function codesFromFile(file: File): Promise<string[]> {
  let text: string;
  if (file.name.toLowerCase().endsWith('.xlsx')) {
    const { readSheet } = await import('read-excel-file/browser');
    const rows = (await readSheet(file)) as unknown as unknown[][];
    text = rows.map((r) => r.map((c) => String(c ?? '')).join(' ')).join('\n');
  } else {
    text = await file.text();
  }
  return Array.from(new Set((text.match(CODE) ?? []).map((c) => c.toUpperCase())));
}

export function OpenCycle({ next, onDone }: { next: number; onDone: (m: string) => void }) {
  const [name, setName] = useState(`Ciclo ${next}`);
  const [startsOn, setStartsOn] = useState('');
  const [endsOn, setEndsOn] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function save(e: FormEvent) {
    e.preventDefault(); setBusy(true); setError(null);
    try {
      const r = await callRpc<{ code: string }>('open_programme_cycle', { p_name: name, p_starts_on: startsOn || null, p_ends_on: endsOn || null });
      onDone(`${name} (${r.code}) aberto. Escolha agora os agregados a recarregar.`);
    } catch (err) { setError(errorMessage(err)); } finally { setBusy(false); }
  }
  return (
    <form className="admin-form" onSubmit={save}>
      <strong>Abrir novo ciclo</strong>
      <div className="form-grid-2">
        <div><label className="form-label" htmlFor="cycle-name">Nome</label><input id="cycle-name" className="input" value={name} onChange={(e) => setName(e.target.value)} /></div>
        <div />
        <div><label className="form-label" htmlFor="cycle-start">Início</label><input id="cycle-start" type="date" className="input" value={startsOn} onChange={(e) => setStartsOn(e.target.value)} /></div>
        <div><label className="form-label" htmlFor="cycle-end">Fim previsto</label><input id="cycle-end" type="date" className="input" value={endsOn} onChange={(e) => setEndsOn(e.target.value)} /></div>
      </div>
      {error && <div className="error-box">{error}</div>}
      <button className="primary-button" disabled={busy}>{busy ? 'A ABRIR…' : 'ABRIR CICLO'}</button>
    </form>
  );
}

function Recharge({ cycle, onDone, onCancel }: { cycle: Cycle; onDone: (m: string) => void; onCancel: () => void }) {
  const geo = useGeography();
  const [mode, setMode] = useState<'zone' | 'list'>('zone');
  const [place, setPlace] = useState<GeoValue>({});
  const [codesText, setCodesText] = useState('');
  const [preview, setPreview] = useState<Preview | null>(null);
  const [selection, setSelection] = useState<{ ids: string[] | null; codes: string[] | null }>({ ids: null, codes: null });
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function check() {
    setBusy('check'); setError(null); setPreview(null);
    try {
      let ids: string[] | null = null; let codes: string[] | null = null;
      if (mode === 'zone') {
        const r = await callRpc<{ rows: { id: string }[] }>('search_households', { p_filters: { ...place, status: 'ACTIVE' }, p_limit: 20000, p_offset: 0 });
        ids = r.rows.map((x) => x.id);
      } else {
        codes = Array.from(new Set((codesText.match(CODE) ?? []).map((c) => c.toUpperCase())));
      }
      if ((ids?.length ?? 0) + (codes?.length ?? 0) === 0) { setError('Nenhum agregado escolhido.'); return; }
      setSelection({ ids, codes });
      setPreview(await callRpc<Preview>('allocate_cycle', { p_cycle_id: cycle.cycle_id, p_household_ids: ids, p_household_codes: codes, p_commit: false }));
    } catch (err) { setError(errorMessage(err)); } finally { setBusy(null); }
  }

  async function confirm() {
    setBusy('commit'); setError(null);
    try {
      const r = await callRpc<Preview>('allocate_cycle', { p_cycle_id: cycle.cycle_id, p_household_ids: selection.ids, p_household_codes: selection.codes, p_commit: true });
      onDone(`${r.allocated} agregado(s) recarregado(s) com ${formatMT(r.amount_each)} cada, no mesmo cartão.`);
    } catch (err) { setError(errorMessage(err)); } finally { setBusy(null); }
  }

  async function file(e: React.ChangeEvent<HTMLInputElement>) {
    const f = e.target.files?.[0]; e.target.value = '';
    if (!f) return;
    try { const codes = await codesFromFile(f); setCodesText(codes.join('\n')); setPreview(null); }
    catch (err) { setError(errorMessage(err)); }
  }

  const listCount = Array.from(new Set((codesText.match(CODE) ?? []).map((c) => c.toUpperCase()))).length;
  return (
    <div className="admin-form cycle-recharge">
      <div className="row-between"><strong>Recarregar agregados — {cycle.name}</strong><button className="quick-link" onClick={onCancel}>Fechar</button></div>
      <p className="muted-text">Cada agregado escolhido recebe {formatMT(cycle.amount)} no cartão que já tem. Quem ainda não tem cartão entregue recebe-o na distribuição de cartões (e entra no ciclo nesse momento).</p>
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
            <span className="muted-text small-note">{listCount} código(s) reconhecido(s)</span>
            <label className="secondary-button file-button">LER FICHEIRO (.xlsx / .csv)<input type="file" accept=".xlsx,.csv,.txt" hidden onChange={file} aria-label="Ficheiro com CODIGO AF" /></label>
          </div>
        </>
      )}
      {error && <div className="error-box">{error}</div>}
      <button className="secondary-button" onClick={check} disabled={busy !== null}>{busy === 'check' ? 'A VERIFICAR…' : 'VER QUEM RECEBE'}</button>
      {preview && (
        <>
          <div className="info-box">
            <strong>{preview.to_allocate} agregado(s)</strong> vão receber {formatMT(preview.amount_each)} — total {formatMT(preview.total_amount)}.
            {Object.keys(preview.left_out).length > 0 && (
              <ul>{Object.entries(preview.left_out).map(([k, v]) => <li key={k}>{v} ficam de fora: {REASON[k] ?? k}</li>)}</ul>
            )}
          </div>
          {preview.left_out_rows.length > 0 && (
            <details><summary>Ver quem fica de fora</summary>
              <ul className="cycle-left-out">{preview.left_out_rows.map((r) => <li key={r.household_code + r.reason}>{r.household_code} — {REASON[r.reason] ?? r.reason}</li>)}</ul>
            </details>
          )}
          <button className="primary-button" onClick={confirm} disabled={busy !== null || preview.to_allocate === 0}>
            {busy === 'commit' ? 'A RECARREGAR…' : `RECARREGAR ${preview.to_allocate} AGREGADO(S)`}
          </button>
        </>
      )}
    </div>
  );
}

function CloseCycle({ cycle, onDone, onCancel }: { cycle: Cycle; onDone: (m: string) => void; onCancel: () => void }) {
  const [preview, setPreview] = useState<ClosePreview | null>(null);
  const [confirmText, setConfirmText] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    callRpc<ClosePreview>('close_programme_cycle', { p_cycle_id: cycle.cycle_id, p_commit: false }).then(setPreview).catch((err) => setError(errorMessage(err)));
  }, [cycle.cycle_id]);
  async function close() {
    setBusy(true); setError(null);
    try {
      const r = await callRpc<ClosePreview>('close_programme_cycle', { p_cycle_id: cycle.cycle_id, p_commit: true });
      onDone(`${cycle.name} fechado. ${r.households_with_balance} agregado(s) não usaram tudo: ${formatMT(r.unused_amount)} expirou e fica no relatório como não utilizado.`);
    } catch (err) { setError(errorMessage(err)); } finally { setBusy(false); }
  }
  return (
    <div className="admin-form">
      <div className="row-between"><strong>Fechar {cycle.name}</strong><button className="quick-link" onClick={onCancel}>Cancelar</button></div>
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
      <button className="danger-button" onClick={close} disabled={busy || !preview || confirmText.trim().toUpperCase() !== cycle.code}>{busy ? 'A FECHAR…' : 'FECHAR CICLO'}</button>
    </div>
  );
}

export function CycleCard({ cycle, canManage, onChanged }: { cycle: Cycle; canManage: boolean; onChanged: (m: string) => void }) {
  const [summary, setSummary] = useState<CycleSummary | null>(null);
  const [panel, setPanel] = useState<'none' | 'recharge' | 'close'>('none');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    callRpc<CycleSummary>('cycle_summary', { p_cycle_id: cycle.cycle_id }).then(setSummary).catch((err) => setError(errorMessage(err)));
  }, [cycle.cycle_id]);
  useEffect(() => { load(); }, [load]);
  const done = (m: string) => { setPanel('none'); load(); onChanged(m); };
  async function report(kind: 'pdf' | 'xlsx') {
    if (!summary) return;
    setBusy(kind); setError(null);
    try { if (kind === 'pdf') await printCycleReport(summary); else await exportCycleReport(summary); }
    catch (err) { setError(errorMessage(err)); } finally { setBusy(null); }
  }
  const t = summary?.totals; const s = summary?.sales;
  const open = cycle.status === 'OPEN';
  return (
    <section className={`admin-card cycle-card${open ? ' open' : ''}`}>
      <div className="row-between">
        <div>
          <strong>{cycle.name}</strong> <span className="muted-text">{cycle.code}</span>
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
        {open && canManage && <button className="quick-link" onClick={() => setPanel(panel === 'recharge' ? 'none' : 'recharge')}>Recarregar agregados</button>}
        {open && canManage && <button className="quick-link danger" onClick={() => setPanel(panel === 'close' ? 'none' : 'close')}>Fechar ciclo</button>}
      </div>
      {open && summary && summary.with_unused.length > 0 && (
        <details className="cycle-follow-up"><summary>Seguimento: {summary.with_unused.length} agregado(s) ainda com saldo</summary>
          <ul className="cycle-left-out">{summary.with_unused.slice(0, 300).map((h) => <li key={h.household_code}>{h.household_code} · {h.representative_name} · {h.community ?? '—'}, {h.district ?? '—'} · {formatMT(h.unused)}</li>)}</ul>
          {summary.with_unused.length > 300 && <div className="muted-text small-note">… lista completa no Excel.</div>}
        </details>
      )}
      {panel === 'recharge' && <Recharge cycle={cycle} onDone={done} onCancel={() => setPanel('none')} />}
      {panel === 'close' && <CloseCycle cycle={cycle} onDone={done} onCancel={() => setPanel('none')} />}
    </section>
  );
}
