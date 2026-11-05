import { useEffect, useMemo, useState } from 'react';
import { requestJson } from '@/lib/http';

// Cascading location filter: Província → Distrito → Posto → Localidade →
// Bairro, do Laravel (HouseholdSearchService::geography, com o número de
// agregados activos de cada lugar). Escolher um nível limpa os de baixo.

type Place = { id: string; name: string; households: number };
export type Geography = {
  provinces: Place[];
  districts: (Place & { province_id: string })[];
  administrative_posts: (Place & { district_id: string })[];
  localities: (Place & { administrative_post_id: string })[];
  communities: (Place & { locality_id: string | null; district_id: string })[];
};

export type GeoValue = {
  province_id?: string; district_id?: string; administrative_post_id?: string; locality_id?: string; community_id?: string;
};

export const GEO_LEVELS = [
  { key: 'province_id', label: 'Província' },
  { key: 'district_id', label: 'Distrito' },
  { key: 'administrative_post_id', label: 'Posto administrativo' },
  { key: 'locality_id', label: 'Localidade' },
  { key: 'community_id', label: 'Bairro / povoação' },
] as const;

export function useGeography() {
  const [geo, setGeo] = useState<Geography | null>(null);
  useEffect(() => { requestJson<Geography>('GET', '/admin/households/geography').then(setGeo).catch(() => setGeo(null)); }, []);
  return geo;
}

// Name of the most specific place chosen, for titles ("Bairro X, Mutarara").
export function geoTitle(geo: Geography | null, v: GeoValue): string {
  if (!geo) return '';
  const name = (list: Place[], id?: string) => list.find((p) => p.id === id)?.name;
  return [name(geo.communities, v.community_id), name(geo.localities, v.locality_id), name(geo.administrative_posts, v.administrative_post_id),
    name(geo.districts, v.district_id), name(geo.provinces, v.province_id)].filter(Boolean).join(', ');
}

export function GeoFilter({ geo, value, onChange, hideEmpty = true, idPrefix = 'geo' }: {
  geo: Geography | null; value: GeoValue; onChange: (v: GeoValue) => void; hideEmpty?: boolean; idPrefix?: string;
}) {
  const options = useMemo(() => {
    if (!geo) return null;
    const keep = <T extends Place>(list: T[]) => (hideEmpty ? list.filter((p) => p.households > 0) : list);
    return {
      province_id: keep(geo.provinces),
      district_id: keep(geo.districts.filter((d) => !value.province_id || d.province_id === value.province_id)),
      administrative_post_id: keep(geo.administrative_posts.filter((a) => !value.district_id || a.district_id === value.district_id)),
      locality_id: keep(geo.localities.filter((l) => !value.administrative_post_id || l.administrative_post_id === value.administrative_post_id)),
      community_id: keep(geo.communities.filter((c) => (!value.locality_id || c.locality_id === value.locality_id)
        && (!value.district_id || c.district_id === value.district_id))),
    };
  }, [geo, value, hideEmpty]);

  function choose(level: number, id: string) {
    const next: GeoValue = {};
    GEO_LEVELS.forEach((l, i) => {
      if (i < level) next[l.key] = value[l.key];
      if (i === level && id) next[l.key] = id;
    });
    onChange(next);
  }

  return (
    <div className="geo-filter">
      {GEO_LEVELS.map((l, i) => (
        <select key={l.key} id={`${idPrefix}-${l.key}`} className="input" aria-label={l.label} value={value[l.key] ?? ''}
          disabled={!options} onChange={(e) => choose(i, e.target.value)}>
          <option value="">{i === 0 ? 'Todas as províncias' : `${l.label}: todos`}</option>
          {(options?.[l.key] ?? []).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.households})</option>)}
        </select>
      ))}
    </div>
  );
}
