
import { FormEvent, useCallback, useEffect, useState } from 'react';
import { HOUSEHOLD_ROLES, useAuth } from '../../auth-context';
import { AdminGate } from '../../components/admin-gate';
import { AdminHeader } from '../../components/admin-header';
import { callRpc } from '../../../lib/supabase';
import { errorMessage } from '../../../lib/errors';
import { formatDateTime, formatMT } from '../../../lib/format';
import { downloadCardsPdf, VoucherCard } from '../../../lib/voucher-cards';
import { DistributePanel } from './distribute-panel';

type Community = {
  community_id: string;
  community: string;
  locality: string | null;
  administrative_post: string | null;
  district: string;
  province: string;
  households: number;
  households_without_card: number;
  open_batches: number;
};

type Batch = {
  batch_id: string;
  batch_code: string;
  status: 'GENERATED' | 'PRINTED' | 'IN_DISTRIBUTION' | 'DISTRIBUTED' | 'CANCELLED';
  card_count: number;
  active_cards: number;
  pending_cards: number;
  created_at: string;
  printed_at: string | null;
  distributed_at: string | null;
  cancelled_at: string | null;
  cancel_reason: string | null;
  community: string;
  locality: string | null;
  district: string;
};

type HouseholdCard = VoucherCard & { voucher_status: string; current_balance: number; distributed_at: string | null };

const MANAGER_ROLES = ['MEAL_MANAGER', 'SYSTEM_ADMIN'];

const BATCH_STATUS: Record<Batch['status'], string> = {
  GENERATED: 'Gerado',
  PRINTED: 'Impresso',
  IN_DISTRIBUTION: 'Em distribuição',
  DISTRIBUTED: 'Distribuído',
  CANCELLED: 'Cancelado',
};

const VOUCHER_STATUS: Record<string, string> = {
  ACTIVE: 'Ativo',
  PENDING: 'Por distribuir',
  REPLACED: 'Substituído',
  CANCELLED: 'Cancelado',
  INVALIDATED: 'Invalidado',
};

async function downloadBatch(batch: Batch) {
  const data = await callRpc<{ batch_code: string; cards: VoucherCard[] }>('get_card_batch_cards', { p_batch_id: batch.batch_id });
  await downloadCardsPdf(data.cards, data.batch_code);
}

