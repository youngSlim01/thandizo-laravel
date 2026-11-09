import { Head, usePage } from '@inertiajs/react';
import { type ChangeEvent, useCallback, useEffect, useState } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { HouseholdList } from '@/components/households/household-list';
import { HouseholdLists } from '@/components/households/household-lists';
import { formatDateTime, formatMT } from '@/lib/format';
import { type ImportRow, OPTIONAL_HEADERS, readHouseholdFile, REQUIRED_HEADERS } from '@/lib/household-import';
import { errorText, requestJson } from '@/lib/http';

// Beneficiários, como app/admin/households/page.tsx do Next.js: lista com
// filtros e ficha, listas para imprimir e importação da lista MEAL.

type ImportError = { row: number; field: string; code: string };
type Summary = {
  rows: number;
  generated_codes: number;
  new_households: number;
  existing_households: number;
  new_provinces: number;
  new_districts: number;
  new_administrative_posts: number;
  new_localities: number;
  new_communities: number;
};
type Tier = { id: string; name: string; amount: number };
type ImportResult = {
  valid: boolean;
  rows_without_amount?: number;
  needs_tier?: boolean;
  committed: boolean;
  batch_id?: string;
  error_count: number;
  errors: ImportError[];
  summary: Summary;
  by_tier?: { name: string; amount: number; households: number; generated_codes: number }[];
  generated_codes?: { row: number; household_code: string; representative_name: string }[];
  possible_duplicates?: { row: number; representative_name: string; matches: string }[];
  existing_codes?: string[];
};
type Batch = { id: string; file_name: string | null; row_count: number; created_households: number; existing_households: number; created_at: string };

const CODE_LABELS: Record<string, string> = {
  REQUIRED: 'em falta',
  INVALID_HOUSEHOLD_CODE: 'formato inválido (ex.: 2022AF000123)',
  DUPLICATE_IN_FILE: 'repetido no ficheiro',
  INVALID_GENDER: 'use Feminino ou Masculino',
  INVALID_DATE: 'data inválida (use dd/mm/aaaa)',
  DATE_OUT_OF_RANGE: 'data fora do intervalo',
  PROVINCE_OUT_OF_SCOPE: 'província fora da sua área',
  INVALID_AMOUNT: 'valor inválido (ex.: 7500)',
  NO_TIER_FOR_AMOUNT: 'não há escalão ativo com este valor (crie-o na Configuração)',
};

// Lista dos códigos gerados, para o MEAL completar o seu ficheiro.
function downloadGeneratedCodes(codes: { row: number; household_code: string; representative_name: string }[], fileName: string | null) {
  const quote = (v: string | number) => `"${String(v).replace(/"/g, '""')}"`;
  const csv = ['LINHA;CODIGO AF;REPRESENTANTE', ...codes.map((c) => [c.row, c.household_code, c.representative_name].map(quote).join(';'))].join('\r\n');
  const link = document.createElement('a');
  link.href = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' }));
  link.download = `codigos-gerados-${(fileName ?? 'importacao').replace(/\.[^.]+$/, '')}.csv`;
  link.click();
  URL.revokeObjectURL(link.href);
}

function SummaryView({ summary, committed }: { summary: Summary; committed: boolean }) {
  const geo = [
    ['Províncias', summary.new_provinces],
    ['Distritos', summary.new_districts],
    ['Postos administrativos', summary.new_administrative_posts],
    ['Localidades', summary.new_localities],
    ['Bairros/povoações', summary.new_communities],
  ] as const;
  return (
    <>
      <div className="sales-metrics">
        <div><span>Linhas</span><strong>{summary.rows}</strong></div>
        <div><span>{committed ? 'Agregados criados' : 'Agregados novos'}</span><strong>{summary.new_households}</strong></div>
        <div><span>Já registados (ignorados)</span><strong>{summary.existing_households}</strong></div>
        {summary.generated_codes > 0 && <div><span>{committed ? 'CODIGO AF gerados' : 'CODIGO AF a gerar'}</span><strong>{summary.generated_codes}</strong></div>}
      </div>
      {!committed && summary.generated_codes > 0 && (
        <div className="warning-box">
          {summary.generated_codes} {summary.generated_codes === 1 ? 'agregado não tem' : 'agregados não têm'} CODIGO AF: o sistema gera um código definitivo para cada.
          Confirme que não têm mesmo código oficial: um código gerado nunca é substituído.
        </div>
      )}
      {geo.some(([, n]) => n > 0) && (
        <div className="info-box">
          {committed ? 'Geografia criada' : 'Geografia nova a criar'}:{' '}
          {geo.filter(([, n]) => n > 0).map(([label, n]) => `${n} ${label.toLowerCase()}`).join(' · ')}
        </div>
      )}
    </>
  );
}

