import { Head, useForm, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/components/admin-layout';
import { CatalogueEditor } from '@/components/catalogue-editor';
import { useState, type FormEvent } from 'react';
import type { SharedProps } from '@/types';

type Status = 'ACTIVE' | 'INACTIVE';
type District = { id: string; name: string; province: string };
type Pos = { id: string; pos_code: string; device_label: string | null; status: Status; last_seen_at: string | null };
type Agrodealer = {
    id: string; code: string; name: string; status: Status;
    district_id: string | null; district: string | null; province: string | null;
    address: string | null; contact_phone: string | null; users: number; catalogue_count: number; pos: Pos[];
};
type Props = { agrodealers: Agrodealer[]; districts: District[]; canManage: boolean; canManageCatalogue: boolean };

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });
}

// Formulários iguais aos do sistema Next.js (app/admin/agrodealers/page.tsx).
function AgrodealerForm({ agrodealer, districts, onDone }: { agrodealer: Agrodealer | null; districts: District[]; onDone: () => void }) {
    const form = useForm({
        name: agrodealer?.name ?? '',
        district_id: agrodealer?.district_id ?? '',
        address: agrodealer?.address ?? '',
        contact_phone: agrodealer?.contact_phone ?? '',
        status: (agrodealer?.status ?? 'ACTIVE') as Status,
    });
    const movesDistrict = agrodealer !== null && agrodealer.district_id !== null && form.data.district_id !== agrodealer.district_id;

    function save(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: onDone, preserveScroll: true };
        if (agrodealer) {
            form.put(`/admin/comerciantes/${agrodealer.id}`, options);
        } else {
            form.transform(({ status: _status, ...data }) => data);
            form.post('/admin/comerciantes', options);
        }
    }

    const error = form.errors.name ?? form.errors.district_id ?? form.errors.address ?? form.errors.contact_phone ?? form.errors.status;

    return (
        <form className="admin-form" onSubmit={save}>
            <strong>{agrodealer ? `Editar ${agrodealer.name}` : 'Novo comerciante'}</strong>
            <div className="form-grid-2">
                <div><span className="form-label">Código</span>
                    <div className="readonly-field">{agrodealer?.code ?? 'Gerado ao guardar (ex.: MER-MUT-001)'}</div></div>
                <div><label className="form-label" htmlFor="agd-name">Nome</label>
                    <input id="agd-name" className="input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></div>
                <div><label className="form-label" htmlFor="agd-district">Distrito</label>
                    <select id="agd-district" className="input" value={form.data.district_id} onChange={(e) => form.setData('district_id', e.target.value)}>
                        <option value="">Escolha o distrito…</option>
                        {districts.map((d) => <option key={d.id} value={d.id}>{d.name} ({d.province})</option>)}
                    </select></div>
                <div><label className="form-label" htmlFor="agd-phone">Telefone</label>
                    <input id="agd-phone" className="input" value={form.data.contact_phone} onChange={(e) => form.setData('contact_phone', e.target.value)} /></div>
            </div>
            <label className="form-label" htmlFor="agd-address">Endereço</label>
            <input id="agd-address" className="input" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
            {agrodealer && (
                <>
                    <label className="form-label" htmlFor="agd-status">Situação</label>
                    <select id="agd-status" className="input" value={form.data.status} onChange={(e) => form.setData('status', e.target.value as Status)}>
                        <option value="ACTIVE">Ativo</option>
                        <option value="INACTIVE">Inativo (os POS deixam de vender)</option>
                    </select>
                </>
            )}
            {movesDistrict && <div className="warning-box">Ao mudar de distrito, o comerciante passa a vender aos preços do novo distrito.</div>}
            {error && <div className="error-box">{error}</div>}
            <div className="admin-form-actions">
                <button type="button" className="secondary-button" onClick={onDone} disabled={form.processing}>CANCELAR</button>
                <button type="submit" className="primary-button" disabled={form.processing || !form.data.district_id}>{form.processing ? 'A GUARDAR…' : 'GUARDAR COMERCIANTE'}</button>
            </div>
        </form>
    );
}

