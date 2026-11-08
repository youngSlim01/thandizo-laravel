import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, type ChangeEvent, type FormEvent } from 'react';
import { BackOfficeLayout } from '@/components/finance-layout';
import { formatMT } from '@/lib/format';
import { downloadPriceTemplate, parsePrice, priceChanges, readPriceFile, type PriceFile } from '@/lib/price-import';
import type { SharedProps } from '@/types';

// Produtos e o seu preço em cada distrito do programa, como em
// app/admin/products/page.tsx do Next.js. Cada distrito tem os seus preços;
// mudar um preço tem efeito imediato e o antigo fica no histórico.

type Status = 'ACTIVE' | 'INACTIVE';
type District = { id: string; name: string; province: string };
type Product = {
    id: string; code: string; name: string; unit: string; category: string | null; status: Status;
    prices: Record<string, string>; agrodealers: number;
};
type Props = { products: Product[]; districts: District[]; canManage: boolean };

function firstError(errors: Record<string, string>): string | undefined {
    return Object.values(errors)[0];
}

function ProductForm({ product, districts, onDone }: { product: Product | null; districts: District[]; onDone: () => void }) {
    const form = useForm({
        name: product?.name ?? '',
        unit: product?.unit ?? '',
        category: product?.category ?? '',
        status: (product?.status ?? 'ACTIVE') as Status,
        prices: {} as Record<string, string>,
    });
    const [priceError, setPriceError] = useState<string | null>(null);

    function save(event: FormEvent) {
        event.preventDefault();
        setPriceError(null);
        const options = { onSuccess: onDone, preserveScroll: true };
        if (product) {
            form.transform(({ prices: _prices, ...data }) => data);
            form.put(`/admin/products/${product.id}`, options);
            return;
        }
        // Produto e preços de uma vez: gravam-se os dois, ou nenhum.
        const parsed: Record<string, number> = {};
        for (const d of districts) {
            const value = parsePrice(form.data.prices[d.id] ?? '');
            if (value === null) continue;
            if (!Number.isFinite(value) || value <= 0) { setPriceError(`Preço inválido em ${d.name}.`); return; }
            parsed[d.id] = value;
        }
        form.transform(({ status: _status, ...data }) => ({ ...data, prices: parsed }));
        form.post('/admin/products', options);
    }

    const error = priceError ?? firstError(form.errors);

    return (
        <form className="admin-form" onSubmit={save}>
            <strong>{product ? `Editar ${product.name}` : 'Novo produto'}</strong>
            <div className="form-grid-2">
                <div><span className="form-label">Código</span>
                    <div className="readonly-field">{product?.code ?? 'Gerado ao guardar (ex.: PRD-0001)'}</div></div>
                <div><label className="form-label" htmlFor="product-name">Nome</label>
                    <input id="product-name" className="input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Ex.: Semente de milho" /></div>
                <div><label className="form-label" htmlFor="product-unit">Unidade</label>
                    <input id="product-unit" className="input" value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} placeholder="Ex.: kg, saco, un" /></div>
                <div><label className="form-label" htmlFor="product-category">Categoria</label>
                    <input id="product-category" className="input" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} placeholder="Ex.: Sementes" /></div>
            </div>
            {!product && districts.length > 0 && (
                <>
                    <div className="form-label">Preço por distrito (MT por unidade) — deixe vazio onde não se vende</div>
                    <div className="form-grid-4">
                        {districts.map((d) => (
                            <div key={d.id}>
                                <label className="form-label small-label" htmlFor={`new-price-${d.id}`}>{d.name}</label>
                                <input id={`new-price-${d.id}`} className="input" inputMode="decimal" value={form.data.prices[d.id] ?? ''}
                                    onChange={(e) => form.setData('prices', { ...form.data.prices, [d.id]: e.target.value })} />
                            </div>
                        ))}
                    </div>
                </>
            )}
            {product && (
                <>
                    <label className="form-label" htmlFor="product-status">Situação</label>
                    <select id="product-status" className="input" value={form.data.status} onChange={(e) => form.setData('status', e.target.value as Status)}>
                        <option value="ACTIVE">Ativo</option>
                        <option value="INACTIVE">Inativo (deixa de se vender em todos os comerciantes)</option>
                    </select>
                </>
            )}
            {error && <div className="error-box">{error}</div>}
            <div className="admin-form-actions">
                <button type="button" className="secondary-button" onClick={onDone} disabled={form.processing}>CANCELAR</button>
                <button type="submit" className="primary-button" disabled={form.processing}>{form.processing ? 'A GUARDAR…' : 'GUARDAR PRODUTO'}</button>
            </div>
        </form>
    );
}

