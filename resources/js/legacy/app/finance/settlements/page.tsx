
import { useEffect, useMemo, useState } from 'react';
import { FinanceGate } from '../../components/finance-gate';
import { FinanceHeader } from '../../components/finance-header';
import { callRpc } from '../../../lib/supabase';
import { errorMessage } from '../../../lib/errors';
import { formatMT, formatDateTime } from '../../../lib/format';

type Settlement = {
  id:string; settlement_code:string; agrodealer_id:string; period_start:string; period_end:string;
  transaction_count:number; gross_amount:number; voucher_amount:number; copayment_amount:number;
  discount_amount:number; payable_amount:number; status:string; created_at:string;
  submitted_at:string|null; notes:string|null;
  agrodealers?:{name?:string}|{name?:string}[]|null;
};
type Tx = {
  id:string; total_amount:number; voucher_amount:number; copayment_amount:number;
  status:string; created_at:string;
  vouchers?:{voucher_code?:string}|{voucher_code?:string}[]|null
};

const dealer=(v:Settlement['agrodealers'])=>Array.isArray(v)?v[0]?.name??'Agrodealer':v?.name??'Agrodealer';
const voucher=(v:Tx['vouchers'])=>Array.isArray(v)?v[0]?.voucher_code??'Voucher':v?.voucher_code??'Voucher';
const money=(n:number)=>formatMT(Number(n||0));

const STATUS_LABEL:Record<string,string>={
  RECONCILED:'Não submetida',
  SUBMITTED_TO_FINANCE:'Aguardando validação',
  VALIDATED:'Validada',
  RETURNED:'Devolvida'
};

