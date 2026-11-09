import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { CycleCard, CycleFields, type Cycle } from '@/components/cycle-card';
import type { SharedProps } from '@/types';

// Ciclos do programa: um aberto de cada vez. O MEAL recarrega os agregados
// escolhidos (mesmo cartão, valor do escalão), acompanha quem ainda tem saldo e
// fecha o ciclo: o que sobra expira e fica no relatório como não utilizado.

type PlanRow = {
    district_id: string; district: string; province: string; target_households: number | null;
    fair_starts_on: string | null; fair_ends_on: string | null; fair_status: 'none' | 'planned' | 'ongoing' | 'finished'; priced_products: number;
};
type Props = { cycles: Cycle[]; canManage: boolean; plan: PlanRow[] };

const FAIR_STATUS: Record<PlanRow['fair_status'], string> = { none: 'Por marcar', planned: 'Marcada', ongoing: 'A decorrer', finished: 'Terminada' };

// Uma linha do planeamento: meta e datas da feira de um distrito. Depois de a
// feira começar, só o gestor MEAL muda as datas, com o motivo.
function PlanLine({ cycleId, row, canManage }: { cycleId: string; row: PlanRow; canManage: boolean }) {
    const form = useForm({
        target_households: row.target_households === null ? '' : String(row.target_households),
        fair_starts_on: row.fair_starts_on ?? '', fair_ends_on: row.fair_ends_on ?? '', reason: '',
    });
    const started = row.fair_status === 'ongoing' || row.fair_status === 'finished';
    const datesLocked = started && !canManage;
    const datesChanged = form.data.fair_starts_on !== (row.fair_starts_on ?? '') || form.data.fair_ends_on !== (row.fair_ends_on ?? '');
    const error = form.errors.target_households ?? form.errors.fair_starts_on ?? form.errors.fair_ends_on ?? (form.errors as Record<string, string>).rule;

    function save(event: FormEvent) {
        event.preventDefault();
        form.put(`/admin/cycles/${cycleId}/districts/${row.district_id}`, { preserveScroll: true, onSuccess: () => { form.setData('reason', ''); form.setDefaults(); } });
    }

    return (
        <form className="plan-line" onSubmit={save}>
            <div className="plan-district"><strong>{row.district}</strong><small>{row.province}</small></div>
            <label><span>Meta (agregados)</span>
                <input className="input" inputMode="numeric" value={form.data.target_households} onChange={(e) => form.setData('target_households', e.target.value)} /></label>
            <label><span>Feira: início</span>
                <input className="input" type="date" disabled={datesLocked} value={form.data.fair_starts_on} onChange={(e) => form.setData('fair_starts_on', e.target.value)} /></label>
            <label><span>Fim</span>
                <input className="input" type="date" disabled={datesLocked} value={form.data.fair_ends_on} onChange={(e) => form.setData('fair_ends_on', e.target.value)} /></label>
            <span className={`plan-status plan-${row.fair_status}`}>{FAIR_STATUS[row.fair_status]}</span>
            <button type="submit" className="sc-save" disabled={form.processing || !form.isDirty}>{form.processing ? 'A guardar…' : 'Guardar'}</button>
            {started && datesChanged && (
                <label className="plan-reason"><span>Motivo da mudança de datas (decisão da direcção)</span>
                    <input className="input" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Ex.: prolongar a feira por um dia, decisão de 12/11" /></label>
            )}
            {(row.fair_status === 'planned' || row.fair_status === 'ongoing') && row.priced_products === 0 && (
                <small className="plan-note plan-warning">Ainda não há preços em {row.district} neste ciclo: o POS não vende. <Link href="/admin/products">Lançar preços →</Link></small>
            )}
            {datesLocked && <small className="plan-note">A feira já começou: só o gestor MEAL muda as datas.</small>}
            {error && <div className="error-box plan-error">{error}</div>}
        </form>
    );
}

function CyclePlan({ cycleId, plan, canManage }: { cycleId: string; plan: PlanRow[]; canManage: boolean }) {
    return (
        <section className="sc-card">
            <div className="sc-head"><div><h2>Metas e feiras por distrito</h2>
                <p>Meta de agregados a alcançar e os dias da feira de cada distrito. Só se vende nos dias da feira; quando a feira acaba, o distrito fica travado e o saldo fica guardado até a direcção decidir.</p></div></div>
            <div className="sc-body">
                {plan.map((row) => <PlanLine key={row.district_id} cycleId={cycleId} row={row} canManage={canManage} />)}
                {plan.length === 0 && <p className="muted-text">Não há distritos do programa na sua área.</p>}
            </div>
        </section>
    );
}

function OpenCycle({ next }: { next: number }) {
    const form = useForm({ name: `Ciclo ${next}`, description: '', starts_on: '', ends_on: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.post('/admin/cycles', { preserveScroll: true });
    }

    const error = form.errors.name ?? form.errors.description ?? form.errors.starts_on ?? form.errors.ends_on ?? (form.errors as Record<string, string>).rule;

    return (
        <form className="admin-form" onSubmit={save}>
            <strong>Abrir novo ciclo</strong>
            <CycleFields form={form} idPrefix="cycle" />
            {error && <div className="error-box">{error}</div>}
            <button className="primary-button" disabled={form.processing}>{form.processing ? 'A ABRIR…' : 'ABRIR CICLO'}</button>
        </form>
    );
}

export default function Cycles({ cycles, canManage, plan }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const open = cycles.find((c) => c.status === 'OPEN');

    return (
        <AdminLayout>
            <Head title="Ciclos" />
            <h1>Ciclos</h1>
            <div className="info-box">
                Um ciclo de cada vez. Em cada ciclo o MEAL escolhe quem recebe: cada agregado recebe no mesmo cartão o valor do seu escalão e tem de usar tudo dentro do ciclo.
                Ao fechar, o saldo que sobrar expira e fica no relatório como não utilizado.
            </div>
            {flash.success && <div className="success-box">{flash.success}</div>}
            {!open && canManage && <OpenCycle next={cycles.length + 1} />}
            {!open && !canManage && <div className="warning-box">Não há nenhum ciclo aberto.</div>}
            {open && <CyclePlan cycleId={open.cycle_id} plan={plan} canManage={canManage} />}
            {cycles.map((c) => <CycleCard key={c.cycle_id} cycle={c} canManage={canManage} />)}
        </AdminLayout>
    );
}
