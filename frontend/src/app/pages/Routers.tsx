import { useEffect, useRef, useState } from 'react';
import { ExternalLink, ImageOff, Loader2, Map, MapPin, Minus, Plus, RefreshCw, Router, X } from 'lucide-react';
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
  comment?: string | null;
  google_maps_url?: string | null;
  status?: string | null;
  last_seen?: string | null;
  managed_by_site?: boolean;
  installer_submission_id?: number | string | null;
}

interface PreviewImage {
  label: string;
  url: string;
}

interface ImagePreview {
  title: string;
  images: PreviewImage[];
}

export function Routers() {
  const { selectedSite } = useSite();
  const [accesspoints, setAccesspoints] = useState<Accesspoint[]>([]);
  const [tab, setTab] = useState<'list' | 'map'>('list');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [preview, setPreview] = useState<ImagePreview | null>(null);

  const previewImagesFor = (accesspoint: Accesspoint): PreviewImage[] => [
    accesspoint.front_photo_url ? { label: 'Front photo', url: accesspoint.front_photo_url } : null,
    accesspoint.back_photo_url ? { label: 'Back photo', url: accesspoint.back_photo_url } : null,
  ].filter(Boolean) as PreviewImage[];

  const openImagePreview = (accesspoint: Accesspoint) => {
    const images = previewImagesFor(accesspoint);
    if (images.length === 0) return;

    setPreview({ title: accesspoint.name, images });
  };

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const data = await getRouters();
      setAccesspoints(Array.isArray(data) ? data.filter((item: Accesspoint) => !item.managed_by_site && item.id !== null && Boolean(item.installer_submission_id)) : []);
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

      <div className="rounded-lg border border-border bg-card p-4">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-sm font-medium text-card-foreground">Installer Device IP Range</p>
            <p className="text-xs text-muted-foreground">Use unique addresses from this admin-assigned range when adding accesspoints.</p>
          </div>
          <code className="w-fit rounded-md border border-border bg-muted px-3 py-2 text-sm font-semibold text-card-foreground">
            {selectedSite?.assigned_device_ip_range || 'Not assigned'}
          </code>
        </div>
      </div>

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
                <th className="px-5 py-3 font-medium">Comment</th>
                <th className="px-5 py-3 font-medium">Coordinates</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium">Last Seen</th>
              </tr>
            </thead>
            <tbody>
              {accesspoints.length === 0 ? (
                <tr><td colSpan={6} className="px-5 py-8 text-center text-muted-foreground">No accesspoints have been submitted from the installer app yet.</td></tr>
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
                        <button
                          type="button"
                          onClick={() => openImagePreview(accesspoint)}
                          className="group h-14 w-20 rounded-md overflow-hidden border border-border bg-muted focus:outline-none focus:ring-2 focus:ring-primary"
                          title="Preview accesspoint photos"
                        >
                          <img src={imageUrl} alt={accesspoint.name} className="h-full w-full object-cover transition-transform group-hover:scale-105" />
                        </button>
                      ) : (
                        <div className="h-14 w-20 rounded-md border border-border bg-muted grid place-items-center text-muted-foreground">
                          <ImageOff className="w-5 h-5" />
                        </div>
                      )}
                    </td>
                    <td className="px-5 py-3">
                      <div className="max-w-xs rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-card-foreground">
                        {accesspoint.comment || accesspoint.location || '-'}
                      </div>
                    </td>
                    <td className="px-5 py-3">
                      <CoordinateLink accesspoint={accesspoint} />
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
        </div> : <AccesspointMap accesspoints={accesspoints} onPreview={openImagePreview} />}
      </div>

      {preview && (
        <ImagePreviewDialog
          title={preview.title}
          images={preview.images}
          onClose={() => setPreview(null)}
        />
      )}
    </div>
  );
}

function CoordinateLink({ accesspoint }: { accesspoint: Accesspoint }) {
  const lat = Number(accesspoint.latitude);
  const lng = Number(accesspoint.longitude);

  if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
    return <span className="text-muted-foreground">-</span>;
  }

  return (
    <a
      href={googleMapsUrl(lat, lng)}
      target="_blank"
      rel="noreferrer"
      className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 font-mono text-xs text-primary hover:bg-muted"
      title="Open exact coordinates in Google Maps"
    >
      <MapPin className="w-3 h-3" />
      {lat.toFixed(6)}, {lng.toFixed(6)}
    </a>
  );
}

