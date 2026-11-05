
import { useEffect, useMemo, useState } from 'react';
import { AdminGate } from '../../components/admin-gate';
import { AdminHeader } from '../../components/admin-header';
import { HOUSEHOLD_ROLES, USER_ADMIN_ROLES, useAuth } from '../../auth-context';
import { callRpc } from '../../../lib/supabase';
import { errorMessage } from '../../../lib/errors';
import { formatMT, formatDateTime } from '../../../lib/format';

type Agrodealer = { id: string; name: string };
// From list_settlements: names of who created, sent and reviewed each one.
type Settlement = {
  id: string; settlement_code: string; agrodealer_id: string; agrodealer: string; province: string | null;
  period_start: string; period_end: string; transaction_count: number;
  gross_amount: number; voucher_amount: number; copayment_amount: number;
  discount_amount: number; payable_amount: number; status: string;
  notes: string | null; manager_notes: string | null;
  created_at: string; created_by: string | null; submitted_at: string | null; submitted_by: string | null;
  manager_reviewed_at: string | null; manager_reviewed_by: string | null;
};
type Transaction = {
  id: string; transaction_code: string; total_amount: number; voucher_amount: number; copayment_amount: number;
  status: string; created_at: string;
  vouchers?: { voucher_code?: string } | { voucher_code?: string }[] | null;
};
const SETTLEMENT_STATUS:Record<string,string>={
  RECONCILED:'Por enviar', PENDING_MANAGER:'À espera do gestor MEAL', MANAGER_RETURNED:'Devolvida pelo gestor',
  SUBMITTED_TO_FINANCE:'No Finance', VALIDATED:'Validada pelo Finance', RETURNED:'Devolvida pelo Finance',
};
const statusLabel=(s:string)=>SETTLEMENT_STATUS[s]??s;
const voucherCode=(v:Transaction['vouchers'])=>Array.isArray(v)?v[0]?.voucher_code??'Voucher':v?.voucher_code??'Voucher';
const money=(n:number)=>formatMT(Number(n||0));

