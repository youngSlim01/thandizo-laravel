import { Head, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { generatePassword } from '@/components/password-generator';
import type { SharedProps } from '@/types';

type Status = 'ACTIVE' | 'INACTIVE';
type AppUser = {
    id: string; email: string; username: string | null; full_name: string; role: string; role_label: string;
    agrodealer_id: string | null; agrodealer_name: string | null;
    province_id: string | null; province_name: string | null;
    status: Status; is_me: boolean; last_login_at: string | null;
};
type Role = { value: string; label: string; agrodealer: boolean; province: boolean };
type Agrodealer = { id: string; code: string; name: string; status: Status };
type Province = { id: string; name: string };
type Props = { users: AppUser[]; roles: Role[]; agrodealers: Agrodealer[]; provinces: Province[] };

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });
}

function firstError(errors: Record<string, string>): string | undefined {
    return Object.values(errors)[0];
}

// Campos de papel, agrodealer e província, partilhados pelos formulários de
// criar e editar (antigo EditUser / CreateUser do sistema Next.js).
function AccessFields({ idPrefix, data, setData, roles, agrodealers, provinces }: {
    idPrefix: string;
    data: { role: string; agrodealer_id: string; province_id: string; status: Status };
    setData: (key: 'role' | 'agrodealer_id' | 'province_id', value: string) => void;
    roles: Role[]; agrodealers: Agrodealer[]; provinces: Province[];
}) {
    const role = roles.find((r) => r.value === data.role);
    return (
        <>
            <label className="form-label" htmlFor={`${idPrefix}-role`}>Papel</label>
            <select id={`${idPrefix}-role`} className="input" value={data.role} onChange={(e) => setData('role', e.target.value)} required>
                <option value="" disabled>Escolher papel…</option>
                {roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </select>

            {role?.agrodealer && (
                <>
                    <label className="form-label" htmlFor={`${idPrefix}-agd`}>Comerciante</label>
                    <select id={`${idPrefix}-agd`} className="input" value={data.agrodealer_id} onChange={(e) => setData('agrodealer_id', e.target.value)} required>
                        <option value="" disabled>Escolher comerciante…</option>
                        {agrodealers.map((a) => (
                            <option key={a.id} value={a.id} disabled={a.status !== 'ACTIVE' && data.status === 'ACTIVE'}>
                                {a.name} ({a.code}){a.status !== 'ACTIVE' ? ' — inativo' : ''}
                            </option>
                        ))}
                    </select>
                </>
            )}

            {role?.province && (
                <>
                    <label className="form-label" htmlFor={`${idPrefix}-province`}>Província</label>
                    <select id={`${idPrefix}-province`} className="input" value={data.province_id} onChange={(e) => setData('province_id', e.target.value)} required>
                        <option value="" disabled>Escolher província…</option>
                        {provinces.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                    <div className="muted-text small-note">O oficial só vê os beneficiários, cartões, comerciantes, vendas e reconciliações desta província.</div>
                </>
            )}
        </>
    );
}

// Só envia o agrodealer ou a província quando o papel os usa.
function accessPayload<T extends { role: string; agrodealer_id: string; province_id: string }>(data: T, roles: Role[]) {
    const role = roles.find((r) => r.value === data.role);
    return {
        ...data,
        agrodealer_id: role?.agrodealer ? data.agrodealer_id || null : null,
        province_id: role?.province ? data.province_id || null : null,
    };
}

function EditUser({ user, onDone, ...lists }: { user: AppUser; onDone: () => void } & Omit<Props, 'users'>) {
    const form = useForm({
        full_name: user.full_name, username: user.username ?? '', role: user.role,
        agrodealer_id: user.agrodealer_id ?? '', province_id: user.province_id ?? '', status: user.status,
    });

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => accessPayload(data, lists.roles));
        form.put(`/admin/users/${user.id}`, { onSuccess: onDone, preserveScroll: true });
    }

    return (
        <form className="admin-form" onSubmit={save}>
            <label className="form-label" htmlFor={`name-${user.id}`}>Nome completo</label>
            <input id={`name-${user.id}`} className="input" value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} required />
            <label className="form-label" htmlFor={`username-${user.id}`}>Nome de utilizador (opcional)</label>
            <input id={`username-${user.id}`} className="input" value={form.data.username} onChange={(e) => form.setData('username', e.target.value.toLowerCase())}
                placeholder="Ex.: joao.operador" autoComplete="off" autoCapitalize="none" spellCheck={false} maxLength={30} />
            <div className="muted-text small-note">Para entrar sem escrever o e-mail. Letras minúsculas, números, ponto, hífen e sublinhado.</div>
            <AccessFields idPrefix={`edit-${user.id}`} data={form.data} setData={(k, v) => form.setData(k, v)} {...lists} />
            <label className="form-label" htmlFor={`status-${user.id}`}>Estado</label>
            <select id={`status-${user.id}`} className="input" value={form.data.status} onChange={(e) => form.setData('status', e.target.value as Status)}>
                <option value="ACTIVE">Ativo — pode entrar e trabalhar</option>
                <option value="INACTIVE">Inativo — sem acesso</option>
            </select>
            {firstError(form.errors) && <div className="error-box">{firstError(form.errors)}</div>}
            <div className="admin-form-actions">
                <button type="button" className="secondary-button" onClick={onDone}>CANCELAR</button>
                <button type="submit" className="primary-button" disabled={form.processing || !form.data.role}>{form.processing ? 'A GRAVAR…' : 'GRAVAR'}</button>
            </div>
        </form>
    );
}