function PosForm({ agrodealerId, pos, onDone }: { agrodealerId: string; pos: Pos | null; onDone: () => void }) {
    const form = useForm({ device_label: pos?.device_label ?? '', status: (pos?.status ?? 'ACTIVE') as Status });

    function save(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: onDone, preserveScroll: true };
        if (pos) {
            form.put(`/admin/comerciantes/${agrodealerId}/pos/${pos.id}`, options);
        } else {
            form.transform(({ device_label }) => ({ device_label }));
            form.post(`/admin/comerciantes/${agrodealerId}/pos`, options);
        }
    }

    const error = form.errors.device_label ?? form.errors.status;

    return (
        <form className="admin-form nested-form" onSubmit={save}>
            <div className="form-grid-2">
                <div><span className="form-label">Código do POS</span>
                    <div className="readonly-field">{pos?.pos_code ?? 'Gerado ao guardar (ex.: POS-MUT-001-01)'}</div></div>
                <div><label className="form-label" htmlFor={`pos-label-${agrodealerId}`}>Aparelho</label>
                    <input id={`pos-label-${agrodealerId}`} className="input" value={form.data.device_label} onChange={(e) => form.setData('device_label', e.target.value)} placeholder="Ex.: Tablet balcão" /></div>
            </div>
            {pos && (
                <select className="input" value={form.data.status} onChange={(e) => form.setData('status', e.target.value as Status)} aria-label="Situação do POS">
                    <option value="ACTIVE">Ativo</option>
                    <option value="INACTIVE">Inativo (deixa de poder vender)</option>
                </select>
            )}
            {error && <div className="error-box">{error}</div>}
            <div className="admin-form-actions">
                <button type="button" className="secondary-button" onClick={onDone} disabled={form.processing}>CANCELAR</button>
                <button type="submit" className="primary-button" disabled={form.processing}>GUARDAR POS</button>
            </div>
        </form>
    );
}

function AgrodealerCard({ agrodealer, districts, canManage, canManageCatalogue }: { agrodealer: Agrodealer; districts: District[]; canManage: boolean; canManageCatalogue: boolean }) {
    const [panel, setPanel] = useState<'none' | 'edit' | 'catalogue' | 'newPos' | Pos>('none');
    const close = () => setPanel('none');

    return (
        <div className={`admin-card agrodealer-card${agrodealer.status === 'INACTIVE' ? ' inactive' : ''}`}>
            <div className="row-between">
                <div>
                    <strong>{agrodealer.name}</strong>
                    <div className="muted-text">{agrodealer.code} · {agrodealer.district ? `${agrodealer.district} (${agrodealer.province})` : 'sem distrito'}</div>
                    {(agrodealer.address || agrodealer.contact_phone) && (
                        <div className="muted-text small-note">{[agrodealer.address, agrodealer.contact_phone].filter(Boolean).join(' · ')}</div>
                    )}
                </div>
                <span className={`badge ${agrodealer.status === 'ACTIVE' ? 'badge-active' : ''}`}>{agrodealer.status === 'ACTIVE' ? 'Ativo' : 'Inativo'}</span>
            </div>
            {!agrodealer.district_id && <div className="warning-box">Sem distrito: não consegue vender. Edite e escolha o distrito.</div>}
            <div className="muted-text small-note">{agrodealer.catalogue_count} produto(s) no catálogo · {agrodealer.users} utilizador(es){agrodealer.pos.length > 1 ? ` · ${agrodealer.pos.length} terminais` : ''}</div>

            {/* Um só terminal (o normal: o dono vende do telemóvel) fica numa linha discreta. */}
            {agrodealer.pos.length === 1 && agrodealer.pos[0].status === 'ACTIVE' ? (
                <div className="terminal-line">Terminal {agrodealer.pos[0].pos_code}{agrodealer.pos[0].last_seen_at ? ` · visto ${formatDateTime(agrodealer.pos[0].last_seen_at)}` : ' · ainda sem vendas'}
                    {canManage && <> · <button className="quick-link small-link" onClick={() => setPanel(agrodealer.pos[0])}>Editar</button></>}</div>
            ) : <div className="pos-list">
                {agrodealer.pos.map((p) => (
                    <div className="pos-row" key={p.id}>
                        <span><strong>{p.pos_code}</strong>{p.device_label ? ` · ${p.device_label}` : ''}
                            <small>{p.status === 'ACTIVE' ? 'Ativo' : 'Inativo'}{p.last_seen_at ? ` · visto ${formatDateTime(p.last_seen_at)}` : ''}</small></span>
                        {canManage && <button className="quick-link small-link" onClick={() => setPanel(p)}>Editar</button>}
                    </div>
                ))}
            </div>}
            {agrodealer.status === 'ACTIVE' && agrodealer.catalogue_count === 0 && <div className="warning-box">Sem produtos no catálogo: não consegue vender. Abra o catálogo e junte o que vem da cadeia de abastecimento.</div>}
            {agrodealer.status === 'ACTIVE' && agrodealer.users === 0 && <div className="warning-box">Sem utilizador: crie a conta do gestor em Utilizadores.</div>}

            <div className="admin-card-actions">
                <button className="quick-link" onClick={() => setPanel(panel === 'catalogue' ? 'none' : 'catalogue')}>Catálogo →</button>
                {canManage && <button className="quick-link" onClick={() => setPanel('newPos')}>+ Terminal</button>}
                {canManage && <button className="quick-link" onClick={() => setPanel('edit')}>Editar dados</button>}
            </div>

            {panel === 'edit' && <AgrodealerForm agrodealer={agrodealer} districts={districts} onDone={close} />}
            {panel === 'newPos' && <PosForm agrodealerId={agrodealer.id} pos={null} onDone={close} />}
            {typeof panel === 'object' && <PosForm key={panel.id} agrodealerId={agrodealer.id} pos={panel} onDone={close} />}
            {panel === 'catalogue' && <CatalogueEditor agrodealerId={agrodealer.id} canManage={canManageCatalogue} />}
        </div>
    );
}

