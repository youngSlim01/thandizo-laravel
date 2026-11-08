
import { useMemo, useState } from 'react';
import { MOZ_MAP_HEIGHT, MOZ_MAP_WIDTH, MOZ_PROVINCES, mapProvinceName } from '@/lib/mozambique-map';
import { formatMT } from '@/lib/format';

// Beneficiários por província no mapa de Moçambique. Só as províncias com
// beneficiários têm cor (um verde, claro → escuro = poucos → muitos); as
// outras ficam cinzentas. A lista ao lado dá os números exactos, para nada
// depender só da cor.

export type ProvinceFigure = { province: string; households: number; with_card: number; transactions: number; total_amount: number };

// Ordinal green ramp, validated (dataviz validate_palette --ordinal): the
// lightest step still stands out from the surface and from the grey.
const RAMP = ['#6fbf98', '#4aa77d', '#2e8b62', '#1c6e4b', '#0f5238'];
const EMPTY = '#eceff1';

function niceStep(max: number) {
  const raw = Math.max(1, Math.ceil(max / RAMP.length));
  const mag = 10 ** Math.floor(Math.log10(raw));
  const nice = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((v) => v >= raw) ?? raw;
  return Math.max(1, Math.round(nice));
}

export function MozambiqueMap({ figures, withoutProvince, period = 'no mês' }: { figures: ProvinceFigure[]; withoutProvince: number; period?: string }) {
  const [hover, setHover] = useState<string | null>(null);

  const { byMap, outside, step } = useMemo(() => {
    const byMap = new Map<string, ProvinceFigure>();
    const outside: ProvinceFigure[] = [];
    for (const f of figures) {
      const name = mapProvinceName(f.province);
      if (!name) { outside.push(f); continue; }
      const prev = byMap.get(name);
      byMap.set(name, prev ? {
        province: name, households: prev.households + f.households, with_card: prev.with_card + f.with_card,
        transactions: prev.transactions + f.transactions, total_amount: Number(prev.total_amount) + Number(f.total_amount),
      } : { ...f, province: name });
    }
    const max = Math.max(0, ...Array.from(byMap.values()).map((f) => f.households));
    return { byMap, outside, step: niceStep(max) };
  }, [figures]);

  const bucket = (n: number) => Math.min(RAMP.length - 1, Math.floor((n - 1) / step));
  const fill = (name: string) => {
    const f = byMap.get(name);
    return f && f.households > 0 ? RAMP[bucket(f.households)] : EMPTY;
  };
  const listed = Array.from(byMap.values()).filter((f) => f.households > 0).sort((a, b) => b.households - a.households);
  const shown = hover ? byMap.get(hover) : null;
  const shownGeo = hover ? MOZ_PROVINCES.find((p) => p.name === hover) : null;

  return (
    <div className="moz-map">
      <div className="moz-map-figure">
        <svg viewBox={`0 0 ${MOZ_MAP_WIDTH} ${MOZ_MAP_HEIGHT}`} role="img" aria-label="Mapa de Moçambique com o número de beneficiários por província">
          {MOZ_PROVINCES.map((p) => {
            const f = byMap.get(p.name);
            const active = !!f && f.households > 0;
            return (
              <path key={p.name} d={p.d} fill={fill(p.name)}
                className={`moz-province${active ? ' active' : ''}${hover === p.name ? ' hover' : ''}`}
                onMouseEnter={() => setHover(p.name)} onMouseLeave={() => setHover((h) => (h === p.name ? null : h))}
                onClick={() => setHover(p.name)}>
                <title>{active ? `${p.name}: ${f!.households.toLocaleString('pt-PT')} beneficiários` : `${p.name}: sem beneficiários`}</title>
              </path>
            );
          })}
          {MOZ_PROVINCES.filter((p) => (byMap.get(p.name)?.households ?? 0) > 0 && p.name !== 'Maputo Cidade').map((p) => (
            <text key={p.name} x={p.cx} y={p.cy} className="moz-label" textAnchor="middle">
              <tspan x={p.cx} dy="-0.2em">{p.name}</tspan>
              <tspan x={p.cx} dy="1.2em" className="moz-label-value">{byMap.get(p.name)!.households.toLocaleString('pt-PT')}</tspan>
            </text>
          ))}
        </svg>
        {shown && shownGeo && (
          <div className="moz-tooltip" style={{ left: `${(shownGeo.cx / MOZ_MAP_WIDTH) * 100}%`, top: `${(shownGeo.cy / MOZ_MAP_HEIGHT) * 100}%` }}>
            <strong>{shown.province}</strong>
            <span>{shown.households.toLocaleString('pt-PT')} beneficiários</span>
            <span>{shown.with_card.toLocaleString('pt-PT')} com cartão entregue</span>
            <span>{shown.transactions.toLocaleString('pt-PT')} compras {period} · {formatMT(shown.total_amount)}</span>
          </div>
        )}
      </div>

      <div className="moz-map-side">
        <div className="moz-legend" aria-label="Legenda">
          <span>Nº de beneficiários</span>
          <div className="moz-legend-steps">
            {RAMP.map((c, i) => (
              <div key={c}><i style={{ background: c }} /><small>{i === RAMP.length - 1 ? `≥ ${(i * step + 1).toLocaleString('pt-PT')}` : `${(i * step + 1).toLocaleString('pt-PT')}–${((i + 1) * step).toLocaleString('pt-PT')}`}</small></div>
            ))}
            <div><i style={{ background: EMPTY }} className="moz-legend-empty" /><small>sem beneficiários</small></div>
          </div>
        </div>
        <ul className="moz-list">
          {listed.map((f) => (
            <li key={f.province} className={hover === f.province ? 'hover' : ''}
              onMouseEnter={() => setHover(f.province)} onMouseLeave={() => setHover(null)}>
              <i style={{ background: fill(f.province) }} />
              <span>{f.province}</span>
              <strong>{f.households.toLocaleString('pt-PT')}</strong>
              <small>{f.with_card.toLocaleString('pt-PT')} com cartão</small>
            </li>
          ))}
          {listed.length === 0 && <li className="moz-list-empty">Ainda não há beneficiários com província.</li>}
        </ul>
        {(outside.length > 0 || withoutProvince > 0) && (
          <p className="moz-note">
            Fora do mapa:{' '}
            {[...outside.map((f) => `${f.province} (${f.households.toLocaleString('pt-PT')})`),
              ...(withoutProvince > 0 ? [`sem província (${withoutProvince.toLocaleString('pt-PT')})`] : [])].join(' · ')}
          </p>
        )}
      </div>
    </div>
  );
}