function DistrictForm({ onDone }: { onDone: () => void }) {
    const form = useForm({ province: '', district: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.post('/admin/districts', { onSuccess: onDone, preserveScroll: true });
    }

    return (
        <form className="admin-form" onSubmit={save}>
            <strong>Adicionar distrito ao programa</strong>
            <div className="form-grid-2">
                <div><label className="form-label" htmlFor="district-province">Província</label>
                    <input id="district-province" className="input" value={form.data.province} onChange={(e) => form.setData('province', e.target.value)} placeholder="Ex.: Tete" /></div>
                <div><label className="form-label" htmlFor="district-name">Distrito</label>
                    <input id="district-name" className="input" value={form.data.district} onChange={(e) => form.setData('district', e.target.value)} placeholder="Ex.: Mutarara" /></div>
            </div>
            {firstError(form.errors) && <div className="error-box">{firstError(form.errors)}</div>}
            <div className="admin-form-actions">
                <button type="button" className="secondary-button" onClick={onDone} disabled={form.processing}>CANCELAR</button>
                <button type="submit" className="primary-button" disabled={form.processing || !form.data.province.trim() || !form.data.district.trim()}>ADICIONAR DISTRITO</button>
            </div>
        </form>
    );
}

function PriceCell({ product, district, canManage }: { product: Product; district: District; canManage: boolean }) {
    const current = product.prices[district.id];
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function save(price: number | null) {
        setBusy(true); setError(null);
        router.put(`/admin/products/${product.id}/prices/${district.id}`, { unit_price: price }, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
            onError: (errors) => setError(firstError(errors) ?? 'Não foi possível guardar o preço.'),
            onFinish: () => setBusy(false),
        });
    }

    if (!editing) {
        return (
            <td className="price-cell">
                {current !== undefined ? <strong>{formatMT(current)}</strong> : <span className="muted-text">Sem preço</span>}
                {canManage && product.status === 'ACTIVE' && (
                    <button className="quick-link small-link" onClick={() => { setValue(current !== undefined ? String(Number(current)) : ''); setEditing(true); }}
                        aria-label={`Preço de ${product.name} em ${district.name}`}>{current !== undefined ? 'Mudar' : 'Definir'}</button>
                )}
            </td>
        );
    }
    const parsed = Number(value.replace(',', '.'));
    const valid = value.trim() !== '' && Number.isFinite(parsed) && parsed > 0;
    const changed = valid && parsed !== Number(current);
    return (
        <td className="price-cell editing">
            <input className="input" inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)}
                aria-label={`Novo preço de ${product.name} em ${district.name}`} autoFocus />
            {changed && current !== undefined && <small className="muted-text">{formatMT(current)} → {formatMT(parsed)}, em vigor já.</small>}
            {error && <small className="error-text">{error}</small>}
            <div className="price-cell-actions">
                <button className="primary-button small-button" disabled={busy || !changed} onClick={() => save(parsed)}>GUARDAR</button>
                {current !== undefined && <button className="quick-link danger small-link" disabled={busy} onClick={() => save(null)}>Retirar preço</button>}
                <button className="quick-link small-link" disabled={busy} onClick={() => setEditing(false)}>Cancelar</button>
            </div>
        </td>
    );
}

