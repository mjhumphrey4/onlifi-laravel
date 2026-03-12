import { useState, useEffect, useCallback, useRef } from 'react';
import { ChevronLeft, ChevronRight, Search, RefreshCw } from 'lucide-react';
import { apiTransactions } from '../utils/api';
import { useAuth } from '../context/AuthContext';

const ITEMS_PER_PAGE = 15;
type TabType = 'all' | 'success' | 'pending' | 'failed' | 'no-voucher';

interface TxRow {
  id: string;
  msisdn: string;
  amount: string;
  status: string;
  created_at: string;
  origin_site: string;
  voucher_code: string;
  external_ref: string;
  site_label: string;
}

function fmt(n: number) { return 'UGX ' + Math.round(n).toLocaleString(); }

function statusStyle(s: string) {
  switch (s.toLowerCase()) {
    case 'success': return 'bg-primary/10 text-primary';
    case 'pending': return 'bg-yellow-500/10 text-yellow-500';
    case 'failed':  return 'bg-destructive/10 text-destructive';
    default:        return 'bg-muted text-muted-foreground';
  }
}

export function Transactions() {
  const { userSites } = useAuth();
  const sites = userSites();
  const [activeTab, setActiveTab] = useState<TabType>('all');
  const [currentPage, setCurrentPage] = useState(1);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [selectedSite, setSelectedSite] = useState('');
  const [txs, setTxs] = useState<TxRow[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const load = useCallback(async (page: number, tab: TabType, search: string, site: string) => {
    setLoading(true);
    try {
      if (tab === 'no-voucher') {
        // Fetch all successful transactions and filter client-side for missing voucher
        const res = await apiTransactions({ page: 1, limit: 500, status: 'success', search, site });
        const rows: TxRow[] = (res.transactions ?? []).filter((r: TxRow) => !r.voucher_code);
        setTxs(rows);
        setTotal(rows.length);
      } else {
        const status = tab === 'all' ? '' : tab;
        const res = await apiTransactions({ page, limit: ITEMS_PER_PAGE, status, search, site });
        setTxs(res.transactions ?? []);
        setTotal(res.total ?? 0);
      }
    } catch (e) { console.error(e); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { load(currentPage, activeTab, searchQuery, selectedSite); }, [currentPage, activeTab, searchQuery, selectedSite, load]);

  const handleTabChange = (tab: TabType) => { setActiveTab(tab); setCurrentPage(1); };
  const handleSiteChange = (site: string) => { setSelectedSite(site); setCurrentPage(1); };

  const handleSearchChange = (val: string) => {
    setSearchInput(val);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => { setSearchQuery(val); setCurrentPage(1); }, 400);
  };

  const totalPages = Math.ceil(total / ITEMS_PER_PAGE);
  const tabs: { key: TabType; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'success', label: 'Success' },
    { key: 'pending', label: 'Pending' },
    { key: 'failed', label: 'Failed' },
    { key: 'no-voucher', label: 'No Voucher' },
  ];

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8">
        <h1 className="text-2xl sm:text-3xl text-foreground mb-2">Transactions</h1>
        <p className="text-sm sm:text-base text-muted-foreground">View and manage all your transactions</p>
      </div>

      <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
        {/* Tabs */}
        <div className="flex gap-2 mb-4 sm:mb-6 border-b border-border pb-4 overflow-x-auto scrollbar-hide">
          {tabs.map((t) => (
            <button key={t.key} onClick={() => handleTabChange(t.key)}
              className={`px-3 sm:px-5 py-2 rounded-lg capitalize transition-colors whitespace-nowrap text-xs sm:text-sm ${
                activeTab === t.key ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'
              }`}>
              {t.label}
            </button>
          ))}
        </div>

        {/* Search + site filter */}
        <div className="flex flex-col sm:flex-row gap-3 mb-6">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <input type="text" placeholder="Search by phone, reference, voucher…"
              value={searchInput} onChange={(e) => handleSearchChange(e.target.value)}
              className="w-full pl-10 pr-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm" />
          </div>
          {sites.length > 1 && (
            <select value={selectedSite} onChange={(e) => handleSiteChange(e.target.value)}
              className="px-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm">
              <option value="">All Sites</option>
              {sites.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          )}
        </div>

        {/* Table */}
        <div className="overflow-x-auto -mx-4 sm:mx-0 mb-6">
          <div className="inline-block min-w-full align-middle">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border">
                  {['ID', 'Voucher', 'Phone', 'Reference', 'Amount', 'Site', 'Status', 'Date'].map((h) => (
                    <th key={h} className="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr><td colSpan={8} className="py-10 text-center"><RefreshCw className="w-5 h-5 text-primary animate-spin mx-auto" /></td></tr>
                ) : txs.length === 0 ? (
                  <tr><td colSpan={8} className="py-8 text-center text-muted-foreground text-sm">No transactions found.</td></tr>
                ) : txs.map((tx, i) => (
                  <tr key={`${tx.id}-${i}`} className="border-b border-border/50 hover:bg-muted/50 transition-colors">
                    <td className="py-3 px-2 sm:px-4 text-xs text-muted-foreground whitespace-nowrap font-mono">#{String(tx.id).slice(0, 8)}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs font-mono whitespace-nowrap">
                      {tx.voucher_code
                        ? <span className="text-primary font-semibold tracking-wider">{tx.voucher_code}</span>
                        : <span className="text-muted-foreground">—</span>}
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">{tx.msisdn}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs text-muted-foreground whitespace-nowrap font-mono">{String(tx.external_ref).slice(0, 14)}…</td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap font-semibold">{fmt(parseFloat(tx.amount))}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{tx.origin_site}</td>
                    <td className="py-3 px-2 sm:px-4 whitespace-nowrap">
                      <span className={`inline-block px-2 py-1 rounded-full text-xs capitalize ${statusStyle(tx.status)}`}>{tx.status}</span>
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {new Date(tx.created_at).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Pagination */}
        {totalPages > 1 && (
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p className="text-xs sm:text-sm text-muted-foreground">
              Showing {(currentPage - 1) * ITEMS_PER_PAGE + 1}–{Math.min(currentPage * ITEMS_PER_PAGE, total)} of {total}
            </p>
            <div className="flex gap-2 flex-wrap justify-center">
              <button onClick={() => setCurrentPage((p) => Math.max(1, p - 1))} disabled={currentPage === 1}
                className="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm">
                <ChevronLeft className="w-4 h-4" /><span className="hidden sm:inline">Prev</span>
              </button>
              <div className="flex gap-1">
                {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
                  const p = totalPages <= 5 ? i + 1 : currentPage <= 3 ? i + 1 : currentPage >= totalPages - 2 ? totalPages - 4 + i : currentPage - 2 + i;
                  return (
                    <button key={p} onClick={() => setCurrentPage(p)}
                      className={`w-9 h-9 rounded-lg text-xs sm:text-sm ${currentPage === p ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'}`}>
                      {p}
                    </button>
                  );
                })}
              </div>
              <button onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))} disabled={currentPage === totalPages}
                className="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm">
                <span className="hidden sm:inline">Next</span><ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
