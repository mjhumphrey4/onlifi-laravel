import { useState, useEffect, useCallback, useRef } from 'react';
import { ChevronLeft, ChevronRight, Search, RefreshCw } from 'lucide-react';
import { apiTransactions } from '../utils/api';
import { useSite } from '../context/SiteContext';

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
  transaction_ref?: string | null;
  site_label: string;
  client_mac?: string | null;
  telecom_fee?: string | number | null;
  platform_fee?: string | number | null;
  net_amount?: string | number | null;
  voucher?: {
    voucher_code?: string | null;
    used_by_mac?: string | null;
  } | null;
}

function fmt(n: number) {
  return 'UGX ' + Math.round(n).toLocaleString();
}

function num(value: string | number | null | undefined) {
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function statusStyle(s: string) {
  switch (s.toLowerCase()) {
    case 'success': return 'bg-primary/10 text-primary';
    case 'pending': return 'bg-yellow-500/10 text-yellow-500';
    case 'failed': return 'bg-destructive/10 text-destructive';
    default: return 'bg-muted text-muted-foreground';
  }
}

export function Transactions() {
  const { selectedSite } = useSite();
  const [activeTab, setActiveTab] = useState<TabType>('all');
  const [currentPage, setCurrentPage] = useState(1);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [txs, setTxs] = useState<TxRow[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const load = useCallback(async (page: number, tab: TabType, search: string) => {
    setLoading(true);
    try {
      if (tab === 'no-voucher') {
        const res = await apiTransactions({ page: 1, limit: 500, status: 'success', search });
        const rows: TxRow[] = (res.transactions ?? []).filter((row: TxRow) => !row.voucher_code);
        setTxs(rows);
        setTotal(rows.length);
      } else {
        const status = tab === 'all' ? '' : tab;
        const res = await apiTransactions({ page, limit: ITEMS_PER_PAGE, status, search });
        setTxs(res.transactions ?? []);
        setTotal(res.total ?? 0);
      }
    } catch (error) {
      console.error(error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load(currentPage, activeTab, searchQuery);
  }, [currentPage, activeTab, searchQuery, selectedSite?.id, load]);

  const handleTabChange = (tab: TabType) => {
    setActiveTab(tab);
    setCurrentPage(1);
  };

  const handleSearchChange = (value: string) => {
    setSearchInput(value);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => {
      setSearchQuery(value);
      setCurrentPage(1);
    }, 400);
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
        <div className="flex gap-2 mb-4 sm:mb-6 border-b border-border pb-4 overflow-x-auto scrollbar-hide">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => handleTabChange(tab.key)}
              className={`px-3 sm:px-5 py-2 rounded-lg capitalize transition-colors whitespace-nowrap text-xs sm:text-sm ${
                activeTab === tab.key ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <div className="flex flex-col sm:flex-row gap-3 mb-6">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <input
              type="text"
              placeholder="Search by phone, reference, voucher..."
              value={searchInput}
              onChange={(event) => handleSearchChange(event.target.value)}
              className="w-full pl-10 pr-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm"
            />
          </div>
        </div>

        <div className="overflow-x-auto -mx-4 sm:mx-0 mb-6">
          <div className="inline-block min-w-full align-middle">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border">
                  {['ID', 'Voucher', 'Phone', 'Reference', 'Amount', 'Telecom Fee', 'Net Amount', 'Site', 'Status', 'Date'].map((heading) => (
                    <th key={heading} className="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {heading}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan={10} className="py-10 text-center">
                      <RefreshCw className="w-5 h-5 text-primary animate-spin mx-auto" />
                    </td>
                  </tr>
                ) : txs.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="py-8 text-center text-muted-foreground text-sm">No transactions found.</td>
                  </tr>
                ) : txs.map((tx, index) => {
                  const amount = num(tx.amount);
                  const fee = num(tx.telecom_fee ?? tx.platform_fee);
                  const net = tx.net_amount !== undefined && tx.net_amount !== null ? num(tx.net_amount) : Math.max(amount - fee, 0);
                  const voucherCode = tx.voucher_code || tx.voucher?.voucher_code || '';
                  const reference = tx.external_ref || tx.transaction_ref || '';
                  const site = tx.origin_site || tx.site_label || selectedSite?.name || '-';

                  return (
                    <tr key={`${tx.id}-${index}`} className="border-b border-border/50 hover:bg-muted/50 transition-colors">
                      <td className="py-3 px-2 sm:px-4 text-xs text-muted-foreground whitespace-nowrap font-mono">#{String(tx.id)}</td>
                      <td className="py-3 px-2 sm:px-4 text-xs font-mono whitespace-nowrap">
                        {voucherCode
                          ? <span className="text-primary font-semibold tracking-wider">{voucherCode}</span>
                          : <span className="text-muted-foreground">-</span>}
                      </td>
                      <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">{tx.msisdn || '-'}</td>
                      <td className="py-3 px-2 sm:px-4 text-xs text-muted-foreground whitespace-nowrap font-mono max-w-[220px] truncate" title={reference || '-'}>
                        {reference || '-'}
                      </td>
                      <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap font-semibold">{fmt(amount)}</td>
                      <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{fmt(fee)}</td>
                      <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap font-semibold">{fmt(net)}</td>
                      <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{site}</td>
                      <td className="py-3 px-2 sm:px-4 whitespace-nowrap">
                        <span className={`inline-block px-2 py-1 rounded-full text-xs capitalize ${statusStyle(tx.status)}`}>{tx.status}</span>
                      </td>
                      <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                        {new Date(tx.created_at).toLocaleString('en-GB', {
                          day: '2-digit',
                          month: 'short',
                          year: 'numeric',
                          hour: '2-digit',
                          minute: '2-digit',
                        })}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>

        {totalPages > 1 && (
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p className="text-xs sm:text-sm text-muted-foreground">
              Showing {(currentPage - 1) * ITEMS_PER_PAGE + 1}-{Math.min(currentPage * ITEMS_PER_PAGE, total)} of {total}
            </p>
            <div className="flex gap-2 flex-wrap justify-center">
              <button
                onClick={() => setCurrentPage((page) => Math.max(1, page - 1))}
                disabled={currentPage === 1}
                className="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm"
              >
                <ChevronLeft className="w-4 h-4" />
                <span className="hidden sm:inline">Prev</span>
              </button>
              <div className="flex gap-1">
                {Array.from({ length: Math.min(5, totalPages) }, (_, index) => {
                  const page = totalPages <= 5
                    ? index + 1
                    : currentPage <= 3
                      ? index + 1
                      : currentPage >= totalPages - 2
                        ? totalPages - 4 + index
                        : currentPage - 2 + index;

                  return (
                    <button
                      key={page}
                      onClick={() => setCurrentPage(page)}
                      className={`w-9 h-9 rounded-lg text-xs sm:text-sm ${currentPage === page ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'}`}
                    >
                      {page}
                    </button>
                  );
                })}
              </div>
              <button
                onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))}
                disabled={currentPage === totalPages}
                className="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm"
              >
                <span className="hidden sm:inline">Next</span>
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