export default function Agrodealers({ agrodealers, districts, canManage, canManageCatalogue }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [creating, setCreating] = useState(false);
    const [filter, setFilter] = useState('');
    const [districtFilter, setDistrictFilter] = useState('');

    const activeDealers = agrodealers.filter((a) => a.status === 'ACTIVE');
    const noCatalogue = activeDealers.filter((a) => a.catalogue_count === 0).length;
    const noUser = activeDealers.filter((a) => a.users === 0).length;
    const term = filter.trim().toLowerCase();
    const visible = agrodealers.filter((a) => (!districtFilter || a.district_id === districtFilter)
        && (!term || a.name.toLowerCase().includes(term) || a.code.toLowerCase().includes(term)));

    return (
        <AdminLayout>
            <Head title="Comerciantes" />
            <div className="admin-page-heading directory-heading">
                <div><div className="eyebrow">MEAL · REDE DE COMERCIANTES</div><h1>Comerciantes</h1><p>As lojas que vendem aos beneficiários, com o catálogo de cada uma.</p></div>
                {canManage && <button className="primary-button directory-primary" onClick={() => setCreating(true)}>+ Novo comerciante</button>}
            </div>
            <div className="directory-summary">
                <div className="directory-stat"><span>Comerciantes</span><strong>{agrodealers.length}</strong><small>Total registado</small></div>
                <div className="directory-stat"><span>Activos</span><strong>{activeDealers.length}</strong><small>Podem realizar vendas</small></div>
                <div className={`directory-stat${noCatalogue > 0 ? ' directory-stat-alert' : ''}`}><span>Sem catálogo</span><strong>{noCatalogue}</strong><small>Activos que ainda não vendem nada</small></div>
                <div className={`directory-stat${noUser > 0 ? ' directory-stat-alert' : ''}`}><span>Sem utilizador</span><strong>{noUser}</strong><small>Activos sem conta para entrar</small></div>
            </div>
            <div className="directory-note"><span>i</span><p>Cada comerciante pertence a um distrito e vende, aos preços do distrito no ciclo, os produtos do seu catálogo. O catálogo é registado pelo MEAL com o que vem da cadeia de abastecimento. O terminal é criado com o comerciante; a conta do gestor cria-se em Utilizadores.</p></div>
            {flash.success && <div className="success-box">{flash.success}</div>}
            {creating && <AgrodealerForm agrodealer={null} districts={districts} onDone={() => setCreating(false)} />}

            <div className="directory-toolbar">
                <div className="directory-search"><span>⌕</span><input className="input" placeholder="Pesquisar nome ou código…" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Procurar comerciante" /></div>
                <select className="input directory-district-filter" value={districtFilter} onChange={(e) => setDistrictFilter(e.target.value)} aria-label="Filtrar por distrito">
                    <option value="">Todos os distritos</option>{districts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select><span className="directory-count">{visible.length} de {agrodealers.length}</span>
            </div>
            <div className="directory-grid">
            {visible.length === 0 && <p className="muted-text">Nenhum comerciante.</p>}
            {visible.map((a) => <AgrodealerCard key={a.id} agrodealer={a} districts={districts} canManage={canManage} canManageCatalogue={canManageCatalogue} />)}
            </div>

        </AdminLayout>
    );
}
