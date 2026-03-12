import { useState, useEffect, useCallback } from 'react';
import { RefreshCw, Package, AlertTriangle } from 'lucide-react';
import { apiVoucherStock } from '../utils/api';
import { useAuth } from '../context/AuthContext';

const LOW_STOCK_THRESHOLD = 50;

interface Stock {
  '2hours': number;
  '3hours': number;
  '12hours': number;
  '24hours': number;
  '7days': number;
  '30days': number;
  total: number;
}

const VOUCHER_TYPES: { key: keyof Omit<Stock, 'total'>; label: string; badge: string; color: string; border: string }[] = [
  { key: '2hours',  label: '2 Hours',  badge: 'QUICK',    color: 'text-purple-400', border: 'border-purple-500', },
  { key: '3hours',  label: '3 Hours',  badge: '3 HRS',    color: 'text-pink-400',   border: 'border-pink-500',   },
  { key: '12hours', label: '12 Hours', badge: 'HALF DAY', color: 'text-blue-400',   border: 'border-blue-500',   },
  { key: '24hours', label: '24 Hours', badge: 'FULL DAY', color: 'text-indigo-400', border: 'border-indigo-500', },
  { key: '7days',   label: '7 Days',   badge: 'WEEKLY',   color: 'text-primary',    border: 'border-primary',    },
  { key: '30days',  label: '30 Days',  badge: 'MONTHLY',  color: 'text-orange-400', border: 'border-orange-500', },
];

export function VoucherStock() {
  const { userSites } = useAuth();
  const sites = userSites();

  const [selectedSite, setSelectedSite] = useState(sites[0] ?? '');
  const [stock, setStock] = useState<Stock | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);

  const load = useCallback(async (site: string) => {
    if (!site) return;
    setLoading(true);
    setError('');
    try {
      const res = await apiVoucherStock(site);
      setStock(res.stock);
      setLastUpdated(new Date());
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load voucher stock');
      setStock(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(selectedSite); }, [selectedSite, load]);

  const lowStockItems = stock
    ? VOUCHER_TYPES.filter((t) => (stock[t.key] ?? 0) < LOW_STOCK_THRESHOLD).map((t) => t.label)
    : [];

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-2">Voucher Stock</h1>
          <p className="text-sm sm:text-base text-muted-foreground">Monitor unused voucher inventory per site</p>
        </div>
        <div className="flex items-center gap-3">
          {lastUpdated && (
            <span className="text-xs text-muted-foreground">Updated {lastUpdated.toLocaleTimeString()}</span>
          )}
          <button onClick={() => load(selectedSite)} disabled={loading}
            className="flex items-center gap-2 px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors text-sm disabled:opacity-50">
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} /> Refresh
          </button>
        </div>
      </div>

      {/* Site selector (only shown for admin or multi-site users) */}
      {sites.length > 1 && (
        <div className="mb-6">
          <div className="flex flex-wrap gap-2">
            {sites.map((s) => (
              <button key={s} onClick={() => setSelectedSite(s)}
                className={`px-4 py-2 rounded-lg text-sm transition-colors ${selectedSite === s ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'}`}>
                {s}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Error */}
      {error && (
        <div className="mb-6 p-4 rounded-lg bg-destructive/10 border border-destructive/20 text-destructive text-sm">
          {error}
        </div>
      )}

      {/* Low stock alert */}
      {lowStockItems.length > 0 && (
        <div className="mb-6 p-4 rounded-lg bg-yellow-500/10 border border-yellow-500/30 flex items-start gap-3">
          <AlertTriangle className="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" />
          <div>
            <p className="text-sm font-semibold text-yellow-500">Low Stock Warning</p>
            <p className="text-xs text-muted-foreground mt-1">
              The following types are below {LOW_STOCK_THRESHOLD} vouchers: <span className="text-yellow-500 font-medium">{lowStockItems.join(', ')}</span>. Please restock soon.
            </p>
          </div>
        </div>
      )}

      {/* Stock cards */}
      {loading && !stock ? (
        <div className="flex items-center justify-center h-48">
          <RefreshCw className="w-6 h-6 text-primary animate-spin" />
        </div>
      ) : stock ? (
        <>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
            {VOUCHER_TYPES.map((t) => {
              const count = stock[t.key] ?? 0;
              const isLow = count < LOW_STOCK_THRESHOLD;
              return (
                <div key={t.key} className={`bg-card border-l-4 ${t.border} border border-border rounded-lg p-4 sm:p-5`}>
                  <div className="flex items-center justify-between mb-3">
                    <span className={`text-xs font-semibold px-2 py-0.5 rounded ${t.color} bg-muted`}>{t.badge}</span>
                    {isLow && <AlertTriangle className="w-4 h-4 text-yellow-500" />}
                  </div>
                  <p className="text-xs text-muted-foreground mb-1">{t.label} Vouchers</p>
                  <p className={`text-3xl font-bold ${isLow ? 'text-yellow-500' : 'text-card-foreground'}`}>
                    {count.toLocaleString()}
                  </p>
                  <p className="text-xs text-muted-foreground mt-1">Available in stock</p>
                </div>
              );
            })}
          </div>

          {/* Total summary */}
          <div className="bg-primary/10 border border-primary/20 rounded-lg p-5 sm:p-6 flex items-center justify-between">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-primary/20 rounded-lg flex items-center justify-center">
                <Package className="w-6 h-6 text-primary" />
              </div>
              <div>
                <p className="text-sm text-muted-foreground">Total Unused Vouchers — {selectedSite}</p>
                <p className="text-3xl font-bold text-primary">{stock.total.toLocaleString()}</p>
              </div>
            </div>
            <p className="text-xs text-muted-foreground hidden sm:block">Combined across all voucher types</p>
          </div>
        </>
      ) : !error ? (
        <div className="flex items-center justify-center h-48 text-muted-foreground text-sm">
          No data available.
        </div>
      ) : null}
    </div>
  );
}