function HouseholdImport({ onShowList }: { onShowList: () => void }) {
  const [fileName, setFileName] = useState<string | null>(null);
  const [rows, setRows] = useState<ImportRow[] | null>(null);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [batches, setBatches] = useState<Batch[]>([]);
  // Grupo dos agregados sem VALOR no ficheiro: escolhido a cada ficheiro, sem nenhum pré-seleccionado.
  const tiers = usePage<{ tiers: Tier[] }>().props.tiers;
  const [tierId, setTierId] = useState('');

  const loadBatches = useCallback(() => {
    requestJson<Batch[]>('GET', '/admin/households/import/batches').then(setBatches).catch(() => setBatches([]));
  }, []);

  useEffect(() => { loadBatches(); }, [loadBatches]);

  async function chooseFile(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    setFileName(file.name); setRows(null); setResult(null); setError(null); setTierId('');
    setBusy('A ler o ficheiro…');
    try {
      const parsed = await readHouseholdFile(file);
      if (parsed.rows.length === 0 && parsed.missingHeaders.length === REQUIRED_HEADERS.length) {
        throw new Error('Não encontrei o cabeçalho da lista (CODIGO AF, NOME, APELIDO…) nas primeiras linhas do ficheiro.');
      }
      if (parsed.missingHeaders.length > 0) {
        setError(`Faltam colunas no ficheiro: ${parsed.missingHeaders.join(', ')}.`);
        return;
      }
      setRows(parsed.rows);
      setBusy(`A validar ${parsed.rows.length} linhas…`);
      setResult(await requestJson<ImportResult>('POST', '/admin/households/import', { rows: parsed.rows, file_name: file.name, commit: false, tier_id: null }));
    } catch (err) {
      setError(errorText(err));
    } finally {
      setBusy(null);
    }
  }

  async function chooseTier(id: string) {
    setTierId(id);
    if (!rows) return;
    setError(null); setBusy('A validar…');
    try {
      setResult(await requestJson<ImportResult>('POST', '/admin/households/import', { rows, file_name: fileName, commit: false, tier_id: id || null }));
    } catch (err) {
      setError(errorText(err));
    } finally {
      setBusy(null);
    }
  }

  async function commit() {
    if (!rows) return;
    setError(null); setBusy('A importar…');
    try {
      const done = await requestJson<ImportResult>('POST', '/admin/households/import', { rows, file_name: fileName, commit: true, tier_id: tierId || null });
      setResult(done);
      setRows(null);
      loadBatches();
    } catch (err) {
      setError(errorText(err));
    } finally {
      setBusy(null);
    }
  }

  return (
    <>
      <div className="info-box">
        Importe a lista do MEAL em <strong>.xlsx</strong> ou <strong>.csv</strong>. Cada linha é o representante de um agregado.
        Colunas: {REQUIRED_HEADERS.map((h) => h.label).join(', ')}. Coluna opcional: {OPTIONAL_HEADERS.map((h) => h.label).join(', ')}, o valor
        do voucher de cada agregado, que o coloca no escalão com esse valor. Para os agregados sem valor no ficheiro, o sistema pergunta a que grupo pertencem.
        O CODIGO AF pode ficar vazio só nos escalões que não o exigem: o sistema gera um código definitivo.
        A lista é validada antes de gravar; se houver um erro, nada é gravado. Agregados cujo CODIGO AF já existe ficam como estão.
      </div>

      <label className="primary-button link-button file-button">
        ESCOLHER FICHEIRO
        <input type="file" accept=".xlsx,.csv" onChange={chooseFile} disabled={busy !== null} hidden />
      </label>

      {fileName && <div className="muted-text small-note">Ficheiro: {fileName}</div>}
      {busy && <div className="loading-card"><span className="loading-dot" /> {busy}</div>}
      {error && <div className="error-box">{error}</div>}

      {result && result.committed && (
        <div className="success-box">
          Importação concluída. {result.summary.new_households} agregados criados.{' '}
          <button className="quick-link inline-link" onClick={onShowList}>Ver na lista →</button>
        </div>
      )}
      {result && result.committed && (result.generated_codes?.length ?? 0) > 0 && (
        <div className="info-box">
          {result.generated_codes!.length} CODIGO AF gerados. Descarregue a lista e actualize o ficheiro do MEAL com estes códigos,
          para a próxima importação os reconhecer.{' '}
          <button className="quick-link inline-link" onClick={() => downloadGeneratedCodes(result.generated_codes!, fileName)}>Descarregar códigos gerados (.csv)</button>
        </div>
      )}

      {result && (
        <>
          <h2>{result.committed ? 'Resultado' : 'Pré-visualização'}</h2>
          {!result.committed && (result.rows_without_amount ?? 0) > 0 && (
            <div className={tierId ? 'info-box tier-choice' : 'warning-box tier-choice'}>
              <label htmlFor="import-tier">
                <strong>{result.rows_without_amount} agregado(s) sem valor no ficheiro.</strong> A que grupo pertencem?
              </label>
              <select id="import-tier" className="input" value={tierId} onChange={(e) => chooseTier(e.target.value)} disabled={busy !== null}>
                <option value="">Escolha o grupo…</option>
                {tiers.map((t) => <option key={t.id} value={t.id}>{t.name} · {formatMT(t.amount)}</option>)}
              </select>
              {(result.rows_without_amount ?? 0) < result.summary.rows && <small>Os restantes ficam no grupo do valor que vem no ficheiro.</small>}
            </div>
          )}
          <SummaryView summary={result.summary} committed={result.committed} />
          {(result.by_tier?.length ?? 0) > 0 && (
            <table className="import-errors">
              <thead><tr><th>Escalão</th><th>Valor</th><th>{result.committed ? 'Agregados criados' : 'Agregados novos'}</th><th>Sem CODIGO AF</th><th>Total por ativação</th></tr></thead>
              <tbody>
                {result.by_tier!.map((t) => (
                  <tr key={t.name}><td>{t.name}</td><td>{formatMT(t.amount)}</td><td>{t.households}</td><td>{t.generated_codes}</td><td>{formatMT(t.amount * t.households)}</td></tr>
                ))}
              </tbody>
            </table>
          )}

          {result.error_count > 0 && (
            <>
              <div className="error-box">
                {result.error_count} {result.error_count === 1 ? 'erro encontrado' : 'erros encontrados'}. Corrija o ficheiro e escolha-o de novo.
                {result.error_count > result.errors.length && ` São mostrados os primeiros ${result.errors.length}.`}
              </div>
              <table className="import-errors">
                <thead><tr><th>Linha</th><th>Coluna</th><th>Problema</th></tr></thead>
                <tbody>
                  {result.errors.map((e, i) => (
                    <tr key={i}><td>{e.row}</td><td>{e.field}</td><td>{CODE_LABELS[e.code] ?? e.code}</td></tr>
                  ))}
                </tbody>
              </table>
            </>
          )}

          {!result.committed && (result.possible_duplicates?.length ?? 0) > 0 && (
            <>
              <div className="warning-box">
                {result.possible_duplicates!.length} {result.possible_duplicates!.length === 1 ? 'linha sem CODIGO AF parece' : 'linhas sem CODIGO AF parecem'} ser
                um agregado já registado (mesmo nome, data de nascimento e bairro). Se for a mesma pessoa, ponha no ficheiro o código que ela já tem;
                se importar assim, fica registada duas vezes, com dois códigos.
              </div>
              <table className="import-errors">
                <thead><tr><th>Linha</th><th>Representante</th><th>Igual a</th></tr></thead>
                <tbody>
                  {result.possible_duplicates!.map((d) => <tr key={d.row}><td>{d.row}</td><td>{d.representative_name}</td><td>{d.matches}</td></tr>)}
                </tbody>
              </table>
            </>
          )}

          {!result.committed && (result.existing_codes?.length ?? 0) > 0 && (
            <div className="muted-text small-note">
              Já registados: {result.existing_codes!.join(', ')}{result.summary.existing_households > result.existing_codes!.length ? '…' : ''}
            </div>
          )}

          {!result.committed && result.valid && rows && (
            result.summary.new_households > 0 ? (
              <button className="primary-button confirm-button" onClick={commit} disabled={busy !== null}>
                {(result.possible_duplicates?.length ?? 0) > 0 ? 'IMPORTAR MESMO ASSIM' : 'IMPORTAR'} {result.summary.new_households} AGREGADOS
              </button>
            ) : (
              <div className="info-box">Todos os agregados deste ficheiro já estão registados.</div>
            )
          )}
        </>
      )}

      <h2>Importações recentes</h2>
      {batches.length === 0 ? <p className="muted-text">Ainda não há importações.</p> : (
        <table className="import-errors">
          <thead><tr><th>Data</th><th>Ficheiro</th><th>Linhas</th><th>Criados</th><th>Já existiam</th></tr></thead>
          <tbody>
            {batches.map((b) => (
              <tr key={b.id}><td>{formatDateTime(b.created_at)}</td><td>{b.file_name ?? '—'}</td><td>{b.row_count}</td><td>{b.created_households}</td><td>{b.existing_households}</td></tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  );
}

export default function Households() {
  const [tab, setTab] = useState<'list' | 'lists' | 'import'>('list');
  const [listKey, setListKey] = useState(0);
  return (
    <AdminLayout>
      <Head title="Beneficiários" />
      <div className="admin-page-heading"><div><div className="eyebrow">MEAL · BENEFICIÁRIOS</div><h1>Beneficiários</h1><p>Registo, consulta, actualização e impressão das listas de agregados familiares.</p></div></div>
      <div className="tab-row" role="tablist">
        <button role="tab" aria-selected={tab === 'list'} className={tab === 'list' ? 'tab active' : 'tab'} onClick={() => setTab('list')}>Lista</button>
        <button role="tab" aria-selected={tab === 'lists'} className={tab === 'lists' ? 'tab active' : 'tab'} onClick={() => setTab('lists')}>Listas</button>
        <button role="tab" aria-selected={tab === 'import'} className={tab === 'import' ? 'tab active' : 'tab'} onClick={() => setTab('import')}>Importar</button>
      </div>
      {tab === 'list' && <HouseholdList key={listKey} />}
      {tab === 'lists' && <HouseholdLists />}
      {tab === 'import' && <HouseholdImport onShowList={() => { setListKey((k) => k + 1); setTab('list'); }} />}
    </AdminLayout>
  );
}
