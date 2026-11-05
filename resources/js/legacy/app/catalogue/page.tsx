
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '../auth-context';
import { PosHeader } from '../components/pos-header';
import { CatalogueEditor } from '@/components/catalogue-editor';

// The agrodealer's own catalogue. The manager adds or removes products; the
// price always comes from the district. Operators only see it.
export default function CataloguePage() {
  const auth = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!auth.loading && !auth.session) router.replace('/login');
  }, [auth.loading, auth.session, router]);

  const agrodealerId = auth.operator?.agrodealerId;
  return (
    <main className="pos-shell">
      <PosHeader title="Catálogo" back="/" />
      <section className="pos-content">
        <div className="eyebrow">{auth.operator?.agrodealerName}</div>
        <h1>Catálogo de produtos</h1>
        <p>Os produtos que este agrodealer vende, aos preços do distrito.</p>
        {agrodealerId
          ? <CatalogueEditor agrodealerId={agrodealerId} canManage={auth.operator?.role === 'AGRODEALER_MANAGER'} />
          : !auth.loading && <div className="error-box">Esta conta não pertence a um agrodealer.</div>}
      </section>
    </main>
  );
}
