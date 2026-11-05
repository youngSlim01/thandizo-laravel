import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { errorText, requestJson } from '@/lib/http';
import { formatDateTime, formatMT } from '@/lib/format';
import { relationshipLabel } from '@/lib/substitute';
import { GeoFilter, type GeoValue, useGeography } from '@/components/geo-filter';
import { DeleteHousehold, EditHousehold, type EditableHousehold, HouseholdHistory, NewHousehold } from '@/components/households/household-editor';

type CardStatus = 'NONE' | 'PENDING' | 'ACTIVE' | 'EMPTY';

// Uma linha da pesquisa (HouseholdSearchService::search), com os ids de todos os níveis de localização.
type HouseholdRow = {
  id: string;
  household_code: string;
  representative_name: string;
  representative_first_name: string | null;
  representative_last_name: string | null;
  representative_sex: string | null;
  representative_birth_date: string | null;
  representative_relationship: string | null;
  status: string;
  community_id: string | null; community: string | null;
  locality_id: string | null; locality: string | null;
  administrative_post_id: string | null; administrative_post: string | null;
  district_id: string | null; district: string | null;
  province_id: string | null; province: string | null;
  voucher_code: string | null;
  voucher_status: string | null;
  current_balance: number | null;
  card_status: CardStatus;
};

type Summary = { active: number; inactive: number; women: number; men: number; with_card: number; card_pending: number; without_card: number };

type Detail = {
  household_code: string;
  representative_name: string;
  first_name: string | null;
  last_name: string | null;
  gender: string | null;
  birth_date: string | null;
  relationship: string | null;
  status: string;
  status_reason: string | null;
  status_changed_at: string | null;
  created_at: string;
  community: string | null;
  locality: string | null;
  administrative_post: string | null;
  district: string | null;
  province: string | null;
  import: { file_name: string | null; imported_at: string } | null;
  cards: {
    voucher_code: string; status: string; current_balance: number; issued_at: string;
    distributed_at: string | null; batch_code: string | null;
    received_by_name: string | null; received_by_relationship: string | null;
    replaced_voucher_code: string | null; replacement_reason: string | null;
  }[];
  sales: {
    transaction_code: string; confirmed_at: string; agrodealer: string | null; voucher_code: string;
    total_amount: number; voucher_amount: number; copayment_amount: number; status: string;
  }[];
};

const PAGE = 50;

const CARD_LABEL: Record<CardStatus, string> = {
  NONE: 'Sem cartão',
  PENDING: 'Por distribuir',
  ACTIVE: 'Ativo',
  EMPTY: 'Sem saldo',
};

const VOUCHER_LABEL: Record<string, string> = {
  ACTIVE: 'Ativo', PENDING: 'Por distribuir', REPLACED: 'Substituído', CANCELLED: 'Cancelado', INVALIDATED: 'Invalidado',
};

const GENDER: Record<string, string> = { F: 'Feminino', M: 'Masculino' };

const formatDate = (value: string | null) =>
  value ? new Date(value + (value.length === 10 ? 'T12:00:00' : '')).toLocaleDateString('pt-PT') : '—';