function ResetPassword({ user, onDone }: { user: AppUser; onDone: () => void }) {
    const form = useForm({ password: generatePassword() });

    function reset(event: FormEvent) {
        event.preventDefault();
        form.put(`/admin/users/${user.id}/password`, { onSuccess: onDone, preserveScroll: true });
    }

    return (
        <form className="admin-form" onSubmit={reset}>
            <label className="form-label" htmlFor={`reset-${user.id}`}>Nova palavra-passe temporária</label>
            <div className="password-row">
                <input id={`reset-${user.id}`} className="input" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required autoComplete="off" />
                <button type="button" className="secondary-button" onClick={() => form.setData('password', generatePassword())}>GERAR OUTRA</button>
            </div>
            <div className="muted-text small-note">A palavra-passe atual deixa de funcionar. No próximo acesso a pessoa escolhe uma nova.</div>
            {form.errors.password && <div className="error-box">{form.errors.password}</div>}
            <div className="admin-form-actions">
                <button type="button" className="secondary-button" onClick={onDone}>CANCELAR</button>
                <button type="submit" className="primary-button" disabled={form.processing}>{form.processing ? 'A REPOR…' : 'REPOR PALAVRA-PASSE'}</button>
            </div>
        </form>
    );
}

// Vindo do cartão do comerciante (?novo=gestor&comerciante=…), o papel e o
// comerciante já vêm escolhidos.
function prefill(): { role: string; agrodealer_id: string } {
    if (typeof window === 'undefined') return { role: '', agrodealer_id: '' };
    const q = new URLSearchParams(window.location.search);
    return q.get('novo') === 'gestor' ? { role: 'AGRODEALER_MANAGER', agrodealer_id: q.get('comerciante') ?? '' } : { role: '', agrodealer_id: '' };
}

function CreateUser({ onDone, ...lists }: { onDone: () => void } & Omit<Props, 'users'>) {
    const form = useForm({
        email: '', username: '', full_name: '', ...prefill(), province_id: '',
        status: 'ACTIVE' as Status, password: generatePassword(),
    });

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform(({ status: _status, ...data }) => accessPayload(data, lists.roles));
        form.post('/admin/users', { onSuccess: onDone, preserveScroll: true });
    }

    return (
        <form className="card admin-form create-user" onSubmit={save}>
            <strong>Nova conta</strong>
            <label className="form-label" htmlFor="new-email">Email</label>
            <input id="new-email" className="input" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required autoComplete="off" />
            <label className="form-label" htmlFor="new-name">Nome completo</label>
            <input id="new-name" className="input" value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} required />
            <label className="form-label" htmlFor="new-username">Nome de utilizador (opcional)</label>
            <input id="new-username" className="input" value={form.data.username} onChange={(e) => form.setData('username', e.target.value.toLowerCase())}
                placeholder="Ex.: joao.operador" autoComplete="off" autoCapitalize="none" spellCheck={false} maxLength={30} />
            <div className="muted-text small-note">Para entrar sem escrever o e-mail. Letras minúsculas, números, ponto, hífen e sublinhado.</div>
            <AccessFields idPrefix="new" data={form.data} setData={(k, v) => form.setData(k, v)} {...lists} />
            <label className="form-label" htmlFor="new-password">Palavra-passe temporária</label>
            <div className="password-row">
                <input id="new-password" className="input" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} minLength={8} required autoComplete="off" />
                <button type="button" className="secondary-button" onClick={() => form.setData('password', generatePassword())}>GERAR OUTRA</button>
            </div>
            <div className="muted-text small-note">Entregue-a à pessoa em mão ou por telefone. Mínimo 8 caracteres.</div>
            {firstError(form.errors) && <div className="error-box">{firstError(form.errors)}</div>}
            <div className="admin-form-actions">
                <button type="button" className="secondary-button" onClick={onDone}>CANCELAR</button>
                <button type="submit" className="primary-button" disabled={form.processing || !form.data.role}>{form.processing ? 'A CRIAR…' : 'CRIAR CONTA'}</button>
            </div>
        </form>
    );
}

