import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { SharedProps } from '@/types';

type Status = 'ACTIVE' | 'INACTIVE';
type Product = { id: string; code: string; name: string; unit: string; category: string | null; status: Status };
type Props = { products: Product[]; canManage: boolean };

// Formulário igual ao ProductForm do sistema Next.js (app/admin/products/page.tsx),
// por agora sem os preços por distrito (nível 5).
function ProductForm({ product, onDone }: { product: Product | null; onDone: () => void }) {
    const form = useForm({
        name: product?.name ?? '',
        unit: product?.unit ?? '',
        category: product?.category ?? '',
        status: (product?.status ?? 'ACTIVE') as Status,
    });

    function save(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: onDone, preserveScroll: true };
        if (product) {
            form.put(`/admin/products/${product.id}`, options);
        } else {
            form.transform(({ name, unit, category }) => ({ name, unit, category }));
            form.post('/admin/products', options);
        }
    }

    const error = form.errors.name ?? form.errors.unit ?? form.errors.category ?? form.errors.status;

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
            {product && (
                <>
                    <label className="form-label" htmlFor="product-status">Situação</label>
                    <select id="product-status" className="input" value={form.data.status} onChange={(e) => form.setData('status', e.target.value as Status)}>
                        <option value="ACTIVE">Ativo</option>
                        <option value="INACTIVE">Inativo (deixa de se vender em todos os agrodealers)</option>
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

export default function Products({ products, canManage }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [form, setForm] = useState<'none' | 'new' | Product>('none');
    const [filter, setFilter] = useState('');

    const term = filter.trim().toLowerCase();
    const visible = products.filter((p) => !term || p.name.toLowerCase().includes(term) || p.code.toLowerCase().includes(term));

    return (
        <main className="admin-shell">
            <Head title="Produtos e preços" />
            <div className="admin-page-heading">
                <div>
                    <div className="eyebrow">MEAL · CATÁLOGO</div>
                    <h1>Produtos e preços</h1>
                    <p>Manter produtos, preços por distrito e importações de tabelas de preços.</p>
                </div>
            </div>
            <div className="info-box">
                Cada distrito tem os seus preços; um distrito sem preço para um produto não o vende.
                Os preços por distrito e a importação por Excel chegam numa próxima etapa.
            </div>

            {flash.success && <div className="success-box">{flash.success}</div>}

            {canManage && form === 'none' && (
                <div className="admin-form-actions">
                    <button className="primary-button" onClick={() => setForm('new')}>+ NOVO PRODUTO</button>
                </div>
            )}
            {form === 'new' && <ProductForm product={null} onDone={() => setForm('none')} />}
            {typeof form === 'object' && <ProductForm key={form.id} product={form} onDone={() => setForm('none')} />}

            <input className="input" placeholder="Procurar produto" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Procurar produto" />

            {products.length === 0 && <p className="muted-text">Ainda não há produtos.</p>}
            {products.length > 0 && (
                <div className="table-scroll">
                    <table className="price-table">
                        <thead><tr><th>Produto</th></tr></thead>
                        <tbody>
                            {visible.map((p) => (
                                <tr key={p.id} className={p.status === 'INACTIVE' ? 'inactive-row' : ''}>
                                    <td className="product-cell">
                                        <strong>{p.name}</strong>
                                        <small>{p.code} · {p.unit}{p.category ? ` · ${p.category}` : ''}</small>
                                        {p.status === 'INACTIVE' && <span className="badge">Inativo</span>}
                                        {canManage && <button className="quick-link small-link" onClick={() => setForm(p)}>Editar</button>}
                                    </td>
                                </tr>
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
