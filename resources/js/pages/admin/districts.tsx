import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { SharedProps } from '@/types';

type District = { id: string; name: string; province: string };

type Props = { districts: District[]; canCreate: boolean };

// Formulário igual ao DistrictForm do sistema Next.js (app/admin/products/page.tsx).
function DistrictForm({ onDone }: { onDone: () => void }) {
    const form = useForm({ province: '', district: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.post('/admin/districts', { onSuccess: () => { form.reset(); onDone(); } });
    }

    const error = form.errors.province ?? form.errors.district;

    return (
        <form className="admin-form" onSubmit={save}>
            <strong>Adicionar distrito ao programa</strong>
            <div className="form-grid-2">
                <div>
                    <label className="form-label" htmlFor="district-province">Província</label>
                    <input id="district-province" className="input" value={form.data.province} onChange={(e) => form.setData('province', e.target.value)} placeholder="Ex.: Tete" />
                </div>
                <div>
                    <label className="form-label" htmlFor="district-name">Distrito</label>
                    <input id="district-name" className="input" value={form.data.district} onChange={(e) => form.setData('district', e.target.value)} placeholder="Ex.: Mutarara" />
                </div>
            </div>
            {error && <div className="error-box">{error}</div>}
            <div className="admin-form-actions">
                <button type="button" className="secondary-button" onClick={onDone} disabled={form.processing}>CANCELAR</button>
                <button type="submit" className="primary-button" disabled={form.processing || !form.data.province.trim() || !form.data.district.trim()}>
                    ADICIONAR DISTRITO
                </button>
            </div>
        </form>
    );
}

// Página provisória do nível 0. Na fase dos produtos (nível 2) este bloco
// volta a viver também na página "Produtos e preços", como no sistema actual.
export default function Districts({ districts, canCreate }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [adding, setAdding] = useState(false);

    return (
        <main className="admin-shell">
            <Head title="Distritos do programa" />
            <div className="admin-page-heading">
                <div>
                    <div className="eyebrow">MEAL · PROGRAMA</div>
                    <h1>Distritos do programa</h1>
                    <p>Distritos onde o programa actua: cada um tem os seus preços e os seus agrodealers.</p>
                </div>
            </div>

            {flash.success && <div className="success-box">{flash.success}</div>}

            {canCreate && !adding && (
                <div className="admin-form-actions">
                    <button className="primary-button" onClick={() => setAdding(true)}>+ ADICIONAR DISTRITO</button>
                </div>
            )}
            {adding && <DistrictForm onDone={() => setAdding(false)} />}

            {districts.length === 0 && <p className="muted-text">Ainda não há distritos no programa.</p>}
            {districts.length > 0 && (
                <div className="table-scroll">
                    <table className="price-table">
                        <thead><tr><th>Distrito</th><th>Província</th></tr></thead>
                        <tbody>
                            {districts.map((d) => (
                                <tr key={d.id}><td><strong>{d.name}</strong></td><td>{d.province}</td></tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="quick-list">
                <Link href="/" className="quick-link">← Início</Link>
            </div>
        </main>
    );
}
