import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { formatMT } from '@/lib/format';
import { requestJson } from '@/lib/http';

// Os produtos que um agrodealer vende. O preço vem do distrito do agrodealer;
// não há preço por agrodealer. Igual a app/components/catalogue-editor.tsx.

type CatalogueProduct = {
    product_id: string; code: string; name: string; unit: string; category: string | null;
    in_catalogue: boolean; unit_price: string | null;
};
type Catalogue = { agrodealer_id: string; name: string; district: string | null; products: CatalogueProduct[] };

export function CatalogueEditor({ agrodealerId, canManage }: { agrodealerId: string; canManage: boolean }) {
    const [catalogue, setCatalogue] = useState<Catalogue | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(() => {
        requestJson<Catalogue>('GET', `/admin/comerciantes/${agrodealerId}/catalogue`)
            .then(setCatalogue)
            .catch((err: Error) => setError(err.message));
    }, [agrodealerId]);

    useEffect(() => { load(); }, [load]);

    async function toggle(product: CatalogueProduct) {
        setBusy(product.product_id); setError(null);
        try {
            await requestJson('PUT', `/admin/comerciantes/${agrodealerId}/catalogue/${product.product_id}`, { in_catalogue: !product.in_catalogue });
            load();
            // Actualiza o número de produtos no cartão do agrodealer.
            router.reload({ only: ['agrodealers'] });
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Não foi possível mudar o catálogo.');
        } finally {
            setBusy(null);
        }
    }

    if (!catalogue) return error ? <div className="error-box">{error}</div> : <p>A carregar…</p>;

    const inCatalogue = catalogue.products.filter((p) => p.in_catalogue);
    const others = catalogue.products.filter((p) => !p.in_catalogue);
    const district = catalogue.district ?? 'sem distrito';

    const row = (p: CatalogueProduct) => (
        <div className="catalogue-row" key={p.product_id}>
            <span className="catalogue-who">
                <strong>{p.name}</strong>
                <small>{p.code} · {p.unit}{p.category ? ` · ${p.category}` : ''}</small>
            </span>
            <span className="catalogue-price">
                {p.unit_price !== null
                    ? <>{formatMT(p.unit_price)} / {p.unit}</>
                    : <span className="warning-text">Sem preço em {district}{p.in_catalogue ? ': não se vende' : ''}</span>}
            </span>
            {canManage && (
                <button className={p.in_catalogue ? 'quick-link danger' : 'quick-link'} disabled={busy !== null} onClick={() => toggle(p)}
                    aria-label={`${p.in_catalogue ? 'Retirar' : 'Adicionar'} ${p.name}`}>
                    {busy === p.product_id ? '…' : p.in_catalogue ? 'Retirar' : 'Adicionar'}
                </button>
            )}
        </div>
    );

    return (
        <div className="catalogue-editor">
            <p className="muted-text">Preços de <strong>{district}</strong>. {inCatalogue.length} produto(s) no catálogo.</p>
            {error && <div className="error-box">{error}</div>}
            <div className="catalogue-list">
                {inCatalogue.length === 0 && <div className="muted-text catalogue-empty">O catálogo está vazio: o POS ainda não vende nada.</div>}
                {inCatalogue.map(row)}
            </div>
            {canManage && others.length > 0 && (
                <>
                    <div className="catalogue-subhead">Outros produtos</div>
                    <div className="catalogue-list">{others.map(row)}</div>
                </>
            )}
        </div>
    );
}
