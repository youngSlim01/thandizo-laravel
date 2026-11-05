
import { useCallback, useEffect, useMemo, useState } from 'react';
import { callRpc } from '../../../lib/supabase';
import { errorMessage } from '../../../lib/errors';
import { formatMT } from '../../../lib/format';
import { QrScanner } from '../../components/qr-scanner';
import { RELATIONSHIPS, relationshipLabel } from '../../../lib/substitute';

// Distribution day: MEAL ticks the households that are present (or scans
// their card) and only those cards are activated with 6,000 MT. When the
// representative is absent, the card can go to a substitute: name and
// relationship are recorded on the card; the household data stays as is.

type Card = {
  voucher_id: string;
  voucher_code: string;
  voucher_status: string;
  household_code: string;
  household_status: string;
  representative_name: string;
  received_by_name: string | null;
  received_by_relationship: string | null;
};

type Substitute = { name: string; relationship: string };

const CARD_VALUE = 6000;

export function DistributePanel({ batchId, batchCode, onDone, onClose }: {
  batchId: string;
  batchCode: string;
  onDone: (message: string) => void;
  onClose: () => void;
}) {
  const [cards, setCards] = useState<Card[] | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [subs, setSubs] = useState<Record<string, Substitute>>({});
  const [filter, setFilter] = useState('');
  const [scanning, setScanning] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    callRpc<{ cards: Card[] }>('get_card_batch_cards', { p_batch_id: batchId })
      .then((r) => setCards(r.cards))
      .catch((err) => setError(errorMessage(err)));
  }, [batchId]);

  useEffect(() => { load(); }, [load]);

  const deliverable = (c: Card) => c.voucher_status === 'PENDING' && c.household_status === 'ACTIVE';
  const pending = (cards ?? []).filter(deliverable);
  const delivered = (cards ?? []).filter((c) => c.voucher_status === 'ACTIVE').length;

  const term = filter.trim().toLowerCase();
  const visible = useMemo(() => (cards ?? []).filter((c) => !term
    || c.representative_name.toLowerCase().includes(term)
    || c.household_code.toLowerCase().includes(term)
    || c.voucher_code.toLowerCase().includes(term)), [cards, term]);

  function toggle(id: string) {
    setSelected((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n; });
    setSubs((m) => { const n = { ...m }; delete n[id]; return n; });
  }

  function setSub(id: string, sub: Substitute | null) {
    setSubs((m) => { const n = { ...m }; if (sub) n[id] = sub; else delete n[id]; return n; });
  }

  const subList = Object.entries(subs).filter(([id]) => selected.has(id));
  const subsIncomplete = subList.some(([, v]) => !v.name.trim() || !v.relationship);

  const scanned = useCallback((text: string) => {
    setScanning(false);
    const code = text.trim().toUpperCase();
    const card = (cards ?? []).find((c) => c.voucher_code === code);
    if (!card) { setError(`O cartão ${code.slice(0, 40)} não pertence a este lote.`); setNotice(null); return; }
    setError(null);
    if (card.voucher_status === 'ACTIVE') { setNotice(`${card.representative_name} já recebeu o cartão.`); return; }
    if (!deliverable(card)) { setError(`O cartão de ${card.representative_name} não pode ser entregue (${card.household_status !== 'ACTIVE' ? 'agregado inativo' : 'cartão cancelado'}).`); return; }
    setSelected((s) => new Set(s).add(card.voucher_id));
    setNotice(`${card.representative_name} (${card.household_code}) marcado como presente.`);
  }, [cards]);

  async function activate() {
    setBusy(true); setError(null);
    try {
      const r = await callRpc<{ activated: number; remaining: number; status: string }>('distribute_cards', {
        p_batch_id: batchId, p_voucher_ids: Array.from(selected),
        p_substitutes: subList.map(([id, v]) => ({ voucher_id: id, name: v.name.trim(), relationship: v.relationship })),
      });
      setSelected(new Set()); setSubs({}); setConfirming(false); setNotice(null);
      load();
      onDone(`${batchCode}: ${r.activated} ${r.activated === 1 ? 'cartão ativado' : 'cartões ativados'} com ${formatMT(CARD_VALUE)} cada.`
        + (r.remaining > 0 ? ` Faltam entregar ${r.remaining}.` : ' Todos os cartões do lote foram entregues.'));
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="admin-form distribute-panel">
      <div className="row-between">
        <strong>Distribuir cartões — {batchCode}</strong>
        <button className="quick-link" onClick={onClose}>Fechar</button>
      </div>
      <p className="muted-text">
        Marque os agregados presentes, ou leia o QR do cartão. Só os cartões marcados ficam ativos com {formatMT(CARD_VALUE)}.
        {cards && ` ${delivered} de ${cards.length} já entregues.`}
      </p>

      <div className="distribute-tools">
        <input className="input" placeholder="Procurar nome, CODIGO AF ou código" value={filter} onChange={(e) => setFilter(e.target.value)}
          aria-label="Procurar no lote" />
        <button className="secondary-button" type="button" onClick={() => { setError(null); setScanning(true); }}>▣ LER QR</button>
        <button className="secondary-button" type="button" disabled={pending.length === 0}
          onClick={() => { setSelected(new Set(pending.map((c) => c.voucher_id))); setConfirming(false); }}>TODOS PRESENTES</button>
      </div>

      {notice && <div className="success-box">{notice}</div>}
      {error && <div className="error-box">{error}</div>}
      {scanning && <QrScanner onResult={scanned} onClose={() => setScanning(false)} />}

      {cards === null && !error && <p>A carregar…</p>}
      <div className="distribute-list">
        {visible.map((c) => {
          const can = deliverable(c);
          const label = c.voucher_status === 'ACTIVE'
            ? (c.received_by_name ? `Entregue a ${c.received_by_name} (${relationshipLabel(c.received_by_relationship)})` : 'Entregue')
            : c.voucher_status === 'CANCELLED' ? 'Cancelado'
            : c.household_status !== 'ACTIVE' ? 'Agregado inativo' : null;
          const isSelected = selected.has(c.voucher_id);
          const sub = subs[c.voucher_id];
          return (
            <div key={c.voucher_id} className={`distribute-row${can ? '' : ' done'}${isSelected ? ' selected' : ''}`}>
              <label className="distribute-main">
                <input type="checkbox" disabled={!can} checked={c.voucher_status === 'ACTIVE' || isSelected}
                  onChange={() => toggle(c.voucher_id)} aria-label={`Presente: ${c.representative_name}`} />
                <span className="distribute-who"><strong>{c.representative_name}</strong><small>{c.household_code} · {c.voucher_code}</small></span>
                {label && <span className="muted-text small-note">{label}</span>}
              </label>
              {can && isSelected && (
                <div className="distribute-sub">
                  <label className="distribute-sub-toggle">
                    <input type="checkbox" checked={!!sub} onChange={(e) => { setConfirming(false); setSub(c.voucher_id, e.target.checked ? { name: '', relationship: '' } : null); }}
                      aria-label={`Entregue a substituto: ${c.representative_name}`} />
                    Representante ausente — entregue a substituto
                  </label>
                  {sub && (
                    <div className="distribute-sub-fields">
                      <input className="input" placeholder="Nome de quem recebe" value={sub.name}
                        aria-label={`Nome do substituto de ${c.representative_name}`}
                        onChange={(e) => { setConfirming(false); setSub(c.voucher_id, { ...sub, name: e.target.value }); }} />
                      <select className="input" value={sub.relationship} aria-label={`Parentesco do substituto de ${c.representative_name}`}
                        onChange={(e) => { setConfirming(false); setSub(c.voucher_id, { ...sub, relationship: e.target.value }); }}>
                        <option value="">Parentesco…</option>
                        {RELATIONSHIPS.map((r) => <option key={r.code} value={r.code}>{r.label}</option>)}
                      </select>
                    </div>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>

      {confirming ? (
        <>
          <div className="warning-box">
            Confirme que entregou os cartões em mão. Isto ativa <strong>{selected.size} {selected.size === 1 ? 'cartão' : 'cartões'}</strong>, no total de{' '}
            <strong>{formatMT(selected.size * CARD_VALUE)}</strong>, e não pode ser desfeito.
            {subList.length > 0 && <> {subList.length === 1 ? '1 cartão é entregue' : `${subList.length} cartões são entregues`} a substituto; o agregado continua registado com o representante.</>}
          </div>
          <div className="admin-form-actions">
            <button className="secondary-button" onClick={() => setConfirming(false)} disabled={busy}>VOLTAR</button>
            <button className="primary-button" onClick={activate} disabled={busy}>{busy ? 'A ATIVAR…' : 'CONFIRMAR ENTREGA'}</button>
          </div>
        </>
      ) : (
        <>
        {subsIncomplete && <div className="muted-text small-note">Indique o nome e o parentesco de cada substituto.</div>}
        <button className="primary-button" disabled={selected.size === 0 || subsIncomplete} onClick={() => setConfirming(true)}>
          ATIVAR {selected.size} {selected.size === 1 ? 'CARTÃO' : 'CARTÕES'}
        </button>
        </>
      )}
    </div>
  );
}
