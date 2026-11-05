import { FormEvent, useEffect, useState } from 'react';
import { errorText, requestJson } from '@/lib/http';
import { formatDateTime } from '@/lib/format';
import { GeoFilter, type GeoValue, useGeography } from '@/components/geo-filter';

// Registar, alterar e eliminar agregados (gestor MEAL / administrador). O
// Laravel valida com as mesmas regras da importação e audita cada alteração
// com o valor antigo, o novo e o motivo. O CODIGO AF não se altera.

export type EditableHousehold = {
  household_id: string; household_code: string;
  first_name: string | null; last_name: string | null; gender: string | null; birth_date: string | null;
  relationship: string | null; community_id: string | null; locality_id: string | null; administrative_post_id: string | null;
  district_id: string | null; province_id: string | null; has_cards: boolean;
};

type Fields = { first_name: string; last_name: string; sex: string; birth_date: string; relationship: string };

const FIELD_LABEL: Record<string, string> = {
  representative_name: 'Nome completo', representative_first_name: 'Nome', representative_last_name: 'Apelido',
  representative_sex: 'Género', representative_birth_date: 'Data de nascimento', representative_relationship: 'Grau de parentesco',
  community_id: 'Bairro', locality_id: 'Localidade', administrative_post_id: 'Posto', district_id: 'Distrito', province_id: 'Província',
};
const ACTION_LABEL: Record<string, string> = {
  HOUSEHOLD_CREATED: 'Registado', HOUSEHOLD_UPDATED: 'Dados alterados',
  HOUSEHOLD_DEACTIVATED: 'Desativado', HOUSEHOLD_REACTIVATED: 'Reativado',
};

function PersonFields({ value, onChange, idPrefix }: { value: Fields; onChange: (v: Fields) => void; idPrefix: string }) {
  const set = (k: keyof Fields) => (e: { target: { value: string } }) => onChange({ ...value, [k]: e.target.value });
  return (
    <div className="form-grid-2">
      <div><label className="form-label" htmlFor={`${idPrefix}-first`}>Nome</label>
        <input id={`${idPrefix}-first`} className="input" value={value.first_name} onChange={set('first_name')} /></div>
      <div><label className="form-label" htmlFor={`${idPrefix}-last`}>Apelido</label>
        <input id={`${idPrefix}-last`} className="input" value={value.last_name} onChange={set('last_name')} /></div>
      <div><label className="form-label" htmlFor={`${idPrefix}-sex`}>Género</label>
        <select id={`${idPrefix}-sex`} className="input" value={value.sex} onChange={set('sex')}>
          <option value="">Escolha…</option><option value="F">Feminino</option><option value="M">Masculino</option>
        </select></div>
      <div><label className="form-label" htmlFor={`${idPrefix}-birth`}>Data de nascimento</label>
        <input id={`${idPrefix}-birth`} className="input" type="date" value={value.birth_date} onChange={set('birth_date')} /></div>
      <div><label className="form-label" htmlFor={`${idPrefix}-rel`}>Grau de parentesco</label>
        <input id={`${idPrefix}-rel`} className="input" value={value.relationship} onChange={set('relationship')} placeholder="Ex.: Chefe" /></div>
    </div>
  );
}

export function EditHousehold({ household, onSaved, onCancel }: { household: EditableHousehold; onSaved: (m: string) => void; onCancel: () => void }) {
  const geo = useGeography();
  const initial: Fields = {
    first_name: household.first_name ?? '', last_name: household.last_name ?? '', sex: household.gender ?? '',
    birth_date: household.birth_date ?? '', relationship: household.relationship ?? '',
  };
  const [fields, setFields] = useState<Fields>(initial);
  const [place, setPlace] = useState<GeoValue>({
    province_id: household.province_id ?? undefined, district_id: household.district_id ?? undefined,
    administrative_post_id: household.administrative_post_id ?? undefined, locality_id: household.locality_id ?? undefined,
    community_id: household.community_id ?? undefined,
  });
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Only the fields that changed are sent.
  const changes: Record<string, string> = {};
  (Object.keys(fields) as (keyof Fields)[]).forEach((k) => { if (fields[k] !== initial[k]) changes[k] = fields[k]; });
  if (place.community_id && place.community_id !== household.community_id) changes.community_id = place.community_id;
  const moving = 'community_id' in changes;
  const nothing = Object.keys(changes).length === 0;

  async function save(event: FormEvent) {
    event.preventDefault();
    setBusy(true); setError(null);
    try {
      const r = await requestJson<{ changed: boolean }>('PATCH', `/admin/households/${household.household_id}`, { changes, reason });
      onSaved(r.changed ? `Dados de ${household.household_code} alterados.` : 'Nada mudou.');
    } catch (err) { setError(errorText(err)); }
    finally { setBusy(false); }
  }

  return (
    <form className="admin-form household-edit" onSubmit={save}>
      <strong>Editar dados de {household.household_code}</strong>
      <PersonFields value={fields} onChange={setFields} idPrefix="edit" />
      <div className="form-label">Bairro / povoação</div>
      <GeoFilter geo={geo} value={place} onChange={setPlace} hideEmpty={false} idPrefix="edit-geo" />
      {moving && <div className="warning-box">O agregado muda de bairro{household.has_cards ? '. O cartão continua a funcionar e passa a comprar aos preços do novo distrito; um cartão já impresso mostra o bairro antigo' : ''}.</div>}
      {!place.community_id && <div className="muted-text small-note">Escolha até ao bairro.</div>}
      <label className="form-label" htmlFor="edit-reason">Motivo da alteração</label>
      <input id="edit-reason" className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex.: nome errado na lista do MEAL" />
      <div className="muted-text small-note">O CODIGO AF não se altera. Cada alteração fica no histórico com o valor antigo e o novo.</div>
      {error && <div className="error-box">{error}</div>}
      <div className="admin-form-actions">
        <button type="button" className="secondary-button" onClick={onCancel} disabled={busy}>CANCELAR</button>
        <button type="submit" className="primary-button" disabled={busy || nothing || !reason.trim() || !place.community_id}>{busy ? 'A GUARDAR…' : 'GUARDAR ALTERAÇÕES'}</button>
      </div>
    </form>
  );
}

