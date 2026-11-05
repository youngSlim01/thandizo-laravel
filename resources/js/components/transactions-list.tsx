
import { useEffect, useState } from 'react';
import { formatDateTime, formatMT } from '@/lib/format';
import { errorText, requestJson } from '@/lib/http';
import { STATUS_LABEL, type TxFilters, type TxResult, type TxRow, exportTransactions, periodLabel } from '@/lib/transactions-export';

type HouseholdDetail = {
  household_code?: string; representative_name?: string; province?: string | null;
  district?: string | null; community?: string | null; status?: string;
  cards?: { voucher_code: string; current_balance: number; status: string }[];
};

const PAGE = 50;
const EXPORT_MAX = 20000;
// Dias na hora de Moçambique, seja qual for o relógio do computador.
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Maputo' });
const firstOfMonth = () => today().slice(0, 8) + '01';
const lastMonth = () => {
  const [y, m] = today().split('-').map(Number);
  const py = m === 1 ? y - 1 : y, pm = m === 1 ? 12 : m - 1;
  const last = new Date(Date.UTC(py, pm, 0)).getUTCDate();
  const mm = String(pm).padStart(2, '0');
  return { from: `${py}-${mm}-01`, to: `${py}-${mm}-${String(last).padStart(2, '0')}` };
};

// Vendas de um período, filtradas e somadas pelo servidor
// (ReportService::searchTransactions), em páginas de 50, com o agregado do
// voucher ao clicar e um Excel de tudo o que corresponde.
const search = (filters: TxFilters, limit: number, offset: number, withItems = false) =>
  requestJson<TxResult>('POST', '/transactions/search', { filters, limit, offset, with_items: withItems });
