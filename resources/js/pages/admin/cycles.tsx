import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { CycleCard, type Cycle } from '@/components/cycle-card';
import type { SharedProps } from '@/types';

// Ciclos do programa: um aberto de cada vez. O MEAL recarrega os agregados
// escolhidos (mesmo cartão, 6 000 MT), acompanha quem ainda tem saldo e
// fecha o ciclo: o que sobra expira e fica no relatório como não utilizado.

type Props = { cycles: Cycle[]; canManage: boolean };

function OpenCycle({ next }: { next: number }) {
    const form = useForm({ name: `Ciclo ${next}`, starts_on: '', ends_on: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.post('/admin/cycles', { preserveScroll: true });
    }

    const error = form.errors.name ?? form.errors.starts_on ?? form.errors.ends_on ?? (form.errors as Record<string, string>).rule;

    return (
        <form className="admin-form" onSubmit={save}>
            <strong>Abrir novo ciclo</strong>
            <div className="form-grid-2">
                <div><label className="form-label" htmlFor="cycle-name">Nome</label>
                    <input id="cycle-name" className="input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></div>
                <div />
                <div><label className="form-label" htmlFor="cycle-start">Início</label>
                    <input id="cycle-start" type="date" className="input" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} /></div>
                <div><label className="form-label" htmlFor="cycle-end">Fim previsto</label>
                    <input id="cycle-end" type="date" className="input" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} /></div>
            </div>
            {error && <div className="error-box">{error}</div>}
            <button className="primary-button" disabled={form.processing}>{form.processing ? 'A ABRIR…' : 'ABRIR CICLO'}</button>
        </form>
    );
}

export default function Cycles({ cycles, canManage }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const open = cycles.find((c) => c.status === 'OPEN');

    return (
        <AdminLayout>
            <Head title="Ciclos" />
            <h1>Ciclos</h1>
            <div className="info-box">
                Um ciclo de cada vez. Em cada ciclo o MEAL escolhe quem recebe: cada agregado recebe 6.000 MT no mesmo cartão e tem de usar tudo dentro do ciclo.
                Ao fechar, o saldo que sobrar expira e fica no relatório como não utilizado.
            </div>
            {flash.success && <div className="success-box">{flash.success}</div>}
            {!open && canManage && <OpenCycle next={cycles.length + 1} />}
            {!open && !canManage && <div className="warning-box">Não há nenhum ciclo aberto.</div>}
            {cycles.map((c) => <CycleCard key={c.cycle_id} cycle={c} canManage={canManage} />)}
        </AdminLayout>
    );
}
