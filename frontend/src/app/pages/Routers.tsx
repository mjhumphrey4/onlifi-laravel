import { useEffect, useState } from 'react';
import { ImageOff, Loader2, MapPin, RefreshCw, Router } from 'lucide-react';
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
        <div className="overflow-x-auto">
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
        </div>
      </div>
    </div>
  );
}
