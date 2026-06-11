import { useEffect, useState } from 'react';
import { ImageOff, Loader2, Map, MapPin, RefreshCw, Router } from 'lucide-react';
import { getRouters } from '../utils/api';
import { useSite } from '../context/SiteContext';

interface Accesspoint {
  id: number | null;
  name: string;
  ip_address?: string | null;
  location?: string | null;
  latitude?: string | number | null;
  longitude?: string | number | null;
  front_photo_url?: string | null;
  back_photo_url?: string | null;
  google_maps_url?: string | null;
  status?: string | null;
  last_seen?: string | null;
  managed_by_site?: boolean;
}

export function Routers() {
  const { selectedSite } = useSite();
  const [accesspoints, setAccesspoints] = useState<Accesspoint[]>([]);
  const [tab, setTab] = useState<'list' | 'map'>('list');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const data = await getRouters();
      setAccesspoints(Array.isArray(data) ? data.filter((item: Accesspoint) => !item.managed_by_site && item.id !== null) : []);
    } catch (err: any) {
      setError(err.message || 'Failed to load accesspoints.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
    const interval = window.setInterval(load, 15000);

    return () => window.clearInterval(interval);
  }, [selectedSite?.id]);

  if (loading && accesspoints.length === 0) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
            <Router className="w-7 h-7 text-primary" />
            Accesspoints
          </h1>
          <p className="text-muted-foreground mt-1">Devices added from the ONLIFI Installer app. Uptime Kuma will provide live status once connected.</p>
        </div>
        <button onClick={load} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {error && <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      <div className="bg-card border border-border rounded-lg overflow-hidden">
        <div className="flex flex-wrap gap-2 p-4 border-b border-border">
          <button onClick={() => setTab('list')} className={`inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm ${tab === 'list' ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-muted'}`}>
            <Router className="w-4 h-4" />
            List
          </button>
          <button onClick={() => setTab('map')} className={`inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm ${tab === 'map' ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-muted'}`}>
            <Map className="w-4 h-4" />
            Map
          </button>
        </div>

        {tab === 'list' ? <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-muted-foreground border-b border-border">
              <tr>
                <th className="px-5 py-3 font-medium">Router</th>
                <th className="px-5 py-3 font-medium">Image</th>
                <th className="px-5 py-3 font-medium">Location</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium">Last Seen</th>
              </tr>
            </thead>
            <tbody>
              {accesspoints.length === 0 ? (
                <tr><td colSpan={5} className="px-5 py-8 text-center text-muted-foreground">No accesspoints have been submitted from the installer app yet.</td></tr>
              ) : accesspoints.map((accesspoint, index) => {
                const isOnline = accesspoint.status === 'online';
                const imageUrl = accesspoint.front_photo_url || accesspoint.back_photo_url;
                return (
                  <tr key={accesspoint.id ?? `${accesspoint.name}-${index}`} className="border-b border-border/70 last:border-0">
                    <td className="px-5 py-3">
                      <p className="font-medium text-card-foreground">{accesspoint.name}</p>
                      <p className="text-xs text-muted-foreground font-mono">{accesspoint.ip_address || 'No IP assigned'}</p>
                    </td>
                    <td className="px-5 py-3">
                      {imageUrl ? (
                        <img src={imageUrl} alt={accesspoint.name} className="h-14 w-20 rounded-md object-cover border border-border" />
                      ) : (
                        <div className="h-14 w-20 rounded-md border border-border bg-muted grid place-items-center text-muted-foreground">
                          <ImageOff className="w-5 h-5" />
                        </div>
                      )}
                    </td>
                    <td className="px-5 py-3">
                      <p className="text-card-foreground">{accesspoint.location || '-'}</p>
                      {accesspoint.google_maps_url && (
                        <a href={accesspoint.google_maps_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-primary hover:underline">
                          <MapPin className="w-3 h-3" />
                          Google Maps
                        </a>
                      )}
                    </td>
                    <td className="px-5 py-3">
                      <span className={`inline-flex px-2 py-1 rounded-md text-xs font-medium ${isOnline ? 'bg-emerald-500/10 text-emerald-600' : 'bg-muted text-muted-foreground'}`}>
                        {isOnline ? 'ONLINE' : 'OFFLINE'}
                      </span>
                    </td>
                    <td className="px-5 py-3 whitespace-nowrap">
                      {isOnline ? 'ONLINE' : accesspoint.last_seen ? new Date(accesspoint.last_seen).toLocaleString() : '-'}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div> : <AccesspointMap accesspoints={accesspoints} />}
      </div>
    </div>
  );
}

function AccesspointMap({ accesspoints }: { accesspoints: Accesspoint[] }) {
  const mapped = accesspoints
    .map((accesspoint) => ({
      ...accesspoint,
      lat: Number(accesspoint.latitude),
      lng: Number(accesspoint.longitude),
    }))
    .filter((accesspoint) => Number.isFinite(accesspoint.lat) && Number.isFinite(accesspoint.lng));

  if (mapped.length === 0) {
    return (
      <div className="p-8 text-center text-muted-foreground">
        No installed accesspoints have valid coordinates yet.
      </div>
    );
  }

  const lats = mapped.map((accesspoint) => accesspoint.lat);
  const lngs = mapped.map((accesspoint) => accesspoint.lng);
  const minLat = Math.min(...lats);
  const maxLat = Math.max(...lats);
  const minLng = Math.min(...lngs);
  const maxLng = Math.max(...lngs);
  const latRange = Math.max(maxLat - minLat, 0.0001);
  const lngRange = Math.max(maxLng - minLng, 0.0001);
  const allMapsUrl = mapped.length === 1
    ? googleMapsUrl(mapped[0].lat, mapped[0].lng)
    : `https://www.google.com/maps/dir/?api=1&travelmode=driving&origin=${mapped[0].lat},${mapped[0].lng}&destination=${mapped[mapped.length - 1].lat},${mapped[mapped.length - 1].lng}&waypoints=${mapped.slice(1, -1).map((point) => `${point.lat},${point.lng}`).join('|')}`;

  return (
    <div className="p-5 space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h2 className="font-semibold text-card-foreground">Accesspoint Map</h2>
          <p className="text-sm text-muted-foreground">Router photos mark the captured GPS coordinates from the installer app.</p>
        </div>
        <a href={allMapsUrl} target="_blank" rel="noreferrer" className="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-border hover:bg-muted text-sm">
          <MapPin className="w-4 h-4" />
          Open in Google Maps
        </a>
      </div>

      <div className="relative h-[520px] rounded-lg overflow-hidden border border-border bg-[#eef3ed]">
        <div className="absolute inset-0 opacity-70" style={{
          backgroundImage: 'linear-gradient(rgba(30,64,55,.12) 1px, transparent 1px), linear-gradient(90deg, rgba(30,64,55,.12) 1px, transparent 1px)',
          backgroundSize: '42px 42px',
        }} />
        <div className="absolute left-4 top-4 rounded-md bg-card/95 border border-border px-3 py-2 text-xs text-muted-foreground shadow-sm">
          {mapped.length} installed {mapped.length === 1 ? 'accesspoint' : 'accesspoints'}
        </div>

        {mapped.map((accesspoint) => {
          const x = 8 + ((accesspoint.lng - minLng) / lngRange) * 84;
          const y = 8 + ((maxLat - accesspoint.lat) / latRange) * 84;
          const imageUrl = accesspoint.front_photo_url || accesspoint.back_photo_url;

          return (
            <a
              key={accesspoint.id ?? `${accesspoint.name}-${accesspoint.ip_address}`}
              href={googleMapsUrl(accesspoint.lat, accesspoint.lng)}
              target="_blank"
              rel="noreferrer"
              title={`${accesspoint.name} - ${accesspoint.ip_address || ''}`}
              className="absolute -translate-x-1/2 -translate-y-full group"
              style={{ left: `${x}%`, top: `${y}%` }}
            >
              <div className="relative">
                <div className="h-12 w-12 rounded-full border-2 border-white bg-card shadow-lg overflow-hidden">
                  {imageUrl ? (
                    <img src={imageUrl} alt={accesspoint.name} className="h-full w-full object-cover" />
                  ) : (
                    <div className="h-full w-full grid place-items-center bg-muted text-muted-foreground">
                      <Router className="w-5 h-5" />
                    </div>
                  )}
                </div>
                <div className="mx-auto h-4 w-4 rotate-45 -mt-2 bg-card border-b border-r border-white shadow-sm" />
                <div className="absolute left-1/2 top-full mt-2 hidden min-w-44 -translate-x-1/2 rounded-md border border-border bg-card p-2 text-xs shadow-lg group-hover:block">
                  <p className="font-medium text-card-foreground">{accesspoint.name}</p>
                  <p className="text-muted-foreground">{accesspoint.ip_address || 'No IP assigned'}</p>
                  <p className="text-muted-foreground">{accesspoint.lat.toFixed(6)}, {accesspoint.lng.toFixed(6)}</p>
                </div>
              </div>
            </a>
          );
        })}
      </div>
    </div>
  );
}

function googleMapsUrl(lat: number, lng: number) {
  return `https://www.google.com/maps?q=${lat},${lng}`;
}