function PriceImport({ districts, products, onDone }: { districts: District[]; products: Product[]; onDone: () => void }) {
    const [file, setFile] = useState<{ name: string; parsed: PriceFile } | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function template() {
        setBusy('template'); setError(null);
        try { await downloadPriceTemplate(districts, products); }
        catch (err) { setError(err instanceof Error ? err.message : 'Não foi possível preparar o modelo.'); }
        finally { setBusy(null); }
    }

    async function choose(event: ChangeEvent<HTMLInputElement>) {
        const f = event.target.files?.[0];
        event.target.value = '';
        if (!f) return;
        setBusy('read'); setError(null); setFile(null);
        try { setFile({ name: f.name, parsed: await readPriceFile(f, districts, products) }); }
        catch (err) { setError(err instanceof Error ? err.message : 'Não consegui ler o ficheiro.'); }
        finally { setBusy(null); }
    }

    function apply() {
        if (!file) return;
        setBusy('import'); setError(null);
        router.post('/admin/products/import', {
            file_name: file.name,
            rows: file.parsed.rows.map((row) => ({ code: row.code, name: row.name, unit: row.unit, category: row.category, prices: row.prices })),
        }, {
            preserveScroll: true,
            onSuccess: onDone,
            onError: (errors) => setError(firstError(errors) ?? 'A importação falhou; nada foi gravado.'),
            onFinish: () => setBusy(null),
        });
    }

    const summary = file ? priceChanges(file.parsed.rows) : null;
    const blocked = !file || file.parsed.errors.length > 0 || file.parsed.rows.length === 0 || (summary!.changed === 0 && summary!.newProducts === 0);

    return (
        <div className="admin-form price-import">
            <div className="row-between"><strong>Importar lista de preços</strong><button className="quick-link" onClick={onDone}>Fechar</button></div>
            <p className="muted-text">
                Descarregue o modelo: já traz os produtos e os preços atuais, uma coluna por distrito. Passe para lá a lista do comité,
                guarde e importe. Célula vazia não muda nada. Antes de gravar, vê tudo o que vai mudar.
            </p>
            <div className="admin-form-actions">
                <button className="secondary-button" onClick={template} disabled={busy !== null}>{busy === 'template' ? 'A PREPARAR…' : 'DESCARREGAR MODELO'}</button>
                <label className="secondary-button file-button">
                    {busy === 'read' ? 'A LER…' : 'ESCOLHER FICHEIRO'}
                    <input type="file" accept=".xlsx,.csv" onChange={choose} disabled={busy !== null} hidden aria-label="Ficheiro de preços" />
                </label>
            </div>
            {error && <div className="error-box">{error}</div>}

            {file && (
                <>
                    <div className="muted-text"><strong>{file.name}</strong> · {file.parsed.rows.length} produto(s)</div>
                    {file.parsed.errors.length > 0 && (
                        <div className="error-box">
                            Corrija o ficheiro e importe de novo; nada foi gravado:
                            <ul>{file.parsed.errors.slice(0, 20).map((e) => <li key={e}>{e}</li>)}</ul>
                            {file.parsed.errors.length > 20 && <div>… e mais {file.parsed.errors.length - 20}.</div>}
                        </div>
                    )}
                    {file.parsed.errors.length === 0 && summary && (
                        <div className="info-box">
                            {summary.changed} preço(s) a mudar, {summary.newProducts} produto(s) novo(s), {summary.unchanged} preço(s) iguais aos atuais.
                        </div>
                    )}
                    {file.parsed.rows.length > 0 && (
                        <div className="table-scroll">
                            <table className="price-table">
                                <thead><tr><th>Produto</th>{districts.map((d) => <th key={d.id}>{d.name}</th>)}</tr></thead>
                                <tbody>
                                    {file.parsed.rows.map((row) => (
                                        <tr key={row.code || `novo-${row.line}`}>
                                            <td className="product-cell"><strong>{row.name}</strong><small>{row.code || 'código gerado ao importar'} · {row.unit}</small>{row.isNew && <span className="badge badge-active">Novo</span>}</td>
                                            {districts.map((d) => {
                                                const next = row.prices[d.id];
                                                const now = row.current[d.id];
                                                if (next === undefined) return <td key={d.id} className="muted-text">{now !== undefined ? formatMT(now) : '—'}</td>;
                                                if (now !== undefined && Number(now) === next) return <td key={d.id}>{formatMT(next)} <small className="muted-text">igual</small></td>;
                                                return <td key={d.id} className="price-change">{now !== undefined ? <><s>{formatMT(now)}</s> → </> : ''}<strong>{formatMT(next)}</strong></td>;
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <button className="primary-button" onClick={apply} disabled={blocked || busy !== null}>
                        {busy === 'import' ? 'A IMPORTAR…' : 'IMPORTAR PREÇOS'}
                    </button>
                </>
            )}
        </div>
    );
}

export default function Products({ products, districts, canManage }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [form, setForm] = useState<'none' | 'new' | 'district' | 'import' | Product>('none');
    const [filter, setFilter] = useState('');
    const close = () => setForm('none');

    const term = filter.trim().toLowerCase();
    const visible = products.filter((p) => !term || p.name.toLowerCase().includes(term) || p.code.toLowerCase().includes(term));

    return (
        <BackOfficeLayout>
            <Head title="Produtos e preços" />
            <div className="admin-page-heading">
                <div>
                    <div className="eyebrow">MEAL · CATÁLOGO</div>
                    <h1>Produtos e preços</h1>
                    <p>Manter produtos, preços por distrito e importações de tabelas de preços.</p>
                </div>
            </div>
            <div className="info-box">
                Cada distrito tem os seus preços: o MEAL lança aqui a lista entregue por cada comité, à mão ou por Excel. Um comerciante vende ao preço do seu distrito os produtos do seu catálogo.
                Mudar um preço tem efeito imediato; o preço antigo fica no histórico e as vendas feitas não mudam.
                Um distrito sem preço para um produto não o vende.
            </div>

            {flash.success && <div className="success-box">{flash.success}</div>}

            {canManage && form === 'none' && (
                <div className="admin-form-actions">
                    <button className="primary-button" onClick={() => setForm('new')}>+ NOVO PRODUTO</button>
                    <button className="secondary-button" onClick={() => setForm('import')}>IMPORTAR EXCEL</button>
                    <button className="secondary-button" onClick={() => setForm('district')}>+ ADICIONAR DISTRITO</button>
                </div>
            )}
            {form === 'new' && <ProductForm product={null} districts={districts} onDone={close} />}
            {form === 'import' && <PriceImport districts={districts} products={products} onDone={close} />}
            {form === 'district' && <DistrictForm onDone={close} />}
            {typeof form === 'object' && <ProductForm key={form.id} product={form} districts={districts} onDone={close} />}

            <input className="input" placeholder="Procurar produto" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Procurar produto" />

            {products.length === 0 && <p className="muted-text">Ainda não há produtos.</p>}
            {products.length > 0 && (
                <div className="table-scroll">
                    <table className="price-table">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                {districts.map((d) => <th key={d.id}>{d.name}<small>{d.province}</small></th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((p) => (
                                <tr key={p.id} className={p.status === 'INACTIVE' ? 'inactive-row' : ''}>
                                    <td className="product-cell">
                                        <strong>{p.name}</strong>
                                        <small>{p.code} · {p.unit}{p.category ? ` · ${p.category}` : ''} · {p.agrodealers} comerciante(s)</small>
                                        {p.status === 'INACTIVE' && <span className="badge">Inativo</span>}
                                        {canManage && <button className="quick-link small-link" onClick={() => setForm(p)}>Editar</button>}
                                    </td>
                                    {districts.map((d) => <PriceCell key={d.id} product={p} district={d} canManage={canManage} />)}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </BackOfficeLayout>
    );
}
