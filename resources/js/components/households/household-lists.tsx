import { useEffect, useMemo, useState } from 'react';
import { errorText, requestJson } from '@/lib/http';
import { GROUP_LABEL, type GroupLevel, type ListRow, exportHouseholdList, groupRows, printHouseholdList } from '@/lib/household-lists';
import { GeoFilter, type GeoValue, geoTitle, useGeography } from '@/components/geo-filter';

// Lists of households to print (PDF) or export (Excel), by province,
// district, post, locality or community. Up to 20,000 households per list.

export function HouseholdLists() {
  const geo = useGeography();
  const [place, setPlace] = useState<GeoValue>({});
  const [status, setStatus] = useState<'ACTIVE' | 'INACTIVE' | ''>('ACTIVE');
  const [cardStatus, setCardStatus] = useState('');
  const [groupBy, setGroupBy] = useState<GroupLevel>('community');
  const [signature, setSignature] = useState(true);
  const [card, setCard] = useState(true);
  const [title, setTitle] = useState('');
  const [rows, setRows] = useState<ListRow[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const filters = useMemo(() => ({ ...place, status, card_status: cardStatus }), [place, status, cardStatus]);
  const where = geoTitle(geo, place) || 'Todas as províncias';

  useEffect(() => {
    let cancelled = false;
    setRows(null); setError(null);
    requestJson<{ rows: ListRow[] }>('POST', '/admin/households/search', { filters, limit: 20000, offset: 0, sort: 'location' })
      .then((r) => { if (!cancelled) setRows(r.rows); })
      .catch((err) => { if (!cancelled) setError(errorText(err)); });
    return () => { cancelled = true; };
  }, [filters]);

  const groups = useMemo(() => (rows ? groupRows(rows, groupBy) : []), [rows, groupBy]);
  const options = () => ({
    title: title.trim() || `Lista de beneficiários — ${where}`,
    subtitle: `${where} · ${status === 'ACTIVE' ? 'agregados ativos' : status === 'INACTIVE' ? 'agregados inativos' : 'ativos e inativos'} · agrupado por ${GROUP_LABEL[groupBy].toLowerCase()}`,
    groupBy, signature, card,
  });

  async function run(kind: 'pdf' | 'xlsx') {
    if (!rows) return;
    setBusy(kind); setError(null);
    try { if (kind === 'pdf') await printHouseholdList(rows, options()); else await exportHouseholdList(rows, options()); }
    catch (err) { setError(errorText(err)); }
    finally { setBusy(null); }
  }

  return (
    <div className="household-lists">
      <p className="muted-text">Escolha a zona e como agrupar. O PDF sai em A4 deitado, com um bloco por grupo e, se quiser, uma coluna para a assinatura ou impressão digital (listas de distribuição).</p>
      <GeoFilter geo={geo} value={place} onChange={setPlace} idPrefix="lists-geo" />
      <div className="household-filters">
        <select className="input" value={groupBy} onChange={(e) => setGroupBy(e.target.value as GroupLevel)} aria-label="Agrupar por">
          {(Object.keys(GROUP_LABEL) as GroupLevel[]).map((g) => <option key={g} value={g}>Agrupar por {GROUP_LABEL[g].toLowerCase()}</option>)}
        </select>
        <select className="input" value={status} onChange={(e) => setStatus(e.target.value as 'ACTIVE' | 'INACTIVE' | '')} aria-label="Situação">
          <option value="ACTIVE">Só ativos</option><option value="INACTIVE">Só inativos</option><option value="">Ativos e inativos</option>
        </select>
        <select className="input" value={cardStatus} onChange={(e) => setCardStatus(e.target.value)} aria-label="Cartão">
          <option value="">Todos os cartões</option><option value="NONE">Sem cartão</option><option value="PENDING">Por distribuir</option>
          <option value="ACTIVE">Cartão ativo</option><option value="EMPTY">Sem saldo</option>
        </select>
      </div>
      <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} placeholder={`Título: Lista de beneficiários — ${where}`} aria-label="Título da lista" />
      <div className="household-list-options">
        <label><input type="checkbox" checked={signature} onChange={(e) => setSignature(e.target.checked)} /> Coluna de assinatura</label>
        <label><input type="checkbox" checked={card} onChange={(e) => setCard(e.target.checked)} /> Código do cartão</label>
      </div>
      {error && <div className="error-box">{error}</div>}
      <div className="info-box">
        {rows === null ? 'A preparar a lista…' : `${rows.length.toLocaleString('pt-PT')} agregados em ${groups.length} grupo(s)`}
        {rows && rows.length >= 20000 && ' — limite de 20.000: escolha uma zona mais pequena.'}
      </div>
      <div className="admin-form-actions">
        <button className="primary-button" onClick={() => run('pdf')} disabled={!rows || rows.length === 0 || busy !== null}>{busy === 'pdf' ? 'A GERAR…' : 'IMPRIMIR PDF'}</button>
        <button className="secondary-button" onClick={() => run('xlsx')} disabled={!rows || rows.length === 0 || busy !== null}>{busy === 'xlsx' ? 'A GERAR…' : 'EXPORTAR EXCEL'}</button>
      </div>
      {groups.length > 0 && (
        <ul className="household-groups">
          {groups.slice(0, 50).map((g) => <li key={g.name}><span>{g.name}</span><strong>{g.items.length}</strong></li>)}
          {groups.length > 50 && <li className="muted-text">… e mais {groups.length - 50} grupos</li>}
        </ul>
      )}
    </div>
  );
}