function AccesspointMap({ accesspoints, onPreview }: { accesspoints: Accesspoint[]; onPreview: (accesspoint: Accesspoint) => void }) {
  const mapRef = useRef<HTMLDivElement | null>(null);
  const [viewportWidth, setViewportWidth] = useState(900);
  const [zoomOffset, setZoomOffset] = useState(0);
  const mapped = accesspoints
    .map((accesspoint) => ({
      ...accesspoint,
      lat: Number(accesspoint.latitude),
      lng: Number(accesspoint.longitude),
    }))
    .filter((accesspoint) => Number.isFinite(accesspoint.lat) && Number.isFinite(accesspoint.lng));

  useEffect(() => {
    const element = mapRef.current;
    if (!element) return;

    const updateWidth = () => setViewportWidth(Math.max(320, Math.round(element.clientWidth)));
    updateWidth();

    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', updateWidth);
      return () => window.removeEventListener('resize', updateWidth);
    }

    const observer = new ResizeObserver(updateWidth);
    observer.observe(element);

    return () => observer.disconnect();
  }, []);

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
  const allMapsUrl = mapped.length === 1
    ? googleMapsUrl(mapped[0].lat, mapped[0].lng)
    : `https://www.google.com/maps/dir/?api=1&travelmode=driving&origin=${mapped[0].lat},${mapped[0].lng}&destination=${mapped[mapped.length - 1].lat},${mapped[mapped.length - 1].lng}&waypoints=${mapped.slice(1, -1).map((point) => `${point.lat},${point.lng}`).join('|')}`;
  const centerLat = (minLat + maxLat) / 2;
  const centerLng = (minLng + maxLng) / 2;
  const baseZoom = Math.min(18, mapZoomForBounds(minLat, maxLat, minLng, maxLng) + 1);
  const zoom = Math.max(3, Math.min(19, baseZoom + zoomOffset));
  const center = latLngToWorldPixel(centerLat, centerLng, zoom);
  const width = viewportWidth;
  const height = 520;
  const tileSize = 256;
  const startTileX = Math.floor((center.x - width / 2) / tileSize);
  const endTileX = Math.floor((center.x + width / 2) / tileSize);
  const startTileY = Math.floor((center.y - height / 2) / tileSize);
  const endTileY = Math.floor((center.y + height / 2) / tileSize);
  const tileCount = 2 ** zoom;
  const tiles = [];

  for (let x = startTileX; x <= endTileX; x++) {
    for (let y = startTileY; y <= endTileY; y++) {
      if (y < 0 || y >= tileCount) continue;
      const wrappedX = ((x % tileCount) + tileCount) % tileCount;
      tiles.push({
        key: `${x}-${y}`,
        url: `https://tile.openstreetmap.org/${zoom}/${wrappedX}/${y}.png`,
        left: x * tileSize - (center.x - width / 2),
        top: y * tileSize - (center.y - height / 2),
      });
    }
  }

  return (
    <div className="p-5 space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h2 className="font-semibold text-card-foreground">Accesspoint Map</h2>
          <p className="text-sm text-muted-foreground">Installed devices are pinned from their captured GPS coordinates.</p>
        </div>
        <a href={allMapsUrl} target="_blank" rel="noreferrer" className="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-border hover:bg-muted text-sm">
          <MapPin className="w-4 h-4" />
          Open in Google Maps
        </a>
      </div>

      <div ref={mapRef} className="relative h-[520px] rounded-lg overflow-hidden border border-border bg-muted">
        {tiles.map((tile) => (
          <img
            key={tile.key}
            src={tile.url}
            alt=""
            className="absolute h-64 w-64 select-none"
            draggable={false}
            style={{ left: tile.left, top: tile.top }}
          />
        ))}
        <div className="absolute inset-0 bg-gradient-to-b from-background/5 via-transparent to-background/10 pointer-events-none" />
        <div className="absolute left-4 top-4 rounded-md bg-card/95 border border-border px-3 py-2 text-xs text-muted-foreground shadow-sm">
          {mapped.length} installed {mapped.length === 1 ? 'accesspoint' : 'accesspoints'}
        </div>
        <div className="absolute right-4 top-4 overflow-hidden rounded-md border border-border bg-card/95 shadow-sm">
          <button
            type="button"
            onClick={() => setZoomOffset((value) => Math.min(19 - baseZoom, value + 1))}
            className="grid h-9 w-9 place-items-center border-b border-border hover:bg-muted"
            aria-label="Zoom in"
            title="Zoom in"
          >
            <Plus className="h-4 w-4" />
          </button>
          <button
            type="button"
            onClick={() => setZoomOffset((value) => Math.max(3 - baseZoom, value - 1))}
            className="grid h-9 w-9 place-items-center border-b border-border hover:bg-muted"
            aria-label="Zoom out"
            title="Zoom out"
          >
            <Minus className="h-4 w-4" />
          </button>
          <button
            type="button"
            onClick={() => setZoomOffset(0)}
            className="grid h-9 w-9 place-items-center text-[10px] font-semibold text-muted-foreground hover:bg-muted"
            aria-label="Reset zoom"
            title="Reset zoom"
          >
            1x
          </button>
        </div>

        {mapped.map((accesspoint) => {
          const point = latLngToWorldPixel(accesspoint.lat, accesspoint.lng, zoom);
          const x = point.x - (center.x - width / 2);
          const y = point.y - (center.y - height / 2);
          const imageUrl = accesspoint.front_photo_url || accesspoint.back_photo_url;

          return (
            <div
              key={accesspoint.id ?? `${accesspoint.name}-${accesspoint.ip_address}`}
              title={`${accesspoint.name} - ${accesspoint.ip_address || ''}`}
              className="absolute -translate-x-1/2 -translate-y-full group"
              style={{ left: x, top: y }}
            >
              <div className="relative">
                <button
                  type="button"
                  onClick={() => imageUrl ? onPreview(accesspoint) : undefined}
                  className="h-12 w-12 rounded-full border-2 border-white bg-card shadow-lg overflow-hidden focus:outline-none focus:ring-2 focus:ring-primary"
                  title={imageUrl ? 'Preview accesspoint photos' : 'Accesspoint location'}
                >
                  {imageUrl ? (
                    <img src={imageUrl} alt={accesspoint.name} className="h-full w-full object-cover" />
                  ) : (
                    <div className="h-full w-full grid place-items-center bg-muted text-muted-foreground">
                      <Router className="w-5 h-5" />
                    </div>
                  )}
                </button>
                <div className="mx-auto h-4 w-4 rotate-45 -mt-2 bg-card border-b border-r border-white shadow-sm" />
                <div className="absolute left-1/2 top-full mt-2 hidden min-w-44 -translate-x-1/2 rounded-md border border-border bg-card p-2 text-xs shadow-lg group-hover:block">
                  <p className="font-medium text-card-foreground">{accesspoint.name}</p>
                  <p className="text-muted-foreground">{accesspoint.ip_address || 'No IP assigned'}</p>
                  <a href={googleMapsUrl(accesspoint.lat, accesspoint.lng)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-primary hover:underline">
                    {accesspoint.lat.toFixed(6)}, {accesspoint.lng.toFixed(6)}
                    <ExternalLink className="w-3 h-3" />
                  </a>
                </div>
              </div>
            </div>
          );
        })}
        <div className="absolute bottom-3 right-3 rounded bg-card/95 px-2 py-1 text-[11px] text-muted-foreground shadow-sm">
          Map tiles (c) OpenStreetMap contributors
        </div>
      </div>
    </div>
  );
}