export default function FinanceSettlementsPage(){
  const [rows,setRows]=useState<Settlement[]>([]);
  const [selected,setSelected]=useState<Settlement|null>(null);
  const [items,setItems]=useState<Tx[]>([]);
  const [filter,setFilter]=useState('ALL');
  const [period,setPeriod]=useState('ALL');
  const [q,setQ]=useState('');
  const [reason,setReason]=useState('');
  const [loading,setLoading]=useState(true);
  const [working,setWorking]=useState(false);
  const [error,setError]=useState<string|null>(null);

  async function load(){
    setLoading(true); setError(null);
    try{ setRows(await callRpc<Settlement[]>('finance_list_settlements',{})); }
    catch(e){ setError(errorMessage(e instanceof Error?e:new Error(String(e)))); }
    setLoading(false);
  }

  useEffect(()=>{load()},[]);

  const periods=useMemo(()=>{
    const set=new Set(rows.map(r=>r.period_start.slice(0,7)));
    return Array.from(set).sort((a,b)=>b.localeCompare(a));
  },[rows]);

  const visible=useMemo(()=>rows.filter(r=>{
    const matchFilter=filter==='ALL'||r.status===filter;
    const matchPeriod=period==='ALL'||r.period_start.slice(0,7)===period;
    const term=q.trim().toLowerCase();
    const matchSearch=!term||r.settlement_code.toLowerCase().includes(term)||dealer(r.agrodealers).toLowerCase().includes(term);
    return matchFilter&&matchPeriod&&matchSearch;
  }),[rows,filter,period,q]);

  const awaiting=rows.filter(r=>r.status==='SUBMITTED_TO_FINANCE');
  const validated=rows.filter(r=>r.status==='VALIDATED');
  const returned=rows.filter(r=>r.status==='RETURNED');

  async function open(row:Settlement){
    setSelected(row);
    setReason('');
    setError(null);
    try{ setItems(await callRpc<Tx[]>('get_settlement_transactions',{p_settlement_id:row.id})); }
    catch(e){ setError(errorMessage(e instanceof Error?e:new Error(String(e)))); }
  }

  async function validate(){
    if(!selected)return;
    setWorking(true);setError(null);
    try{
      await callRpc('finance_validate_settlement',{p_settlement_id:selected.id});
      const updated={...selected,status:'VALIDATED'};
      setSelected(updated);
      setRows(prev=>prev.map(x=>x.id===updated.id?updated:x));
    }catch(e){setError(errorMessage(e instanceof Error?e:new Error(String(e))))}
    finally{setWorking(false)}
  }

  async function returnToMeal(){
    if(!selected)return;
    if(!reason.trim()){setError('Indique o motivo da devolução.');return}
    setWorking(true);setError(null);
    try{
      await callRpc('finance_return_settlement',{p_settlement_id:selected.id,p_reason:reason.trim()});
      const updated={...selected,status:'RETURNED',notes:reason.trim()};
      setSelected(updated);
      setRows(prev=>prev.map(x=>x.id===updated.id?updated:x));
    }catch(e){setError(errorMessage(e instanceof Error?e:new Error(String(e))))}
    finally{setWorking(false)}
  }

  async function pdf(){
    if(!selected)return;
    const {jsPDF}=await import('jspdf');
    const doc=new jsPDF({unit:'mm',format:'a4'});
    const W=210;
    doc.setFont('helvetica','bold');doc.setFontSize(17);doc.setTextColor(23,107,77);doc.text('VOUCHER LINK GCF',18,18);
    doc.setFontSize(13);doc.setTextColor(30,40,50);doc.text('MAPA DE RECONCILIAÇÃO',18,27);
    doc.setFont('helvetica','normal');doc.setFontSize(9);doc.setTextColor(90,100,110);doc.text('Documento recebido do MEAL para conferência financeira',18,34);
    doc.setDrawColor(210,220,215);doc.line(18,39,W-18,39);
    doc.setFontSize(9);doc.setTextColor(35,45,55);
    doc.text('Agrodealer:',18,48);doc.setFont('helvetica','bold');doc.text(dealer(selected.agrodealers),48,48);
    doc.setFont('helvetica','normal');doc.text('Referência:',18,55);doc.setFont('helvetica','bold');doc.text(selected.settlement_code,48,55);
    doc.setFont('helvetica','normal');doc.text('Período:',18,62);doc.setFont('helvetica','bold');doc.text(selected.period_start+' a '+selected.period_end,48,62);
    doc.setFont('helvetica','normal');let y=73;
    doc.setFillColor(234,246,240);doc.roundedRect(18,y,W-36,39,3,3,'F');
    doc.setFont('helvetica','bold');doc.setFontSize(9);doc.setTextColor(23,107,77);doc.text('RESUMO DA RECONCILIAÇÃO',24,y+8);
    doc.setTextColor(35,45,55);doc.setFontSize(10);
    doc.text('Transações',24,y+17);doc.text(String(selected.transaction_count),80,y+17);
    doc.text('Vendas',24,y+25);doc.text(money(selected.gross_amount),80,y+25);
    doc.text('Voucher',112,y+17);doc.text(money(selected.voucher_amount),112,y+25);
    doc.text('Copagamento',112,y+33);doc.text(money(selected.copayment_amount),166,y+33);
    doc.setFont('helvetica','bold');doc.text('VALOR DA RECONCILIAÇÃO',24,y+33);doc.text(money(selected.payable_amount),80,y+33);
    y+=50;
    doc.setFontSize(10);doc.setTextColor(23,107,77);doc.text('DETALHE DAS TRANSAÇÕES',18,y);y+=7;
    doc.setFont('helvetica','bold');doc.setFontSize(7);doc.setTextColor(80,90,100);
    doc.text('Data',18,y);doc.text('Voucher',50,y);doc.text('Valor',108,y);doc.text('Voucher',139,y);doc.text('Copagamento',169,y);y+=4;
    doc.setFont('helvetica','normal');doc.setTextColor(35,45,55);
    for(const r of items){
      if(y>278){doc.addPage();y=18;doc.setFont('helvetica','bold');doc.text('DETALHE DAS TRANSAÇÕES (continuação)',18,y);y+=8}
      doc.text(new Date(r.created_at).toLocaleDateString('pt-PT'),18,y);
      doc.text(voucher(r.vouchers).slice(0,18),50,y);
      doc.text(money(r.total_amount),108,y);
      doc.text(money(r.voucher_amount),139,y);
      doc.text(money(r.copayment_amount),169,y);y+=5;
    }
    doc.save(selected.settlement_code+'.pdf');
  }

  return <FinanceGate>
    <div className="finance-app finance-settlements-page">
      <FinanceHeader/>
      <main className="finance-main finance-settlements-main">
      <div className="admin-page-heading">
        <div>
          <div className="eyebrow">FINANCE · RECONCILIAÇÃO</div>
          <h1>Liquidações</h1>
          <p>Reconciliações recebidas do MEAL para conferência e validação.</p>
        </div>
      </div>

      {error&&<div className="error-box">{error}</div>}

      <div className="meal-metrics finance-metrics">
        <div className="meal-metric accent"><span>Aguardando validação</span><strong>{awaiting.length}</strong><small>Recebidas do MEAL</small></div>
        <div className="meal-metric"><span>Validadas</span><strong>{validated.length}</strong><small>Conferência concluída</small></div>
        <div className="meal-metric"><span>Devolvidas</span><strong>{returned.length}</strong><small>Aguardam correcção pelo MEAL</small></div>
        <div className="meal-metric"><span>Total</span><strong>{rows.length}</strong><small>Reconciliações registadas</small></div>
      </div>

      <div className="admin-filters finance-toolbar">
        <select className="input" value={period} onChange={e=>setPeriod(e.target.value)}>
          <option value="ALL">Todos os períodos</option>
          {periods.map(p=><option key={p} value={p}>{new Date(p+'-01T12:00:00').toLocaleDateString('pt-PT',{month:'long',year:'numeric'})}</option>)}
        </select>
        <input className="input" placeholder="Pesquisar por referência ou agrodealer..." value={q} onChange={e=>setQ(e.target.value)}/>
      </div>

      <div className="finance-tabs">
        {[
          ['ALL','Todas',rows.length],
          ['SUBMITTED_TO_FINANCE','Aguardando validação',awaiting.length],
          ['VALIDATED','Validadas',validated.length],
          ['RETURNED','Devolvidas',returned.length],
        ].map(([value,label,count])=>
          <button key={value} className={filter===value?'finance-tab active':'finance-tab'} onClick={()=>setFilter(value as string)}>
            {label}<span>{count}</span>
          </button>
        )}
      </div>

      {loading?<div className="loading-card"><span className="loading-dot"/> A carregar reconciliações...</div>:
        <div className="settlement-list finance-list">
          {visible.map(r=><button key={r.id} className="settlement-row finance-row" onClick={()=>open(r)}>
            <div>
              <strong>{r.settlement_code}</strong>
              <small>{dealer(r.agrodealers)} · {r.period_start} a {r.period_end}</small>
            </div>
            <div className="finance-row-middle">
              <span>{r.transaction_count} transações</span>
              <strong>{money(r.payable_amount)}</strong>
            </div>
            <div className="finance-row-end">
              <span className={'settlement-status '+r.status.toLowerCase()}>{STATUS_LABEL[r.status]??r.status}</span>
              <small>{r.submitted_at?formatDateTime(r.submitted_at):'Ainda não enviada'}</small>
            </div>
          </button>)}
          {visible.length===0&&<div className="recent-empty">Nenhuma reconciliação encontrada.</div>}
        </div>
      }

      {selected&&<section className="settlement-detail admin-card finance-detail">
        <div className="settlement-detail-head">
          <div>
            <button className="quick-link" onClick={()=>setSelected(null)}>← Voltar para a lista</button>
            <div className="detail-title-row"><h2>{selected.settlement_code}</h2><span className={'settlement-status '+selected.status.toLowerCase()}>{STATUS_LABEL[selected.status]??selected.status}</span></div>
            <p>Revisar o mapa de vendas reconciliado pelo MEAL</p>
          </div>
          <button className="secondary-button" onClick={pdf}>Baixar mapa PDF</button>
        </div>

        <div className="finance-detail-grid">
          <div className="admin-card finance-summary-card">
            <span className="section-label">Informações gerais</span>
            <div className="finance-info-list">
              <div><span>Agrodealer</span><strong>{dealer(selected.agrodealers)}</strong></div>
              <div><span>Período</span><strong>{selected.period_start} · {selected.period_end}</strong></div>
              <div><span>Número de transações</span><strong>{selected.transaction_count}</strong></div>
              <div><span>Valor bruto das vendas</span><strong>{money(selected.gross_amount)}</strong></div>
              <div><span>Valor em vouchers</span><strong>{money(selected.voucher_amount)}</strong></div>
              <div><span>Copagamento</span><strong>{money(selected.copayment_amount)}</strong></div>
              <div className="finance-payable-row"><span>Valor da reconciliação</span><strong>{money(selected.payable_amount)}</strong></div>
            </div>
          </div>

          <div className="finance-decision-card finance-action-card">
            <span className="section-label">Acções Finance</span>
            {selected.status==='SUBMITTED_TO_FINANCE'?
              <>
                <p className="muted-text">Confirme que recebeu e validou a reconciliação enviada pelo MEAL.</p>
                <button className="primary-button" disabled={working} onClick={validate}>{working?'A validar...':'✓ Recebido / Validado'}</button>
                <div className="finance-return-divider"><span>ou</span></div>
                <label><span className="form-label">Observação para o MEAL</span><textarea className="input" rows={4} placeholder="Indique o que precisa de ser corrigido..." value={reason} onChange={e=>setReason(e.target.value)}/></label>
                <button className="secondary-button danger-action" disabled={working||!reason.trim()} onClick={returnToMeal}>↩ Devolver ao MEAL</button>
              </>:
              selected.status==='VALIDATED'?
                <div className="success-box"><strong>✓ Reconciliação validada</strong><br/><span>Finance confirmou o recebimento e a validação do documento.</span></div>:
                selected.status==='RETURNED'?
                  <div className="error-box"><strong>Reconciliação devolvida ao MEAL.</strong><br/><span>{selected.notes}</span></div>:
                  <div className="info-box">Esta reconciliação ainda não foi submetida pelo MEAL.</div>
            }
          </div>
        </div>

        <div className="admin-section-head"><div><span>Transações incluídas ({items.length})</span><small>Apenas transações confirmadas incluídas nesta reconciliação</small></div></div>
        <div className="admin-table-card settlement-items">
          {items.map(r=><div className="admin-transaction-row finance-tx-row" key={r.id}>
            <div><strong>{voucher(r.vouchers)}</strong><small>{formatDateTime(r.created_at)} · {r.id.slice(0,8)}</small></div>
            <div className="transaction-money"><strong>{money(r.total_amount)}</strong><small>Voucher {money(r.voucher_amount)} · Copagamento {money(r.copayment_amount)}</small></div>
            <span className="badge">{r.status}</span>
          </div>)}
        </div>
      </section>}
      </main>
    </div>
  </FinanceGate>;
}