function BatchRow({ batch, canCancel, onChanged }: { batch: Batch; canCancel: boolean; onChanged: (message: string) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<'distribute' | 'cancel' | null>(null);
  const [reason, setReason] = useState('');
  const [expanded, setExpanded] = useState(false);
  const [cards, setCards] = useState<VoucherCard[] | null>(null);
  const [cardsLoading, setCardsLoading] = useState(false);
  const [selectedCard, setSelectedCard] = useState<VoucherCard | null>(null);
  useEffect(() => {
    if (!selectedCard) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setSelectedCard(null); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [selectedCard]);

  async function toggleBatchCards() {
    if (expanded) { setExpanded(false); setSelectedCard(null); return; }
    setExpanded(true);
    if (cards) return;
    setCardsLoading(true); setError(null);
    try {
      const data = await callRpc<{ batch_code: string; cards: VoucherCard[] }>('get_card_batch_cards', { p_batch_id: batch.batch_id });
      setCards(data.cards ?? []);
    } catch (err) { setError(errorMessage(err)); }
    finally { setCardsLoading(false); }
  }

  async function printSelectedCard(card: VoucherCard) {
    setBusy(true); setError(null);
    try {
      await downloadCardsPdf([card], card.voucher_code);
      onChanged(`Cartão ${card.voucher_code} preparado para impressão.`);
    } catch (err) { setError(errorMessage(err)); }
    finally { setBusy(false); }
  }

  async function run(action: () => Promise<string>) {
    setBusy(true); setError(null);
    try { onChanged(await action()); setConfirming(null); }
    catch (err) { setError(errorMessage(err)); }
    finally { setBusy(false); }
  }

  const pdf = () => run(async () => { await downloadBatch(batch); return `PDF do ${batch.batch_code} descarregado.`; });
  const printed = () => run(async () => {
    await callRpc('mark_card_batch_printed', { p_batch_id: batch.batch_id });
    return `${batch.batch_code} marcado como impresso.`;
  });
  const closing = batch.status === 'IN_DISTRIBUTION';
  const cancel = (event: FormEvent) => {
    event.preventDefault();
    run(async () => {
      const r = await callRpc<{ cancelled_cards: number }>('cancel_card_batch', { p_batch_id: batch.batch_id, p_reason: reason });
      return closing
        ? `${batch.batch_code} fechado. ${r.cancelled_cards} cartões não entregues foram cancelados; esses agregados podem receber cartão num lote novo.`
        : `${batch.batch_code} cancelado. Os agregados podem receber cartão num lote novo.`;
    });
  };

  return (
    <div className={`card card-batch status-${batch.status.toLowerCase()}`}>
      <div className="row-between">
        <div>
          <strong>{batch.batch_code}</strong>
          <div className="muted-text">{batch.community}{batch.locality ? `, ${batch.locality}` : ''} · {batch.district}</div>
        </div>
        <span className={`badge badge-batch-${batch.status.toLowerCase()}`}>{BATCH_STATUS[batch.status]}</span>
      </div>
      <div className="admin-user-meta">
        <span>{batch.card_count} cartões</span>
        <span>· gerado {formatDateTime(batch.created_at)}</span>
        {batch.printed_at && <span>· impresso {formatDateTime(batch.printed_at)}</span>}
        {(batch.status === 'IN_DISTRIBUTION' || batch.status === 'DISTRIBUTED') && <span>· {batch.active_cards} de {batch.card_count} entregues</span>}
        {batch.distributed_at && <span>· concluído {formatDateTime(batch.distributed_at)}</span>}
        {batch.cancelled_at && <span>· cancelado: {batch.cancel_reason}</span>}
      </div>

      <div className="batch-actions">
        <button className="quick-link" onClick={() => void toggleBatchCards()} disabled={busy}>
          {expanded ? 'Ocultar cartões' : 'Ver cartões do lote'}
        </button>
        {batch.status !== 'CANCELLED' && confirming === null && (
          <>
          <button className="quick-link" onClick={pdf} disabled={busy}>Imprimir lote completo (PDF)</button>
          {batch.status === 'GENERATED' && <button className="quick-link" onClick={printed} disabled={busy}>Marcar impresso</button>}
          {(batch.status === 'PRINTED' || batch.status === 'IN_DISTRIBUTION') && (
            <button className="quick-link" onClick={() => setConfirming('distribute')} disabled={busy}>
              Distribuir cartões{batch.status === 'IN_DISTRIBUTION' ? ` (faltam ${batch.pending_cards})` : ''}
            </button>
          )}
          {canCancel && (batch.status === 'GENERATED' || batch.status === 'PRINTED') && (
            <button className="quick-link danger" onClick={() => setConfirming('cancel')} disabled={busy}>Cancelar lote</button>
          )}
          {canCancel && batch.status === 'IN_DISTRIBUTION' && (
            <button className="quick-link danger" onClick={() => setConfirming('cancel')} disabled={busy}>Fechar lote</button>
          )}
          </>
        )}
      </div>

      {expanded && (
        <div className="card-batch-cards">
          {cardsLoading && <p className="muted-text">A carregar cartões…</p>}
          {cards && cards.length === 0 && <p className="muted-text">Este lote não tem cartões para mostrar.</p>}
          {cards?.map((card) => (
            <button type="button" className={selectedCard?.voucher_code === card.voucher_code ? 'batch-card-item selected' : 'batch-card-item'}
              key={card.voucher_code} onClick={() => setSelectedCard(card)}>
              <span className="batch-card-item-code">{card.household_code}</span>
              <span className="batch-card-item-name">{card.representative_name}</span>
              <span className="batch-card-item-voucher">{card.voucher_code}</span>
              <span className="batch-card-item-action">Ver cartão</span>
            </button>
          ))}
          {selectedCard && (
            <div className="card-preview-overlay" onClick={() => setSelectedCard(null)}>
            <div className="card-preview-modal" role="dialog" aria-modal="true" aria-label={`Cartão de ${selectedCard.representative_name}`} onClick={(e) => e.stopPropagation()}>
              <div className="card-preview-modal-head">
                <strong>Cartão de {selectedCard.representative_name}</strong>
                <button type="button" className="card-preview-close" onClick={() => setSelectedCard(null)} aria-label="Fechar">✕</button>
              </div>
              <div className="voucher-card-preview">
                <div className="voucher-card-preview-head">
                  <div><strong>THANDIZO</strong><small>PROJECTO LINK GCF</small></div>
                  <span>e-VOUCHER</span>
                </div>
                <div className="voucher-card-preview-body">
                  <div className="voucher-card-preview-info">
                    <small>REPRESENTANTE</small><strong>{selectedCard.representative_name}</strong>
                    <small>CÓDIGO DO AF</small><strong>{selectedCard.household_code}</strong>
                    <span>{[selectedCard.community, selectedCard.locality].filter(Boolean).join(', ') || '—'}</span>
                    <span>{[selectedCard.district, selectedCard.province].filter(Boolean).join(' · ') || '—'}</span>
                    <b>{selectedCard.voucher_code}</b>
                  </div>
                  <div className="voucher-card-preview-qr"><span>QR</span><small>{selectedCard.voucher_code}</small></div>
                </div>
                <div className="voucher-card-preview-foot">Válido apenas para o agregado indicado</div>
              </div>
              <div className="batch-actions">
                <button className="quick-link" onClick={() => void printSelectedCard(selectedCard)} disabled={busy}>Imprimir cartão seleccionado (PDF)</button>
                <button className="quick-link" onClick={() => setSelectedCard(null)}>Fechar</button>
              </div>
              <p className="muted-text small-note">A pré-visualização é ilustrativa. O PDF mantém o desenho oficial do cartão e inclui o QR real.</p>
            </div>
            </div>
          )}
        </div>
      )}

      {confirming === 'distribute' && (
        <DistributePanel batchId={batch.batch_id} batchCode={batch.batch_code}
          onDone={(message) => onChanged(message)} onClose={() => setConfirming(null)} />
      )}

      {confirming === 'cancel' && (
        <form className="admin-form" onSubmit={cancel}>
          {closing && (
            <div className="warning-box">
              Os {batch.pending_cards} cartões ainda não entregues serão cancelados. Os cartões já entregues continuam a funcionar.
            </div>
          )}
          <label className="form-label" htmlFor={`cancel-${batch.batch_id}`}>{closing ? 'Motivo para fechar o lote' : 'Motivo do cancelamento'}</label>
          <input id={`cancel-${batch.batch_id}`} className="input" value={reason} onChange={(e) => setReason(e.target.value)}
            placeholder="Ex.: erro de impressão" required />
          <div className="admin-form-actions">
            <button type="button" className="secondary-button" onClick={() => setConfirming(null)} disabled={busy}>VOLTAR</button>
            <button type="submit" className="danger-button" disabled={busy || !reason.trim()}>{closing ? 'FECHAR LOTE' : 'CANCELAR LOTE'}</button>
          </div>
        </form>
      )}

      {error && <div className="error-box">{error}</div>}
    </div>
  );
}

function ReplaceCard({ canReplace }: { canReplace: boolean }) {
  const [query, setQuery] = useState('');
  const [cards, setCards] = useState<HouseholdCard[] | null>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [replaced, setReplaced] = useState<{ old_voucher_code: string; new_voucher_code: string; transferred: number; card: VoucherCard } | null>(null);

  async function search(event: FormEvent) {
    event.preventDefault();
    setError(null); setReplaced(null); setBusy(true);
    try { setCards(await callRpc<HouseholdCard[]>('find_household_cards', { p_query: query })); }
    catch (err) { setError(errorMessage(err)); }
    finally { setBusy(false); }
  }

  async function replace(card: HouseholdCard) {
    setError(null); setBusy(true);
    try {
      const r = await callRpc<{ old_voucher_code: string; new_voucher_code: string; transferred: number; card: VoucherCard }>(
        'replace_voucher', { p_voucher_code: card.voucher_code, p_reason: reason });
      setReplaced(r); setReason('');
      setCards(await callRpc<HouseholdCard[]>('find_household_cards', { p_query: card.household_code }));
    } catch (err) { setError(errorMessage(err)); }
    finally { setBusy(false); }
  }

  return (
    <>
      <h2>Cartão perdido</h2>
      <p className="muted-text">
        O cartão perdido é cancelado e é emitido um novo, com o saldo que restava. O cartão antigo deixa de funcionar no POS.
      </p>
      <form className="replace-search" onSubmit={search}>
        <input className="input" value={query} onChange={(e) => setQuery(e.target.value.toUpperCase())}
          placeholder="Código do voucher ou CODIGO AF" aria-label="Código do voucher ou CODIGO AF" />
        <button className="secondary-button" type="submit" disabled={busy || !query.trim()}>PROCURAR</button>
      </form>

      {replaced && (
        <div className="success-box">
          Cartão {replaced.old_voucher_code} substituído por <strong>{replaced.new_voucher_code}</strong> com {formatMT(replaced.transferred)}.
          <button className="secondary-button link-button" onClick={() => downloadCardsPdf([replaced.card], replaced.new_voucher_code)}>
            DESCARREGAR CARTÃO NOVO (PDF)
          </button>
        </div>
      )}
      {error && <div className="error-box">{error}</div>}
      {cards && cards.length === 0 && <p className="muted-text">Nenhum cartão encontrado.</p>}

      {cards?.map((card) => (
        <div className="card" key={card.voucher_code}>
          <div className="row-between">
            <div>
              <strong>{card.voucher_code}</strong>
              <div className="muted-text">{card.representative_name} · {card.household_code} · {card.community ?? '—'}</div>
            </div>
            <span className={`badge badge-voucher-${card.voucher_status.toLowerCase()}`}>{VOUCHER_STATUS[card.voucher_status] ?? card.voucher_status}</span>
          </div>
          <div className="admin-user-meta"><span>Saldo {formatMT(card.current_balance)}</span></div>
          {card.voucher_status === 'ACTIVE' && canReplace && (
            <div className="admin-form">
              <label className="form-label" htmlFor={`reason-${card.voucher_code}`}>Motivo</label>
              <input id={`reason-${card.voucher_code}`} className="input" value={reason} onChange={(e) => setReason(e.target.value)}
                placeholder="Ex.: cartão perdido" />
              <button className="danger-button" onClick={() => replace(card)} disabled={busy || !reason.trim()}>
                CANCELAR E EMITIR NOVO CARTÃO
              </button>
            </div>
          )}
          {card.voucher_status === 'ACTIVE' && !canReplace && (
            <div className="muted-text small-note">Só um gestor MEAL ou administrador pode substituir cartões.</div>
          )}
        </div>
      ))}
    </>
  );
}

function CardsScreen() {
  const auth = useAuth();
  const isManager = MANAGER_ROLES.includes(auth.operator?.role ?? '');
  const [communities, setCommunities] = useState<Community[] | null>(null);
  const [batches, setBatches] = useState<Batch[]>([]);
  const [filter, setFilter] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    callRpc<Community[]>('list_card_communities', {}).then(setCommunities).catch((err) => setError(errorMessage(err)));
    callRpc<Batch[]>('list_card_batches', {}).then(setBatches).catch((err) => setError(errorMessage(err)));
  }, []);

  useEffect(() => { load(); }, [load]);

  function changed(message: string) { setNotice(message); setError(null); load(); }

  async function generate(community: Community) {
    setBusy(community.community_id); setError(null); setNotice(null);
    try {
      const r = await callRpc<{ batch_code: string; card_count: number }>('generate_card_batch', { p_community_id: community.community_id });
      changed(`${r.batch_code} gerado para ${community.community}: ${r.card_count} cartões. Descarregue o PDF para imprimir.`);
    } catch (err) { setError(errorMessage(err)); }
    finally { setBusy(null); }
  }

  const term = filter.trim().toLowerCase();
  const visible = (communities ?? []).filter((c) => !term
    || [c.community, c.locality, c.administrative_post, c.district, c.province].some((v) => (v ?? '').toLowerCase().includes(term)));

  return (
    <main className="admin-shell">
      <AdminHeader />
      <div className="admin-page-heading"><div><div className="eyebrow">MEAL · DISTRIBUIÇÃO</div><h1>Cartões e distribuição</h1><p>Gerir lotes, imprimir cartões e acompanhar a entrega aos agregados familiares.</p></div></div>
      <div className="info-box">
        Gere um lote por bairro, descarregue o PDF (folhas A4 com 10 cartões) e imprima. No dia da distribuição, marque quem está
        presente (ou leia o QR do cartão): só esses cartões ficam ativos, com {formatMT(6000)}. Um cartão que não chegou ao agregado
        nunca tem saldo.
      </div>

      {notice && <div className="success-box">{notice}</div>}
      {error && <div className="error-box">{error}</div>}

      <h2>Gerar lote por bairro</h2>
      <input className="input admin-search" placeholder="Procurar bairro, localidade ou distrito"
        value={filter} onChange={(e) => setFilter(e.target.value)} />
      {communities === null && !error && <p>A carregar…</p>}
      {communities && visible.length === 0 && <p className="muted-text">Nenhum bairro com agregados registados.</p>}
      {visible.length > 0 && (
        <table className="import-errors">
          <thead><tr><th>Bairro</th><th>Localidade · Distrito</th><th>Agregados</th><th>Sem cartão</th><th></th></tr></thead>
          <tbody>
            {visible.map((c) => (
              <tr key={c.community_id}>
                <td><strong>{c.community}</strong></td>
                <td>{c.locality ?? '—'} · {c.district}</td>
                <td>{c.households}</td>
                <td>{c.households_without_card}</td>
                <td>
                  <button className="quick-link" onClick={() => generate(c)} disabled={busy !== null || c.households_without_card === 0}>
                    {busy === c.community_id ? 'A gerar…' : c.households_without_card > 0 ? `Gerar lote (${c.households_without_card})` : 'Todos com cartão'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <h2>Lotes</h2>
      {batches.length === 0 && <p className="muted-text">Ainda não há lotes.</p>}
      {batches.map((b) => <BatchRow key={b.batch_id} batch={b} canCancel={isManager} onChanged={changed} />)}

      <ReplaceCard canReplace={isManager} />
    </main>
  );
}

export default function AdminCardsPage() {
  return (
    <AdminGate roles={HOUSEHOLD_ROLES} deniedCode="CARD_BATCH_NOT_ALLOWED">
      <CardsScreen />
    </AdminGate>
  );
}