export function TransactionsList({ eyebrow }: { eyebrow: string }) {
  const [filters, setFilters] = useState<TxFilters>({ from: firstOfMonth(), to: today(), status: '', agrodealer_id: '', search: '' });
  const [searchText, setSearchText] = useState('');
  const [result, setResult] = useState<TxResult | null>(null);
  const [rows, setRows] = useState<TxRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [more, setMore] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [household, setHousehold] = useState<HouseholdDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);

  // A pesquisa espera por uma pausa na escrita antes de perguntar ao servidor.
  useEffect(() => {
    const t = setTimeout(() => setFilters((f) => (f.search === searchText.trim() ? f : { ...f, search: searchText.trim() })), 400);
    return () => clearTimeout(t);
  }, [searchText]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true); setError(null); setSelected(null);
    search(filters, PAGE, 0)
      .then((r) => { if (!cancelled) { setResult(r); setRows(r.rows); } })
      .catch((err) => { if (!cancelled) { setError(errorText(err)); setResult(null); setRows([]); } })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [filters]);

  const set = (patch: Partial<TxFilters>) => setFilters((f) => ({ ...f, ...patch }));
  const dealerName = result?.agrodealers.find((a) => a.id === filters.agrodealer_id)?.name ?? null;

  async function loadMore() {
    setMore(true);
    try {
      const r = await search(filters, PAGE, rows.length);
      setRows((prev) => [...prev, ...r.rows]);
    } catch (err) { setError(errorText(err)); } finally { setMore(false); }
  }

  async function exportExcel() {
    setExporting(true); setError(null);
    try {
      const r = await search(filters, EXPORT_MAX, 0, true);
      await exportTransactions(r, filters, dealerName);
    } catch (err) { setError(errorText(err)); } finally { setExporting(false); }
  }

  async function toggleDetails(row: TxRow) {
    if (selected === row.id) {
      setSelected(null); setHousehold(null); setDetailError(null); return;
    }
    setSelected(row.id); setHousehold(null); setDetailError(null); setDetailLoading(true);
    try {
      setHousehold(await requestJson<HouseholdDetail>('GET', `/transactions/households/${row.household_id}`));
    } catch (err) {
      setDetailError(errorText(err));
    } finally {
      setDetailLoading(false);
    }
  }

  const s = result?.summary;
  const prev = lastMonth();
  const isMonth = filters.from === firstOfMonth() && filters.to === today();
  const isLastMonth = filters.from === prev.from && filters.to === prev.to;
  const isAll = !filters.from && !filters.to;

  return <>
    <div className="admin-page-heading">
      <div><div className="eyebrow">{eyebrow}</div><h1>Transações</h1>
        <p>Consulte cada compra, o beneficiário, o fornecedor e a utilização do voucher.</p>
      </div>
    </div>
    {error && <div className="error-box">{error}</div>}
    <div className="tx-period">
      <div className="tx-period-presets" role="group" aria-label="Período">
        <button type="button" className={isMonth ? 'active' : ''} onClick={() => set({ from: firstOfMonth(), to: today() })}>Este mês</button>
        <button type="button" className={isLastMonth ? 'active' : ''} onClick={() => set(prev)}>Mês passado</button>
        <button type="button" className={isAll ? 'active' : ''} onClick={() => set({ from: '', to: '' })}>Tudo</button>
      </div>
      <label>De <input type="date" className="input" value={filters.from} max={filters.to || undefined} onChange={(e) => set({ from: e.target.value })} /></label>
      <label>Até <input type="date" className="input" value={filters.to} min={filters.from || undefined} onChange={(e) => set({ to: e.target.value })} /></label>
      <button type="button" className="quick-link tx-export" onClick={() => void exportExcel()} disabled={exporting || loading || !result?.total}>
        {exporting ? 'A gerar…' : 'Exportar Excel'}
      </button>
    </div>
    <div className="admin-filters admin-transaction-filters tx-filters">
      <input className="input" placeholder="Pesquisar referência, voucher, CODIGO AF, beneficiário ou agrodealer" value={searchText} onChange={(e) => setSearchText(e.target.value)} />
      <select className="input" aria-label="Agrodealer" value={filters.agrodealer_id} onChange={(e) => set({ agrodealer_id: e.target.value })}>
        <option value="">Todos os agrodealers</option>
        {(result?.agrodealers ?? []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
      </select>
      <select className="input" aria-label="Estado" value={filters.status} onChange={(e) => set({ status: e.target.value })}>
        <option value="">Todos os estados</option><option value="CONFIRMED">Confirmadas</option>
        <option value="PENDING">Pendentes</option><option value="REVERSED">Anuladas</option><option value="REJECTED">Rejeitadas</option>
      </select>
    </div>
    {s && result && <div className="tx-summary" aria-live="polite">
      <div><span>Transações</span><strong>{result.total.toLocaleString('pt-PT')}</strong>
        <small>{s.by_status.CONFIRMED} confirmadas{s.by_status.REVERSED ? ` · ${s.by_status.REVERSED} anuladas` : ''}{s.by_status.PENDING ? ` · ${s.by_status.PENDING} pendentes` : ''}</small></div>
      <div><span>Total confirmado</span><strong>{formatMT(s.total_amount)}</strong><small>{periodLabel(filters)}</small></div>
      <div><span>Pago pelo voucher</span><strong>{formatMT(s.voucher_amount)}</strong><small>{s.households.toLocaleString('pt-PT')} agregado(s)</small></div>
      <div><span>Copagamento</span><strong>{formatMT(s.copayment_amount)}</strong><small>{s.agrodealers} agrodealer(s)</small></div>
    </div>}
    {loading ? <div className="loading-card"><span className="loading-dot" /> A carregar transações…</div> :
      <div className="admin-table-card admin-transactions-list">
        {rows.map((r) => <div className="admin-transaction-entry" key={r.id}>
          <button type="button" className="admin-transaction-row" aria-expanded={selected === r.id} title="Ver detalhes" onClick={() => void toggleDetails(r)}>
            <span className="admin-transaction-primary"><strong>{r.transaction_code}</strong><small>{r.voucher_code} · {formatDateTime(r.sold_at)}</small></span>
            <span className="admin-transaction-dealer"><strong>{r.agrodealer}</strong><small>{r.representative_name}</small></span>
            <span className="transaction-money"><strong>{formatMT(Number(r.total_amount))}</strong><small>Total utilizado</small></span>
            <span className={'badge transaction-status status-' + r.status.toLowerCase()}>{STATUS_LABEL[r.status] ?? r.status}</span>
          </button>
          {selected === r.id && <section className="admin-transaction-detail">
            {detailLoading ? <p className="muted-text">A carregar os dados do beneficiário…</p> : null}
            {detailError && <div className="error-box">{detailError}</div>}
            <div className="admin-transaction-detail-grid">
              <div className="admin-transaction-detail-block">
                <h3>Beneficiário</h3>
                <strong>{r.representative_name}</strong>
                <span>{r.household_code}</span>
                <span>{[household?.community, household?.district ?? r.district, household?.province ?? r.province].filter(Boolean).join(' · ') || 'Localização não disponível'}</span>
                {household?.status && <span>Estado: {household.status === 'ACTIVE' ? 'Activo' : 'Inactivo'}</span>}
              </div>
              <div className="admin-transaction-detail-block">
                <h3>Compra e fornecedor</h3>
                <strong>{r.agrodealer}</strong><span>{r.agrodealer_code}{r.pos_code ? ` · ${r.pos_code}` : ''}</span>
                <span>Voucher: {r.voucher_code}</span>
                <span>Referência: {r.transaction_code}</span><span>Data: {formatDateTime(r.sold_at)}</span>
                <span>Estado: {STATUS_LABEL[r.status] ?? r.status}</span>
              </div>
              <div className="admin-transaction-detail-block">
                <h3>Resumo financeiro</h3>
                <div><span>Custo total da compra</span><strong>{formatMT(Number(r.total_amount))}</strong></div>
                {Number(r.discount_amount) > 0 && <div><span>Desconto</span><strong>{formatMT(Number(r.discount_amount))}</strong></div>}
                <div><span>Valor descontado do voucher</span><strong>{formatMT(Number(r.voucher_amount))}</strong></div>
                <div><span>Copagamento do beneficiário</span><strong>{formatMT(Number(r.copayment_amount))}</strong></div>
                {household?.cards?.find((c) => c.voucher_code === r.voucher_code) &&
                  <div><span>Saldo actual do voucher</span><strong>{formatMT(Number(household.cards.find((c) => c.voucher_code === r.voucher_code)?.current_balance))}</strong></div>}
              </div>
            </div>
          </section>}
        </div>)}
        {rows.length === 0 && <div className="recent-empty">Nenhuma transação neste período.</div>}
        {result && rows.length < result.total && <button type="button" className="tx-more" onClick={() => void loadMore()} disabled={more}>
          {more ? 'A carregar…' : `Mostrar mais (${rows.length.toLocaleString('pt-PT')} de ${result.total.toLocaleString('pt-PT')})`}
        </button>}
      </div>}
    {result && result.total > EXPORT_MAX && <div className="warning-box small-note">O Excel leva no máximo {EXPORT_MAX.toLocaleString('pt-PT')} transações: escolha um período mais curto para ter todas.</div>}
  </>;
}