// CSV for Excel in Portuguese: ";" separator and a BOM so accents open correctly.
function toCsv(rows: HouseholdRow[]): string {
  const header = ['CODIGO AF', 'REPRESENTANTE', 'GENERO', 'DATA NASC', 'GRAU PARENTESCO', 'PROVINCIA', 'DISTRITO',
    'LOCALIDADE', 'BAIRRO/POVOACAO', 'CARTAO', 'CODIGO VOUCHER', 'SALDO'];
  const cell = (v: unknown) => {
    const text = v === null || v === undefined ? '' : String(v);
    return /[";\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  };
  const lines = rows.map((r) => [
    r.household_code, r.representative_name, GENDER[r.representative_sex ?? ''] ?? r.representative_sex,
    r.representative_birth_date ? formatDate(r.representative_birth_date) : '',
    r.representative_relationship, r.province, r.district, r.locality, r.community, CARD_LABEL[r.card_status], r.voucher_code,
    r.current_balance ?? '',
  ].map(cell).join(';'));
  return '﻿' + [header.join(';'), ...lines].join('\r\n');
}

function StatusChange({ id, active, onChanged }: { id: string; active: boolean; onChanged: () => void }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save(event: FormEvent) {
    event.preventDefault();
    setBusy(true); setError(null);
    try {
      await requestJson('PUT', `/admin/households/${id}/status`, { status: active ? 'INACTIVE' : 'ACTIVE', reason });
      setOpen(false); setReason('');
      onChanged();
    } catch (err) { setError(errorText(err)); }
    finally { setBusy(false); }
  }

  if (!open) {
    return (
      <button className={active ? 'quick-link danger household-status-button' : 'quick-link household-status-button'} onClick={() => setOpen(true)}>
        {active ? 'Desativar agregado' : 'Reativar agregado'}
      </button>
    );
  }
  return (
    <form className="admin-form" onSubmit={save}>
      <div className={active ? 'warning-box' : 'info-box'}>
        {active
          ? <>
              O agregado deixa de poder comprar no POS. O saldo do voucher fica guardado e volta a poder ser usado quando o agregado for reativado.
              <br /><br />
              <strong>Isto bloqueia o agregado, não o cartão.</strong> Se o cartão foi perdido ou roubado, não desative: use{' '}
              <strong>Cartões → Cartão perdido</strong>, que cancela o cartão e passa o saldo para um cartão novo.
            </>
          : 'O agregado volta a poder comprar com o saldo que tinha.'}
      </div>
      <label className="form-label" htmlFor={`status-reason-${id}`}>Motivo</label>
      <input id={`status-reason-${id}`} className="input" value={reason} onChange={(e) => setReason(e.target.value)}
        placeholder={active ? 'Ex.: mudou-se do bairro' : 'Ex.: voltou ao bairro'} />
      {error && <div className="error-box">{error}</div>}
      <div className="admin-form-actions">
        <button type="button" className="secondary-button" onClick={() => setOpen(false)} disabled={busy}>CANCELAR</button>
        <button type="submit" className={active ? 'danger-button' : 'primary-button'} disabled={busy || !reason.trim()}>
          {active ? 'DESATIVAR' : 'REATIVAR'}
        </button>
      </div>
    </form>
  );
}

function HouseholdDetail({ row, onClose, onChanged, onDeleted }: {
  row: HouseholdRow; onClose: () => void; onChanged: () => void; onDeleted: (m: string) => void;
}) {
  const id = row.id;
  const { canManage } = usePage<{ canManage: boolean }>().props;
  const [detail, setDetail] = useState<Detail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [panel, setPanel] = useState<'none' | 'edit' | 'delete' | 'history'>('none');
  const [notice, setNotice] = useState<string | null>(null);
  const [historyKey, setHistoryKey] = useState(0);

  const load = useCallback(() => {
    setError(null);
    requestJson<Detail>('GET', `/admin/households/${id}`).then(setDetail).catch((err) => setError(errorText(err)));
  }, [id]);

  useEffect(() => { setDetail(null); setPanel('none'); setNotice(null); load(); }, [load]);

  const editable: EditableHousehold | null = detail && {
    household_id: id, household_code: detail.household_code,
    first_name: detail.first_name, last_name: detail.last_name, gender: detail.gender, birth_date: detail.birth_date,
    relationship: detail.relationship, community_id: row.community_id, locality_id: row.locality_id,
    administrative_post_id: row.administrative_post_id, district_id: row.district_id, province_id: row.province_id,
    has_cards: detail.cards.length > 0,
  };

  return (
    <section className="card household-detail">
      <div className="row-between">
        <div>
          <span className="section-label">Agregado familiar</span>
          <h2>{detail?.representative_name ?? '…'}</h2>
          <div className="muted-text">
            {detail?.household_code}
            {detail && detail.status !== 'ACTIVE' && <span className="badge badge-inativo household-inactive">Inativo</span>}
          </div>
        </div>
        <button className="quick-link" onClick={onClose}>Fechar</button>
      </div>
      {error && <div className="error-box">{error}</div>}
      {detail && (
        <>
          <div className="household-facts">
            <div><span>Nome</span><strong>{detail.first_name ?? '—'}</strong></div>
            <div><span>Apelido</span><strong>{detail.last_name ?? '—'}</strong></div>
            <div><span>Género</span><strong>{GENDER[detail.gender ?? ''] ?? '—'}</strong></div>
            <div><span>Data de nascimento</span><strong>{formatDate(detail.birth_date)}</strong></div>
            <div><span>Grau de parentesco</span><strong>{detail.relationship ?? '—'}</strong></div>
            <div><span>Bairro / povoação</span><strong>{detail.community ?? '—'}</strong></div>
            <div><span>Localidade</span><strong>{detail.locality ?? '—'}</strong></div>
            <div><span>Posto administrativo</span><strong>{detail.administrative_post ?? '—'}</strong></div>
            <div><span>Distrito · Província</span><strong>{[detail.district, detail.province].filter(Boolean).join(' · ') || '—'}</strong></div>
          </div>
          {detail.status !== 'ACTIVE' && (
            <div className="warning-box">
              Agregado inativo{detail.status_reason ? `: ${detail.status_reason}` : ''}
              {detail.status_changed_at ? ` (desde ${formatDateTime(detail.status_changed_at)})` : ''}. Não pode comprar no POS; o saldo fica guardado.
            </div>
          )}
          {notice && <div className="success-box">{notice}</div>}
          <div className="household-actions">
            {canManage && <button className="quick-link" onClick={() => setPanel(panel === 'edit' ? 'none' : 'edit')}>Editar dados</button>}
            <button className="quick-link" onClick={() => setPanel(panel === 'history' ? 'none' : 'history')}>Histórico de alterações</button>
            {canManage && detail.cards.length === 0 && <button className="quick-link danger" onClick={() => setPanel(panel === 'delete' ? 'none' : 'delete')}>Eliminar</button>}
          </div>
          {panel === 'edit' && editable && (
            <EditHousehold household={editable} onCancel={() => setPanel('none')}
              onSaved={(m) => { setNotice(m); setPanel('none'); setHistoryKey((k) => k + 1); load(); onChanged(); }} />
          )}
          {panel === 'delete' && editable && <DeleteHousehold household={editable} onCancel={() => setPanel('none')} onDeleted={onDeleted} />}
          {panel === 'history' && <HouseholdHistory householdId={id} refresh={historyKey} />}
          {canManage && <StatusChange id={id} active={detail.status === 'ACTIVE'} onChanged={() => { setHistoryKey((k) => k + 1); load(); onChanged(); }} />}
          <div className="muted-text small-note">
            {detail.import
              ? `Importado de ${detail.import.file_name ?? 'ficheiro sem nome'} em ${formatDateTime(detail.import.imported_at)}.`
              : `Registado em ${formatDateTime(detail.created_at)}.`}
          </div>

          <h3>Cartões</h3>
          {detail.cards.length === 0 && <p className="muted-text">Ainda sem cartão. Gere um lote para o bairro em Cartões.</p>}
          {detail.cards.map((c) => (
            <div className="household-card-row" key={c.voucher_code}>
              <div>
                <strong>{c.voucher_code}</strong>
                <small>
                  {c.batch_code ? `${c.batch_code} · ` : ''}
                  {c.distributed_at ? `distribuído ${formatDateTime(c.distributed_at)}` : `emitido ${formatDateTime(c.issued_at)}`}
                  {c.received_by_name ? ` · recebido por ${c.received_by_name} (${relationshipLabel(c.received_by_relationship)}), substituto do representante` : ''}
                  {c.replaced_voucher_code ? ` · substitui ${c.replaced_voucher_code} (${c.replacement_reason ?? ''})` : ''}
                </small>
              </div>
              <div className="household-card-balance">
                <span className={`badge badge-voucher-${c.status.toLowerCase()}`}>{VOUCHER_LABEL[c.status] ?? c.status}</span>
                <strong>{formatMT(c.current_balance)}</strong>
              </div>
            </div>
          ))}

          <h3>Compras</h3>
          {detail.sales.length === 0 ? <p className="muted-text">Ainda sem compras.</p> : (
            <table className="import-errors">
              <thead><tr><th>Data</th><th>Transação</th><th>Agrodealer</th><th>Total</th><th>Voucher</th><th>Dinheiro</th><th>Estado</th></tr></thead>
              <tbody>
                {detail.sales.map((s) => (
                  <tr key={s.transaction_code}>
                    <td>{formatDateTime(s.confirmed_at)}</td><td>{s.transaction_code}</td><td>{s.agrodealer ?? '—'}</td>
                    <td>{formatMT(s.total_amount)}</td><td>{formatMT(s.voucher_amount)}</td><td>{formatMT(s.copayment_amount)}</td>
                    <td>{s.status === 'REVERSED' ? 'Anulada' : 'Confirmada'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </>
      )}
    </section>
  );
}

export function HouseholdList() {
  const { canManage } = usePage<{ canManage: boolean }>().props;
  const geo = useGeography();
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [place, setPlace] = useState<GeoValue>({});
  const [cardStatus, setCardStatus] = useState<CardStatus | ''>('');
  const [status, setStatus] = useState<'' | 'ACTIVE' | 'INACTIVE'>('');
  const [sex, setSex] = useState<'' | 'F' | 'M'>('');
  const [page, setPage] = useState(0);
  const [rows, setRows] = useState<HouseholdRow[] | null>(null);
  const [total, setTotal] = useState(0);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [selected, setSelected] = useState<HouseholdRow | null>(null);
  const [creating, setCreating] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  const [error, setError] = useState<string | null>(null);

  // Search after the user stops typing.
  useEffect(() => {
    const timer = setTimeout(() => { setQuery(search.trim()); setPage(0); }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  const filters = useMemo(() => ({ search: query, ...place, card_status: cardStatus, status, sex }), [query, place, cardStatus, status, sex]);

  useEffect(() => {
    let cancelled = false;
    setError(null);
    requestJson<{ total: number; summary: Summary; rows: HouseholdRow[] }>('POST', '/admin/households/search', { filters, limit: PAGE, offset: page * PAGE })
      .then((r) => {
        if (cancelled) return;
        setRows(r.rows); setTotal(r.total); setSummary(r.summary);
        // Keep the open detail in step with the list (e.g. after a move).
        setSelected((sel) => (sel ? r.rows.find((x) => x.id === sel.id) ?? sel : sel));
      })
      .catch((err) => { if (!cancelled) setError(errorText(err)); });
    return () => { cancelled = true; };
  }, [filters, page, reloadKey]);

  async function exportCsv() {
    setExporting(true); setError(null);
    try {
      const r = await requestJson<{ total: number; rows: HouseholdRow[] }>('POST', '/admin/households/search', { filters, limit: 20000, offset: 0, sort: 'location' });
      const blob = new Blob([toCsv(r.rows)], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `agregados-${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(errorText(err));
    } finally {
      setExporting(false);
    }
  }

  const from = total === 0 ? 0 : page * PAGE + 1;
  const to = Math.min(total, (page + 1) * PAGE);

  return (
    <>
      {message && <div className="success-box">{message}</div>}
      {canManage && !creating && <button className="primary-button household-new" onClick={() => { setMessage(null); setCreating(true); }}>+ NOVO AGREGADO</button>}
      {creating && <NewHousehold onCancel={() => setCreating(false)} onSaved={(m) => { setMessage(m); setCreating(false); setReloadKey((k) => k + 1); }} />}

      <div className="household-filters">
        <input className="input" placeholder="Procurar nome, CODIGO AF ou código do voucher" value={search}
          onChange={(e) => setSearch(e.target.value)} aria-label="Procurar agregados" />
        <select className="input" value={cardStatus} onChange={(e) => { setCardStatus(e.target.value as CardStatus | ''); setPage(0); }} aria-label="Situação do cartão">
          <option value="">Todos os cartões</option>
          {(Object.keys(CARD_LABEL) as CardStatus[]).map((k) => <option key={k} value={k}>{CARD_LABEL[k]}</option>)}
        </select>
        <select className="input" value={status} onChange={(e) => { setStatus(e.target.value as '' | 'ACTIVE' | 'INACTIVE'); setPage(0); }} aria-label="Situação do agregado">
          <option value="">Ativos e inativos</option><option value="ACTIVE">Só ativos</option><option value="INACTIVE">Só inativos</option>
        </select>
        <select className="input" value={sex} onChange={(e) => { setSex(e.target.value as '' | 'F' | 'M'); setPage(0); }} aria-label="Género">
          <option value="">Mulheres e homens</option><option value="F">Mulheres</option><option value="M">Homens</option>
        </select>
      </div>
      <GeoFilter geo={geo} value={place} onChange={(v) => { setPlace(v); setPage(0); }} idPrefix="list-geo" />

      <div className="row-between household-toolbar">
        <span className="muted-text">
          {rows === null ? 'A carregar…' : `${from}–${to} de ${total} agregados`}
          {summary && total > 0 && ` · ${summary.women} mulheres, ${summary.men} homens · ${summary.with_card} com cartão, ${summary.card_pending} por distribuir, ${summary.without_card} sem cartão`}
        </span>
        <button className="quick-link" onClick={exportCsv} disabled={exporting || total === 0}>{exporting ? 'A exportar…' : 'Exportar CSV'}</button>
      </div>

      {error && <div className="error-box">{error}</div>}

      {selected && <HouseholdDetail row={selected} onClose={() => setSelected(null)} onChanged={() => setReloadKey((k) => k + 1)}
        onDeleted={(m) => { setSelected(null); setMessage(m); setReloadKey((k) => k + 1); }} />}

      {rows && rows.length === 0 && <p className="muted-text">Nenhum agregado encontrado.</p>}
      {rows && rows.length > 0 && (
        <table className="import-errors household-table">
          <thead><tr><th>CODIGO AF</th><th>Representante</th><th>Bairro · Distrito</th><th>Cartão</th><th>Saldo</th></tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className={selected?.id === r.id ? 'selected' : ''}
                onClick={() => { setSelected(r); window.scrollTo({ top: 0, behavior: 'smooth' }); }}>
                <td><strong>{r.household_code}</strong></td>
                <td>{r.representative_name}{r.status !== 'ACTIVE' && <span className="badge badge-inativo household-inactive">Inativo</span>}</td>
                <td>{r.community ?? '—'} · {r.district ?? '—'}</td>
                <td>
                  <span className={`badge badge-card-${r.card_status.toLowerCase()}`}>{CARD_LABEL[r.card_status]}</span>
                  {r.voucher_code && <small className="household-voucher">{r.voucher_code}</small>}
                </td>
                <td>{r.current_balance === null ? '—' : formatMT(r.current_balance)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {total > PAGE && (
        <div className="household-pager">
          <button className="secondary-button" disabled={page === 0} onClick={() => setPage((p) => p - 1)}>ANTERIOR</button>
          <button className="secondary-button" disabled={to >= total} onClick={() => setPage((p) => p + 1)}>SEGUINTE</button>
        </div>
      )}
    </>
  );
}