function ImagePreviewDialog({ title, images, onClose }: { title: string; images: PreviewImage[]; onClose: () => void }) {
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/70 p-4" role="dialog" aria-modal="true">
      <div className="w-full max-w-6xl overflow-hidden rounded-lg border border-border bg-card shadow-2xl">
        <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
          <div>
            <p className="font-medium text-card-foreground">{title}</p>
            <p className="text-xs text-muted-foreground">{images.length} uploaded {images.length === 1 ? 'photo' : 'photos'}</p>
          </div>
          <button type="button" onClick={onClose} className="rounded-md border border-border p-2 hover:bg-muted" aria-label="Close image preview">
            <X className="h-4 w-4" />
          </button>
        </div>
        <div className="max-h-[78vh] overflow-auto bg-black p-4">
          <div className={`grid gap-4 ${images.length > 1 ? 'lg:grid-cols-2' : 'grid-cols-1'}`}>
            {images.map((image) => (
              <div key={image.url} className="overflow-hidden rounded-lg border border-white/15 bg-zinc-950">
                <div className="flex items-center justify-between gap-3 border-b border-white/10 px-3 py-2">
                  <p className="text-sm font-medium text-white">{image.label}</p>
                  <a href={image.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-white/80 hover:text-white">
                    Open
                    <ExternalLink className="w-3 h-3" />
                  </a>
                </div>
                <img src={image.url} alt={`${title} ${image.label}`} className="mx-auto max-h-[64vh] w-auto max-w-full object-contain" />
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

function googleMapsUrl(lat: number, lng: number) {
  return `https://www.google.com/maps?q=${lat},${lng}`;
}

function mapZoomForBounds(minLat: number, maxLat: number, minLng: number, maxLng: number) {
  const latRange = Math.max(Math.abs(maxLat - minLat), 0.0001);
  const lngRange = Math.max(Math.abs(maxLng - minLng), 0.0001);
  const range = Math.max(latRange, lngRange);

  if (range > 2) return 7;
  if (range > 1) return 8;
  if (range > 0.5) return 9;
  if (range > 0.25) return 10;
  if (range > 0.12) return 11;
  if (range > 0.06) return 12;
  if (range > 0.03) return 13;
  if (range > 0.015) return 14;
  if (range > 0.007) return 15;

  return 16;
}

function latLngToWorldPixel(lat: number, lng: number, zoom: number) {
  const tileSize = 256;
  const scale = tileSize * 2 ** zoom;
  const sinLat = Math.sin((Math.max(Math.min(lat, 85.05112878), -85.05112878) * Math.PI) / 180);

  return {
    x: ((lng + 180) / 360) * scale,
    y: (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * scale,
  };
}
