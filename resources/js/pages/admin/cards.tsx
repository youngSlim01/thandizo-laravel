import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { QrScanner } from '@/components/qr-scanner';
import { useAction } from '@/lib/action';
import { formatDateTime, formatMT } from '@/lib/format';
import { errorText, requestJson } from '@/lib/http';
import { RELATIONSHIPS, relationshipLabel } from '@/lib/substitute';
import { cardSubtitle, useBrand } from '@/lib/brand';
import { downloadCardsPdf, type VoucherCard } from '@/lib/voucher-cards';
import type { SharedProps } from '@/types';

type Community = {
    community_id: string; community: string; locality: string | null; administrative_post: string | null;
    district: string; province: string; households: number; households_without_card: number; open_batches: number;
};

type BatchStatus = 'GENERATED' | 'PRINTED' | 'IN_DISTRIBUTION' | 'DISTRIBUTED' | 'CANCELLED';

type Batch = {
    batch_id: string; batch_code: string; status: BatchStatus; card_count: number; active_cards: number; pending_cards: number;
    created_at: string; printed_at: string | null; distributed_at: string | null; cancelled_at: string | null; cancel_reason: string | null;
    community: string; locality: string | null; district: string;
};

// Cartão como o servidor o devolve (CardService::cardJson).
type BatchCard = VoucherCard & {
    voucher_id: string; voucher_status: string; household_status: string;
    received_by_name: string | null; received_by_relationship: string | null;
    tier_name: string; tier_amount: number;
};

type HouseholdCard = VoucherCard & { voucher_status: string; current_balance: number; distributed_at: string | null };

type Props = { communities: Community[]; batches: Batch[]; canManage: boolean };

const BATCH_STATUS: Record<BatchStatus, string> = {
    GENERATED: 'Gerado', PRINTED: 'Impresso', IN_DISTRIBUTION: 'Em distribuição', DISTRIBUTED: 'Distribuído', CANCELLED: 'Cancelado',
};

const VOUCHER_STATUS: Record<string, string> = {
    ACTIVE: 'Ativo', PENDING: 'Por distribuir', REPLACED: 'Substituído', CANCELLED: 'Cancelado', INVALIDATED: 'Invalidado',
};

function batchCards(batchId: string) {
    return requestJson<{ batch_code: string; cards: BatchCard[] }>('GET', `/admin/cards/batches/${batchId}/cards`);
}

// Dia da distribuição: o MEAL marca os agregados presentes (ou lê o QR do
// cartão) e só esses cartões ficam ativos, com o valor do escalão. Na ausência do
// representante o cartão pode ir para um substituto: o nome e o parentesco
// ficam no cartão; os dados do agregado não mudam.
type Substitute = { name: string; relationship: string };