function UserCard({ user, ...lists }: { user: AppUser } & Omit<Props, 'users'>) {
    const [panel, setPanel] = useState<'none' | 'edit' | 'reset'>('none');
    const badge = user.status === 'ACTIVE' ? 'Ativo' : 'Inativo';
    const needsProvince = lists.roles.find((r) => r.value === user.role)?.province ?? false;
    return (
        <div className="card admin-user">
            <div className="row-between">
                <div>
                    <strong>{user.full_name}</strong>{user.is_me && <span className="muted-text"> (você)</span>}
                    <div className="muted-text">{user.email}{user.username && <> · utilizador <strong>{user.username}</strong></>}</div>
                </div>
                <span className={`badge badge-${badge.toLowerCase()}`}>{badge}</span>
            </div>
            <div className="admin-user-meta">
                <span>{user.role_label}</span>
                {user.agrodealer_name && <span>· {user.agrodealer_name}</span>}
                {user.province_name && <span>· {user.province_name}</span>}
                {needsProvince && !user.province_id && <span className="warning-text">· sem província: não vê nada até lhe atribuir uma</span>}
            </div>
            <div className="muted-text small-note">Último acesso: {user.last_login_at ? formatDateTime(user.last_login_at) : 'nunca'}</div>
            {panel === 'edit' && <EditUser user={user} onDone={() => setPanel('none')} {...lists} />}
            {panel === 'reset' && <ResetPassword user={user} onDone={() => setPanel('none')} />}
            {panel === 'none' && !user.is_me && (
                <div className="user-actions">
                    <button className="quick-link admin-edit" onClick={() => setPanel('edit')}>Editar acesso →</button>
                    <button className="quick-link admin-edit" onClick={() => setPanel('reset')}>Repor palavra-passe</button>
                </div>
            )}
        </div>
    );
}

export default function Users({ users, ...lists }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [creating, setCreating] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('novo') === 'gestor');
    const [filter, setFilter] = useState('');
    // Fechar esconde só estas credenciais; as de uma conta seguinte aparecem.
    const [closedPassword, setClosedPassword] = useState<string | null>(null);
    const credentials = flash.credentials && flash.credentials.password !== closedPassword ? flash.credentials : null;

    const term = filter.trim().toLowerCase();
    const visible = users.filter((u) => !term
        || u.full_name.toLowerCase().includes(term)
        || u.email.toLowerCase().includes(term)
        || (u.username ?? '').includes(term)
        || (u.agrodealer_name ?? '').toLowerCase().includes(term));

    return (
        <AdminLayout>
            <Head title="Utilizadores" />
            <div className="admin-page-heading directory-heading"><div><div className="eyebrow">ADMINISTRAÇÃO · ACESSOS</div><h1>Utilizadores</h1><p>Gerir contas, perfis, permissões e acessos ao sistema.</p></div>{!creating && <button className="primary-button directory-primary" onClick={() => setCreating(true)}>+ Criar utilizador</button>}</div><div className="directory-summary"><div className="directory-stat"><span>Contas registadas</span><strong>{users.length}</strong><small>Total de utilizadores</small></div><div className="directory-stat"><span>Activas</span><strong>{users.filter(u=>u.status==="ACTIVE").length}</strong><small>Com acesso ao sistema</small></div><div className="directory-stat"><span>Inactivas</span><strong>{users.filter(u=>u.status==="INACTIVE").length}</strong><small>Acesso suspenso</small></div><div className="directory-stat"><span>Perfis diferentes</span><strong>{new Set(users.map(u=>u.role)).size}</strong><small>Funções atribuídas</small></div></div>
            {credentials && (
                <div className="success-box">
                    {credentials.kind === 'created'
                        ? <>Conta criada para <strong>{credentials.full_name}</strong> ({credentials.role_label}). Entregue estes dados à pessoa; a palavra-passe não volta a ser mostrada.</>
                        : <>Palavra-passe de <strong>{credentials.full_name}</strong> reposta. Entregue-a à pessoa; no próximo acesso terá de escolher uma nova.</>}
                    <div className="credentials"><span>Email</span><strong>{credentials.email}</strong>{credentials.username && <><span>Utilizador</span><strong>{credentials.username}</strong></>}<span>Palavra-passe</span><strong>{credentials.password}</strong></div>
                    <button className="quick-link" onClick={() => setClosedPassword(credentials.password)}>Fechar</button>
                </div>
            )}
            {flash.success && <div className="success-box">{flash.success}</div>}

            {creating && <CreateUser onDone={() => setCreating(false)} {...lists} />}
            <div className="directory-toolbar"><div className="directory-search"><span>⌕</span><input className="input" placeholder="Pesquisar nome, email, utilizador ou comerciante…" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Pesquisar utilizadores" /></div><span className="directory-count">{visible.length} de {users.length} contas</span></div>
            <div className="directory-list-head"><h2>Contas de utilizador</h2><span>Estado · Perfil · Último acesso</span></div><div className="directory-user-list">{visible.length === 0 && <div className="directory-empty">Nenhuma conta encontrada. Ajuste a pesquisa ou crie um utilizador.</div>}{visible.map((u) => <UserCard key={u.id} user={u} {...lists} />)}</div>
        </AdminLayout>
    );
}