export function NewHousehold({ onSaved, onCancel }: { onSaved: (m: string, id: string) => void; onCancel: () => void }) {
  const geo = useGeography();
  const [code, setCode] = useState('');
  const [fields, setFields] = useState<Fields>({ first_name: '', last_name: '', sex: '', birth_date: '', relationship: 'Chefe' });
  const [place, setPlace] = useState<GeoValue>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save(event: FormEvent) {
    event.preventDefault();
    setBusy(true); setError(null);
    try {
      const r = await requestJson<{ household_id: string; household_code: string }>('POST', '/admin/households', {
        household_code: code, ...fields, community_id: place.community_id,
      });
      onSaved(`Agregado ${r.household_code} registado.`, r.household_id);
    } catch (err) { setError(errorText(err)); }
    finally { setBusy(false); }
  }

  return (
    <form className="admin-form household-edit" onSubmit={save}>
      <strong>Novo agregado familiar</strong>
      <label className="form-label" htmlFor="new-code">CODIGO AF</label>
      <input id="new-code" className="input" value={code} onChange={(e) => setCode(e.target.value)} placeholder="Ex.: 1234AF000001" />
      <PersonFields value={fields} onChange={setFields} idPrefix="new" />
      <div className="form-label">Bairro / povoação</div>
      <GeoFilter geo={geo} value={place} onChange={setPlace} hideEmpty={false} idPrefix="new-geo" />
      <div className="muted-text small-note">Para muitos agregados use a importação de Excel. Um bairro novo entra pela importação.</div>
      {error && <div className="error-box">{error}</div>}
      <div className="admin-form-actions">
        <button type="button" className="secondary-button" onClick={onCancel} disabled={busy}>CANCELAR</button>
        <button type="submit" className="primary-button" disabled={busy || !place.community_id || !code.trim()}>{busy ? 'A GUARDAR…' : 'REGISTAR AGREGADO'}</button>
      </div>
    </form>
  );
}

export function DeleteHousehold({ household, onDeleted, onCancel }: { household: EditableHousehold; onDeleted: (m: string) => void; onCancel: () => void }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function remove(event: FormEvent) {
    event.preventDefault();
    setBusy(true); setError(null);
    try {
      await requestJson('DELETE', `/admin/households/${household.household_id}`, { reason });
      onDeleted(`Agregado ${household.household_code} eliminado.`);
    } catch (err) { setError(errorText(err)); }
    finally { setBusy(false); }
  }

  return (
    <form className="admin-form" onSubmit={remove}>
      <div className="warning-box">
        Eliminar só serve para um registo feito por engano (por exemplo duplicado na importação). Fica uma cópia completa na auditoria.
        Se o agregado existe mas saiu do programa, use <strong>Desativar agregado</strong>.
      </div>
      <label className="form-label" htmlFor="delete-reason">Motivo</label>
      <input id="delete-reason" className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex.: registado duas vezes" />
      {error && <div className="error-box">{error}</div>}
      <div className="admin-form-actions">
        <button type="button" className="secondary-button" onClick={onCancel} disabled={busy}>CANCELAR</button>
        <button type="submit" className="danger-button" disabled={busy || !reason.trim()}>ELIMINAR {household.household_code}</button>
      </div>
    </form>
  );
}

type HistoryEntry = { action: string; at: string; by: string | null; reason: string | null; changes: Record<string, { old: unknown; new: unknown }> | null };

export function HouseholdHistory({ householdId, refresh }: { householdId: string; refresh: number }) {
  const [entries, setEntries] = useState<HistoryEntry[] | null>(null);
  useEffect(() => {
    requestJson<HistoryEntry[]>('GET', `/admin/households/${householdId}/history`).then(setEntries).catch(() => setEntries([]));
  }, [householdId, refresh]);
  const show = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : String(v));

  if (!entries) return <p className="muted-text">A carregar…</p>;
  if (entries.length === 0) return <p className="muted-text">Sem alterações registadas.</p>;
  return (
    <ul className="household-history">
      {entries.map((e, i) => (
        <li key={i}>
          <div><strong>{ACTION_LABEL[e.action] ?? e.action}</strong> · {formatDateTime(e.at)}{e.by ? ` · ${e.by}` : ''}</div>
          {e.reason && <div className="muted-text">Motivo: {e.reason}</div>}
          {e.changes && (
            <ul>
              {Object.entries(e.changes).filter(([k]) => !k.endsWith('_id') || k === 'community_id').map(([k, c]) => (
                <li key={k}>{FIELD_LABEL[k] ?? k}: {k === 'community_id' ? 'mudou de bairro' : <><s>{show(c.old)}</s> → {show(c.new)}</>}</li>
              ))}
            </ul>
          )}
        </li>
      ))}
    </ul>
  );
}