function DistributePanel({ batch, onClose }: { batch: Batch; onClose: () => void }) {
    const [cards, setCards] = useState<BatchCard[] | null>(null);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [subs, setSubs] = useState<Record<string, Substitute>>({});
    const [filter, setFilter] = useState('');
    const [scanning, setScanning] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const action = useAction();

    const load = useCallback(() => {
        batchCards(batch.batch_id).then((r) => setCards(r.cards)).catch((err) => setLoadError(errorText(err)));
    }, [batch.batch_id]);
    useEffect(() => { load(); }, [load]);

    const deliverable = (c: BatchCard) => c.voucher_status === 'PENDING' && c.household_status === 'ACTIVE';
    const pending = (cards ?? []).filter(deliverable);
    const delivered = (cards ?? []).filter((c) => c.voucher_status === 'ACTIVE').length;
    const selectedTotal = (cards ?? []).filter((c) => selected.has(c.voucher_id)).reduce((sum, c) => sum + Number(c.tier_amount), 0);

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
        setConfirming(false);
        setSubs((m) => { const n = { ...m }; if (sub) n[id] = sub; else delete n[id]; return n; });
    }

    const subList = Object.entries(subs).filter(([id]) => selected.has(id));
    const subsIncomplete = subList.some(([, v]) => !v.name.trim() || !v.relationship);

    const scanned = useCallback((text: string) => {
        setScanning(false);
        const code = text.trim().toUpperCase();
        const card = (cards ?? []).find((c) => c.voucher_code === code);
        action.setError(null);
        if (!card) { action.setError(`O cartão ${code.slice(0, 40)} não pertence a este lote.`); setNotice(null); return; }
        if (card.voucher_status === 'ACTIVE') { setNotice(`${card.representative_name} já recebeu o cartão.`); return; }
        if (!deliverable(card)) {
            action.setError(`O cartão de ${card.representative_name} não pode ser entregue (${card.household_status !== 'ACTIVE' ? 'agregado inativo' : 'cartão cancelado'}).`);
            return;
        }
        setSelected((s) => new Set(s).add(card.voucher_id));
        setNotice(`${card.representative_name} (${card.household_code}) marcado como presente.`);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cards]);

    function activate() {
        action.run('post', `/admin/cards/batches/${batch.batch_id}/distribution`, {
            voucher_ids: Array.from(selected),
            substitutes: subList.map(([id, v]) => ({ voucher_id: id, name: v.name.trim(), relationship: v.relationship })),
        }, () => { setSelected(new Set()); setSubs({}); setConfirming(false); setNotice(null); load(); });
    }

    const error = loadError ?? action.error;

    return (
        <div className="admin-form distribute-panel">
            <div className="row-between">
                <strong>Distribuir cartões — {batch.batch_code}</strong>
                <button className="quick-link" onClick={onClose}>Fechar</button>
            </div>
            <p className="muted-text">
                Marque os agregados presentes, ou leia o QR do cartão. Só os cartões marcados ficam ativos, cada um com o valor do escalão do seu agregado.
                {cards && ` ${delivered} de ${cards.length} já entregues.`}
            </p>

            <div className="distribute-tools">
                <input className="input" placeholder="Procurar nome, CODIGO AF ou código" value={filter} onChange={(e) => setFilter(e.target.value)}
                    aria-label="Procurar no lote" />
                <button className="secondary-button" type="button" onClick={() => { action.setError(null); setScanning(true); }}>▣ LER QR</button>
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
                                <span className="distribute-who"><strong>{c.representative_name}</strong><small>{c.household_code} · {c.voucher_code}{can && ` · ${c.tier_name} ${formatMT(c.tier_amount)}`}</small></span>
                                {label && <span className="muted-text small-note">{label}</span>}
                            </label>
                            {can && isSelected && (
                                <div className="distribute-sub">
                                    <label className="distribute-sub-toggle">
                                        <input type="checkbox" checked={!!sub} onChange={(e) => setSub(c.voucher_id, e.target.checked ? { name: '', relationship: '' } : null)}
                                            aria-label={`Entregue a substituto: ${c.representative_name}`} />
                                        Representante ausente — entregue a substituto
                                    </label>
                                    {sub && (
                                        <div className="distribute-sub-fields">
                                            <input className="input" placeholder="Nome de quem recebe" value={sub.name}
                                                aria-label={`Nome do substituto de ${c.representative_name}`}
                                                onChange={(e) => setSub(c.voucher_id, { ...sub, name: e.target.value })} />
                                            <select className="input" value={sub.relationship} aria-label={`Parentesco do substituto de ${c.representative_name}`}
                                                onChange={(e) => setSub(c.voucher_id, { ...sub, relationship: e.target.value })}>
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
                        <strong>{formatMT(selectedTotal)}</strong>, e não pode ser desfeito.
                        {subList.length > 0 && <> {subList.length === 1 ? '1 cartão é entregue' : `${subList.length} cartões são entregues`} a substituto; o agregado continua registado com o representante.</>}
                    </div>
                    <div className="admin-form-actions">
                        <button className="secondary-button" onClick={() => setConfirming(false)} disabled={action.busy}>VOLTAR</button>
                        <button className="primary-button" onClick={activate} disabled={action.busy}>{action.busy ? 'A ATIVAR…' : 'CONFIRMAR ENTREGA'}</button>
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

function CardPreview({ card, busy, onPrint, onClose }: { card: VoucherCard; busy: boolean; onPrint: () => void; onClose: () => void }) {
    const brand = useBrand();
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="card-preview-overlay" onClick={onClose}>
            <div className="card-preview-modal" role="dialog" aria-modal="true" aria-label={`Cartão de ${card.representative_name}`} onClick={(e) => e.stopPropagation()}>
                <div className="card-preview-modal-head">
                    <strong>Cartão de {card.representative_name}</strong>
                    <button type="button" className="card-preview-close" onClick={onClose} aria-label="Fechar">✕</button>
                </div>
                <div className="voucher-card-preview">
                    <div className="voucher-card-preview-head">
                        <div className="voucher-card-preview-logos">
                            {brand.logos.map((src) => <img key={src} src={src} alt="" />)}
                            <div><strong>Thandizo</strong><small>{cardSubtitle(brand).toUpperCase()}</small></div>
                        </div>
                        <span>e-VOUCHER</span>
                    </div>
                    <div className="voucher-card-preview-body">
                        <div className="voucher-card-preview-info">
                            <small>REPRESENTANTE</small><strong>{card.representative_name}</strong>
                            <small>CÓDIGO DO AF</small><strong>{card.household_code}</strong>
                            <span>{[card.community, card.locality].filter(Boolean).join(', ') || '—'}</span>
                            <span>{[card.district, card.province].filter(Boolean).join(' · ') || '—'}</span>
                            <b>{card.voucher_code}</b>
                        </div>
                        <div className="voucher-card-preview-qr"><span>QR</span><small>{card.voucher_code}</small></div>
                    </div>
                    <div className="voucher-card-preview-foot">Válido apenas para o agregado indicado</div>
                </div>
                <div className="batch-actions">
                    <button className="quick-link" onClick={onPrint} disabled={busy}>Imprimir cartão seleccionado (PDF)</button>
                    <button className="quick-link" onClick={onClose}>Fechar</button>
                </div>
                <p className="muted-text small-note">A pré-visualização é ilustrativa. O PDF mantém o desenho oficial do cartão e inclui o QR real.</p>
            </div>
        </div>
    );
}

function BatchRow({ batch, canManage }: { batch: Batch; canManage: boolean }) {
    const brand = useBrand();
    const [confirming, setConfirming] = useState<'distribute' | 'cancel' | null>(null);
    const [reason, setReason] = useState('');
    const [expanded, setExpanded] = useState(false);
    const [cards, setCards] = useState<BatchCard[] | null>(null);
    const [selectedCard, setSelectedCard] = useState<VoucherCard | null>(null);
    const [working, setWorking] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const action = useAction();
    const busy = working || action.busy;
    const closing = batch.status === 'IN_DISTRIBUTION';

    async function work(task: () => Promise<void>) {
        setWorking(true); setLocalError(null);
        try { await task(); } catch (err) { setLocalError(errorText(err)); } finally { setWorking(false); }
    }

    function toggleCards() {
        if (expanded) { setExpanded(false); setSelectedCard(null); return; }
        setExpanded(true);
        if (!cards) void work(async () => setCards((await batchCards(batch.batch_id)).cards));
    }

    const pdf = () => work(async () => {
        const data = await batchCards(batch.batch_id);
        await downloadCardsPdf(data.cards, data.batch_code, cardSubtitle(brand), brand.logos);
    });

    function cancel(event: FormEvent) {
        event.preventDefault();
        action.run('post', `/admin/cards/batches/${batch.batch_id}/cancellation`, { reason }, () => setConfirming(null));
    }

    const error = localError ?? action.error;

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
                <button className="quick-link" onClick={toggleCards} disabled={busy}>{expanded ? 'Ocultar cartões' : 'Ver cartões do lote'}</button>
                {batch.status !== 'CANCELLED' && confirming === null && (
                    <>
                        <button className="quick-link" onClick={pdf} disabled={busy}>Imprimir lote completo (PDF)</button>
                        {batch.status === 'GENERATED' && (
                            <button className="quick-link" onClick={() => action.run('put', `/admin/cards/batches/${batch.batch_id}/printed`)} disabled={busy}>Marcar impresso</button>
                        )}
                        {(batch.status === 'PRINTED' || closing) && (
                            <button className="quick-link" onClick={() => setConfirming('distribute')} disabled={busy}>
                                Distribuir cartões{closing ? ` (faltam ${batch.pending_cards})` : ''}
                            </button>
                        )}
                        {canManage && (batch.status === 'GENERATED' || batch.status === 'PRINTED') && (
                            <button className="quick-link danger" onClick={() => setConfirming('cancel')} disabled={busy}>Cancelar lote</button>
                        )}
                        {canManage && closing && (
                            <button className="quick-link danger" onClick={() => setConfirming('cancel')} disabled={busy}>Fechar lote</button>
                        )}
                    </>
                )}
            </div>

            {expanded && (
                <div className="card-batch-cards">
                    {cards === null && working && <p className="muted-text">A carregar cartões…</p>}
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
                        <CardPreview card={selectedCard} busy={busy} onClose={() => setSelectedCard(null)}
                            onPrint={() => work(() => downloadCardsPdf([selectedCard], selectedCard.voucher_code, cardSubtitle(brand), brand.logos))} />
                    )}
                </div>
            )}

            {confirming === 'distribute' && <DistributePanel batch={batch} onClose={() => setConfirming(null)} />}

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

type Replaced = { old_voucher_code: string; new_voucher_code: string; transferred: number; card: VoucherCard };

function ReplaceCard({ canManage }: { canManage: boolean }) {
    const brand = useBrand();
    const [query, setQuery] = useState('');
    const [cards, setCards] = useState<HouseholdCard[] | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [replaced, setReplaced] = useState<Replaced | null>(null);

    const find = (q: string) => requestJson<HouseholdCard[]>('GET', `/admin/cards/search?q=${encodeURIComponent(q)}`);

    async function search(event: FormEvent) {
        event.preventDefault();
        setError(null); setReplaced(null); setBusy(true);
        try { setCards(await find(query)); } catch (err) { setError(errorText(err)); } finally { setBusy(false); }
    }

    async function replace(card: HouseholdCard) {
        setError(null); setBusy(true);
        try {
            setReplaced(await requestJson<Replaced>('POST', `/admin/cards/${encodeURIComponent(card.voucher_code)}/replacement`, { reason }));
            setReason('');
            setCards(await find(card.household_code));
        } catch (err) { setError(errorText(err)); } finally { setBusy(false); }
    }

    return (
        <>
            <h2>Cartão perdido</h2>
            <p className="muted-text">O cartão perdido é cancelado e é emitido um novo, com o saldo que restava. O cartão antigo deixa de funcionar no POS.</p>
            <form className="replace-search" onSubmit={search}>
                <input className="input" value={query} onChange={(e) => setQuery(e.target.value.toUpperCase())}
                    placeholder="Código do voucher ou CODIGO AF" aria-label="Código do voucher ou CODIGO AF" />
                <button className="secondary-button" type="submit" disabled={busy || !query.trim()}>PROCURAR</button>
            </form>

            {replaced && (
                <div className="success-box">
                    Cartão {replaced.old_voucher_code} substituído por <strong>{replaced.new_voucher_code}</strong> com {formatMT(replaced.transferred)}.
                    <button className="secondary-button link-button" onClick={() => downloadCardsPdf([replaced.card], replaced.new_voucher_code, cardSubtitle(brand), brand.logos)}>
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
                    {card.voucher_status === 'ACTIVE' && canManage && (
                        <div className="admin-form">
                            <label className="form-label" htmlFor={`reason-${card.voucher_code}`}>Motivo</label>
                            <input id={`reason-${card.voucher_code}`} className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex.: cartão perdido" />
                            <button className="danger-button" onClick={() => replace(card)} disabled={busy || !reason.trim()}>CANCELAR E EMITIR NOVO CARTÃO</button>
                        </div>
                    )}
                    {card.voucher_status === 'ACTIVE' && !canManage && (
                        <div className="muted-text small-note">Só um gestor MEAL ou administrador pode substituir cartões.</div>
                    )}
                </div>
            ))}
        </>
    );
}

export default function Cards({ communities, batches, canManage }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [filter, setFilter] = useState('');
    const [generating, setGenerating] = useState<string | null>(null);
    const action = useAction();

    function generate(community: Community) {
        setGenerating(community.community_id);
        action.run('post', '/admin/cards/batches', { community_id: community.community_id }, () => setGenerating(null));
    }

    const term = filter.trim().toLowerCase();
    const visible = communities.filter((c) => !term
        || [c.community, c.locality, c.administrative_post, c.district, c.province].some((v) => (v ?? '').toLowerCase().includes(term)));

    return (
        <AdminLayout>
            <Head title="Cartões e distribuição" />
            <div className="admin-page-heading"><div><div className="eyebrow">MEAL · DISTRIBUIÇÃO</div><h1>Cartões e distribuição</h1><p>Gerir lotes, imprimir cartões e acompanhar a entrega aos agregados familiares.</p></div></div>
            <div className="info-box">
                Gere um lote por bairro, descarregue o PDF (folhas A4 com 10 cartões) e imprima. No dia da distribuição, marque quem está
                presente (ou leia o QR do cartão): só esses cartões ficam ativos, com o valor do escalão de cada agregado. Um cartão que não chegou ao agregado
                nunca tem saldo.
            </div>

            {flash.success && <div className="success-box">{flash.success}</div>}
            {action.error && <div className="error-box">{action.error}</div>}

            <h2>Gerar lote por bairro</h2>
            <input className="input admin-search" placeholder="Procurar bairro, localidade ou distrito" value={filter} onChange={(e) => setFilter(e.target.value)} />
            {visible.length === 0 && <p className="muted-text">Nenhum bairro com agregados registados.</p>}
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
                                    <button className="quick-link" onClick={() => generate(c)} disabled={action.busy || c.households_without_card === 0}>
                                        {action.busy && generating === c.community_id ? 'A gerar…' : c.households_without_card > 0 ? `Gerar lote (${c.households_without_card})` : 'Todos com cartão'}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            <h2>Lotes</h2>
            {batches.length === 0 && <p className="muted-text">Ainda não há lotes.</p>}
            {batches.map((b) => <BatchRow key={b.batch_id} batch={b} canManage={canManage} />)}

            <ReplaceCard canManage={canManage} />
        </AdminLayout>
    );
}