function SettlementsScreen(){
  const auth=useAuth();
  const isManager=USER_ADMIN_ROLES.includes(auth.operator?.role??'');
  // Only closed days can be settled, in Mozambique time: the default period is
  // from the first day of yesterday's month up to yesterday.
  const todayMz=new Date().toLocaleDateString('en-CA',{timeZone:'Africa/Maputo'});
  const yesterday=new Date(Date.parse(todayMz+'T00:00:00Z')-86400000).toISOString().slice(0,10);
  const monthStart=yesterday.slice(0,8)+'01';
  const monthEnd=yesterday;
  const [dealers,setDealers]=useState<Agrodealer[]>([]);
  const [settlements,setSettlements]=useState<Settlement[]>([]);
  const [dealerId,setDealerId]=useState('');
  const [start,setStart]=useState(monthStart);
  const [end,setEnd]=useState(monthEnd);
  const [selected,setSelected]=useState<Settlement|null>(null);
  const [items,setItems]=useState<Transaction[]>([]);
  const [loading,setLoading]=useState(true);
  const [working,setWorking]=useState(false);
  const [error,setError]=useState<string|null>(null);
  const [message,setMessage]=useState<string|null>(null);
  const [tab,setTab]=useState<'all'|'pending'>('all');
  const [reason,setReason]=useState('');

  async function load():Promise<Settlement[]>{
    setLoading(true); setError(null);
    let rows:Settlement[]=[];
    try{
      const [d,b]=await Promise.all([
        callRpc<Agrodealer[]>('list_agrodealer_options',{p_active_only:true}),
        callRpc<Settlement[]>('list_settlements',{}),
      ]);
      rows=b??[];
      setDealers(d??[]); setSettlements(rows);
    }catch(e){setError(errorMessage(e instanceof Error?e:new Error(String(e))))}
    setLoading(false);
    return rows;
  }
  useEffect(()=>{load().then(rows=>{ if(isManager&&rows.some(r=>r.status==='PENDING_MANAGER')) setTab('pending'); })},[isManager]);

  const pendingCount=settlements.filter(x=>x.status==='PENDING_MANAGER').length;
  const filtered=useMemo(()=>settlements.filter(x=>(!dealerId||x.agrodealer_id===dealerId)&&(tab==='all'||x.status==='PENDING_MANAGER')),[settlements,dealerId,tab]);

  async function createSettlement(){
    if(!dealerId){setError('Seleccione um agrodealer.');return}
    setWorking(true);setError(null);
    try{
      const id=await callRpc<string>('create_agrodealer_settlement',{p_agrodealer_id:dealerId,p_period_start:start,p_period_end:end});
      const rows=await load();
      const created=rows.find(r=>r.id===id);
      if(created) await openSettlement(created);
    }catch(e){setError(errorMessage(e instanceof Error?e:new Error(String(e))))}
    finally{setWorking(false)}
  }

  async function openSettlement(row:Settlement){
    setSelected(row);setError(null);setReason('');
    try{ setItems(await callRpc<Transaction[]>('get_settlement_transactions',{p_settlement_id:row.id})); }
    catch(e){ setError(errorMessage(e instanceof Error?e:new Error(String(e)))); }
  }

  // Officer -> MEAL manager -> Finance. Every step reloads the list so the
  // names and dates shown are the ones the database recorded.
  async function act(kind:'submit'|'approve'|'return'){
    if(!selected)return;
    if(kind==='return'&&!reason.trim()){setError('Indique o motivo da devolução.');return}
    setWorking(true);setError(null);setMessage(null);
    try{
      if(kind==='submit'){
        const next=await callRpc<string>('submit_agrodealer_settlement',{p_settlement_id:selected.id});
        setMessage(next==='PENDING_MANAGER'?'Enviada ao gestor MEAL para aprovação.':'Enviada para Finance.');
      }else if(kind==='approve'){
        await callRpc('manager_approve_settlement',{p_settlement_id:selected.id});
        setMessage('Aprovada e enviada para Finance.');
      }else{
        await callRpc('manager_return_settlement',{p_settlement_id:selected.id,p_reason:reason.trim()});
        setMessage('Devolvida. As vendas ficam livres para uma nova reconciliação.');
      }
      const rows=await load();
      setSelected(rows.find(r=>r.id===selected.id)??null);setReason('');
    }
    catch(e){setError(errorMessage(e instanceof Error?e:new Error(String(e))))}
    finally{setWorking(false)}
  }

  async function generatePdf(){
    if(!selected)return;
    const { jsPDF }=await import('jspdf');
    const doc=new jsPDF({unit:'mm',format:'a4'});
    const W=210;
    const green=[23,107,77] as const;
    doc.setFont('helvetica','bold');doc.setFontSize(17);doc.setTextColor(...green);
    doc.text('VOUCHER LINK GCF',18,18);
    doc.setFontSize(13);doc.setTextColor(30,40,50);doc.text('MAPA DE VENDAS E LIQUIDAÇÃO',18,27);
    doc.setFont('helvetica','normal');doc.setFontSize(9);doc.setTextColor(90,100,110);
    doc.text('Documento de suporte à submissão da factura pelo agrodealer',18,34);
    doc.setDrawColor(210,220,215);doc.line(18,39,W-18,39);
    doc.setFontSize(9);doc.setTextColor(35,45,55);
    const dealer=selected.agrodealer;
    doc.text('Agrodealer:',18,48);doc.setFont('helvetica','bold');doc.text(dealer,48,48);
    doc.setFont('helvetica','normal');doc.text('Referência:',18,55);doc.setFont('helvetica','bold');doc.text(selected.settlement_code,48,55);
    doc.setFont('helvetica','normal');doc.text('Período:',18,62);doc.setFont('helvetica','bold');doc.text(selected.period_start+' a '+selected.period_end,48,62);
    doc.setFont('helvetica','normal');
    let y=73;
    doc.setFillColor(234,246,240);doc.roundedRect(18,y,W-36,39,3,3,'F');
    doc.setFont('helvetica','bold');doc.setFontSize(9);doc.setTextColor(...green);
    doc.text('RESUMO FINANCEIRO',24,y+8);
    doc.setTextColor(35,45,55);doc.setFontSize(10);
    doc.text('Transações confirmadas',24,y+17);doc.text(String(selected.transaction_count),88,y+17);
    doc.text('Total das vendas (após desconto)',24,y+25);doc.text(money(selected.gross_amount),88,y+25);
    doc.text('Valor coberto por vouchers',112,y+17);doc.text(money(selected.voucher_amount),112,y+25);
    doc.text('Copagamentos (já recebidos)',112,y+33);doc.text(money(selected.copayment_amount),166,y+33);
    doc.setFont('helvetica','bold');doc.setFontSize(12);doc.text('VALOR A PAGAR',24,y+33);doc.text(money(selected.payable_amount),88,y+33);
    y+=50;
    doc.setFontSize(10);doc.setTextColor(...green);doc.text('DETALHE DAS TRANSAÇÕES',18,y);y+=7;
    doc.setFont('helvetica','bold');doc.setFontSize(7);doc.setTextColor(80,90,100);
    doc.text('Data',18,y);doc.text('Voucher',50,y);doc.text('Total',108,y);doc.text('Voucher',139,y);doc.text('Copagamento',169,y);y+=4;
    doc.setFont('helvetica','normal');doc.setTextColor(35,45,55);
    for(const r of items){
      if(y>278){doc.addPage();y=18;doc.setFont('helvetica','bold');doc.text('DETALHE DAS TRANSAÇÕES (continuação)',18,y);y+=8}
      doc.text(new Date(r.created_at).toLocaleDateString('pt-PT'),18,y);
      doc.text(voucherCode(r.vouchers).slice(0,18),50,y);
      doc.text(money(r.total_amount),108,y);
      doc.text(money(r.voucher_amount),139,y);
      doc.text(money(r.copayment_amount),169,y);y+=5;
    }
    y+=8;doc.setDrawColor(210,220,215);doc.line(18,y,W-18,y);y+=8;
    doc.setFontSize(8);doc.setTextColor(90,100,110);
    doc.text('Este documento apresenta as transações confirmadas pelo LINK Market no período indicado.',18,y);
    doc.text('A factura deve ser emitida pelo agrodealer de acordo com os procedimentos financeiros aplicáveis.',18,y+5);
    doc.setFont('helvetica','bold');doc.setTextColor(35,45,55);
    doc.text('Preparado por: '+(selected.created_by??'MEAL'),18,y+18);
    doc.text('Estado: '+statusLabel(selected.status),18,y+25);
    if(selected.manager_reviewed_by) doc.text('Aprovado pelo gestor MEAL: '+selected.manager_reviewed_by,18,y+32);
    doc.save(selected.settlement_code+'.pdf');
  }

  return <main className="admin-shell"><AdminHeader/>
    <div className="admin-page-heading"><div><div className="eyebrow">MEAL · RECONCILIAÇÃO</div><h1>Reconciliações</h1><p>{isManager?'Fechar períodos de vendas, aprovar as reconciliações dos oficiais e encaminhá-las para Finance.':'Fechar períodos de vendas dos agrodealers da sua província e enviá-los ao gestor MEAL para aprovação.'}</p></div></div>
    {error&&<div className="error-box">{error}</div>}
    {message&&<div className="success-box">{message}</div>}
    <section className="settlement-builder admin-card">
      <div><span className="section-label">Nova liquidação</span><p className="muted-text">Seleccione o agrodealer e o período. Só dias já fechados (até ontem, hora de Moçambique). Entram as vendas confirmadas ainda não liquidadas; paga-se ao agrodealer a parte do voucher.</p></div>
      <div className="settlement-form">
        <label><span className="form-label">Agrodealer</span><select className="input" value={dealerId} onChange={e=>setDealerId(e.target.value)}><option value="">Seleccionar agrodealer</option>{dealers.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></label>
        <label><span className="form-label">Data inicial</span><input className="input" type="date" value={start} max={yesterday} onChange={e=>setStart(e.target.value)}/></label>
        <label><span className="form-label">Data final</span><input className="input" type="date" value={end} max={yesterday} onChange={e=>setEnd(e.target.value)}/></label>
        <button className="primary-button" disabled={working||!dealerId} onClick={createSettlement}>{working?'A processar...':'Fechar e criar liquidação'}</button>
      </div>
    </section>
    <div className="admin-section-head"><div><span>Histórico de liquidações</span><small>{filtered.length} documento(s)</small></div>
      {isManager&&<div className="tx-period-presets settlement-tabs" role="group" aria-label="Mostrar">
        <button type="button" className={tab==='pending'?'active':''} onClick={()=>setTab('pending')}>Por aprovar ({pendingCount})</button>
        <button type="button" className={tab==='all'?'active':''} onClick={()=>setTab('all')}>Todas</button>
      </div>}
    </div>
    {loading?<div className="loading-card"><span className="loading-dot"/> A carregar liquidações...</div>:<div className="settlement-list">{filtered.map(s=><button className="settlement-row" key={s.id} onClick={()=>openSettlement(s)}><div><strong>{s.settlement_code}</strong><small>{s.agrodealer}{s.province?` (${s.province})`:''} · {s.period_start} a {s.period_end} · por {s.created_by??'—'}</small></div><div><strong>{money(s.payable_amount)}</strong><span className={'settlement-status '+s.status.toLowerCase()}>{statusLabel(s.status)}</span></div></button>)}{filtered.length===0&&<div className="recent-empty">{tab==='pending'?'Nenhuma reconciliação à espera de aprovação.':'Ainda não existem liquidações.'}</div>}</div>}
    {selected&&<section className="settlement-detail admin-card">
      <div className="settlement-detail-head"><div><span className="section-label">Documento de liquidação</span><h2>{selected.settlement_code}</h2><p>{selected.agrodealer} · {selected.period_start} a {selected.period_end} · <strong>{statusLabel(selected.status)}</strong></p></div><button className="quick-link" onClick={()=>setSelected(null)}>Fechar</button></div>
      <div className="settlement-kpis"><div><span>Transações</span><strong>{selected.transaction_count}</strong></div><div><span>Total após desconto</span><strong>{money(selected.gross_amount)}</strong></div><div><span>Voucher</span><strong>{money(selected.voucher_amount)}</strong></div><div><span>Copagamento</span><strong>{money(selected.copayment_amount)}</strong></div><div className="payable"><span>A pagar</span><strong>{money(selected.payable_amount)}</strong></div></div>
      <ul className="settlement-trail">
        <li>Criada por <strong>{selected.created_by??'—'}</strong> em {formatDateTime(selected.created_at)}</li>
        {selected.submitted_at&&<li>Enviada por <strong>{selected.submitted_by??'—'}</strong> em {formatDateTime(selected.submitted_at)}</li>}
        {selected.manager_reviewed_at&&<li>{selected.status==='MANAGER_RETURNED'?'Devolvida':'Aprovada'} pelo gestor <strong>{selected.manager_reviewed_by??'—'}</strong> em {formatDateTime(selected.manager_reviewed_at)}</li>}
        {selected.manager_notes&&<li>Motivo do gestor: {selected.manager_notes}</li>}
        {selected.status==='RETURNED'&&selected.notes&&<li>Motivo do Finance: {selected.notes}</li>}
      </ul>
      <div className="settlement-actions"><button className="secondary-button" onClick={generatePdf}>Gerar PDF</button>
        {selected.status==='RECONCILED'&&<button className="primary-button" disabled={working} onClick={()=>act('submit')}>{working?'A enviar...':isManager?'Enviar para Finance':'Enviar ao gestor MEAL'}</button>}
      </div>
      {selected.status==='PENDING_MANAGER'&&isManager&&<div className="settlement-review">
        <button className="primary-button" disabled={working} onClick={()=>act('approve')}>{working?'A processar...':'Aprovar e enviar para Finance'}</button>
        <label><span className="form-label">Motivo (para devolver ao oficial)</span><textarea className="input" rows={2} value={reason} onChange={e=>setReason(e.target.value)} placeholder="Ex.: período errado, vendas em falta"/></label>
        <button className="secondary-button danger" disabled={working} onClick={()=>act('return')}>Devolver ao oficial</button>
      </div>}
      <div className="admin-section-head"><div><span>Transações incluídas</span><small>{items.length} transação(ões) confirmadas</small></div></div>
      <div className="admin-table-card settlement-items">{items.map(r=><div className="admin-transaction-row" key={r.id}><div><strong>{r.transaction_code}</strong><small>{voucherCode(r.vouchers)} · {formatDateTime(r.created_at)}</small></div><div className="transaction-money"><strong>{money(r.total_amount)}</strong><small>Voucher {money(r.voucher_amount)} · Copagamento {money(r.copayment_amount)}</small></div><span className="badge">{({CONFIRMED:'Confirmada',REVERSED:'Anulada',PENDING:'Pendente',REJECTED:'Rejeitada'} as Record<string,string>)[r.status]??r.status}</span></div>)}</div>
    </section>}
  </main>
}

export default function SettlementsPage(){
  return <AdminGate roles={HOUSEHOLD_ROLES} deniedCode="SETTLEMENT_NOT_ALLOWED"><SettlementsScreen/></AdminGate>;
}
