import { useCallback, useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Clock, RefreshCw, Search, Ticket } from 'lucide-react';
import { getVouchers } from '../utils/api';
import { useSite } from '../context/SiteContext';

const ITEMS_PER_PAGE = 20;

interface ExpiredVoucher {
  id: number;
  voucher_code: string;
  status: string;
  profile_name?: string | null;
  validity_hours?: number | null;
  validity_minutes?: number | null;
  data_limit_mb?: number | null;
  expired_reason?: string | null;
  first_used_at?: string | null;
  last_used_at?: string | null;
  expires_at?: string | null;
  used_by_mac?: string | null;
  used_by_ip?: string | null;
  total_data_used_mb?: string | number | null;
  total_session_time_minutes?: number | null;
  group?: {
    group_name?: string | null;
  } | null;
}

interface VoucherPageResponse {
  data?: ExpiredVoucher[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

function formatDate(value?: string | null) {
  if (!value) return '-';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';

  return date.toLocaleString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatReason(voucher: ExpiredVoucher) {
  const reason = String(voucher.expired_reason || '').trim();
  if (reason) {
    return reason
      .replace(/_/g, ' ')
      .replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

  if (voucher.status === 'expired') return 'Expired';
  if (voucher.status === 'used') return 'Consumed';

  const expiresAt = voucher.expires_at ? new Date(voucher.expires_at) : null;
  if (expiresAt && !Number.isNaN(expiresAt.getTime()) && expiresAt.getTime() <= Date.now()) {
    return 'Time Limit';
  }

  return '-';
}

function formatData(value?: string | number | null) {
  const numericValue = Number(value ?? 0);
  if (!Number.isFinite(numericValue) || numericValue <= 0) return '-';
  return `${numericValue.toLocaleString(undefined, { maximumFractionDigits: 2 })} MB`;
}

function formatDuration(minutes?: number | null) {
  if (!minutes || minutes <= 0) return '-';
  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;

  if (hours > 0 && remainingMinutes > 0) return `${hours}h ${remainingMinutes}m`;
  if (hours > 0) return `${hours}h`;
  return `${remainingMinutes}m`;
}

function voucherType(voucher: ExpiredVoucher) {
  if (voucher.group?.group_name) return voucher.group.group_name;
  if (voucher.profile_name) return voucher.profile_name;
  if (voucher.validity_minutes) return `${voucher.validity_minutes} minutes`;
  if (voucher.validity_hours) return `${voucher.validity_hours} hours`;
  return '-';
}

export function ExpiredVouchers() {
  const { selectedSite } = useSite();
  const [vouchers, setVouchers] = useState<ExpiredVoucher[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const loadVouchers = useCallback(async () => {
    setLoading(true);
    setError('');

    try {
      const response = await getVouchers({
        status: 'expired',
        search,
        page,
        per_page: ITEMS_PER_PAGE,
      }) as VoucherPageResponse;

      const rows = Array.isArray(response?.data) ? response.data : [];
      setVouchers(rows);
      setTotal(Number(response?.total ?? rows.length));
      setLastPage(Math.max(1, Number(response?.last_page ?? 1)));
    } catch (loadError) {
      setVouchers([]);
      setTotal(0);
      setLastPage(1);
      setError(loadError instanceof Error ? loadError.message : 'Failed to load expired vouchers');
    } finally {
      setLoading(false);
    }
  }, [page, search]);

  useEffect(() => {
    loadVouchers();
  }, [loadVouchers, selectedSite?.id]);

  useEffect(() => () => {
    if (searchTimer.current) {
      clearTimeout(searchTimer.current);
    }
  }, []);

  const handleSearchChange = (value: string) => {
    setSearchInput(value);

    if (searchTimer.current) {
      clearTimeout(searchTimer.current);
    }

    searchTimer.current = setTimeout(() => {
      setSearch(value.trim());
      setPage(1);
    }, 350);
  };

  const from = total === 0 ? 0 : (page - 1) * ITEMS_PER_PAGE + 1;
  const to = Math.min(page * ITEMS_PER_PAGE, total);

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-2">Expired Vouchers</h1>
          <p className="text-sm sm:text-base text-muted-foreground">
            Search consumed vouchers by code or client MAC and review their usage trail.
          </p>
        </div>
        <button
          onClick={loadVouchers}
          disabled={loading}
          className="inline-flex items-center justify-center gap-2 px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors text-sm disabled:opacity-50"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
        <div className="flex flex-col gap-3 mb-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="relative w-full lg:max-w-md">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <input
              type="text"
              value={searchInput}
              onChange={(event) => handleSearchChange(event.target.value)}
              placeholder="Search by voucher code or MAC address..."
              className="w-full pl-10 pr-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm"
            />
          </div>
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <Clock className="w-4 h-4" />
            <span>{total.toLocaleString()} expired voucher{total === 1 ? '' : 's'}</span>
          </div>
        </div>

        {error && (
          <div className="mb-6 p-4 rounded-lg bg-destructive/10 border border-destructive/20 text-destructive text-sm">
            {error}
          </div>
        )}

        <div className="overflow-x-auto -mx-4 sm:mx-0">
          <div className="inline-block min-w-full align-middle">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border">
                  {['Voucher', 'MAC Address', 'IP Address', 'Voucher Type', 'Expired Reason', 'First Used At', 'Last Used At', 'Data Used', 'Session Time'].map((heading) => (
                    <th key={heading} className="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {heading}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan={9} className="py-10 text-center">
                      <RefreshCw className="w-5 h-5 text-primary animate-spin mx-auto" />
                    </td>
                  </tr>
                ) : vouchers.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="py-10 text-center">
                      <div className="flex flex-col items-center gap-2 text-muted-foreground">
                        <Ticket className="w-8 h-8" />
                        <p className="text-sm">No expired vouchers found.</p>
                      </div>
                    </td>
                  </tr>
                ) : vouchers.map((voucher) => (
                  <tr key={voucher.id} className="border-b border-border/50 hover:bg-muted/50 transition-colors">
                    <td className="py-3 px-2 sm:px-4 whitespace-nowrap">
                      <span className="font-mono text-xs sm:text-sm font-semibold text-primary tracking-wider">
                        {voucher.voucher_code}
                      </span>
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap font-mono">
                      {voucher.used_by_mac || '-'}
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap font-mono">
                      {voucher.used_by_ip || '-'}
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">
                      {voucherType(voucher)}
                    </td>
                    <td className="py-3 px-2 sm:px-4 whitespace-nowrap">
                      <span className="inline-flex px-2 py-1 rounded-full bg-yellow-500/10 text-yellow-500 text-xs font-medium">
                        {formatReason(voucher)}
                      </span>
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {formatDate(voucher.first_used_at)}
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {formatDate(voucher.last_used_at)}
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {formatData(voucher.total_data_used_mb)}
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {formatDuration(voucher.total_session_time_minutes)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {lastPage > 1 && (
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4 mt-6">
            <p className="text-xs sm:text-sm text-muted-foreground">
              Showing {from}-{to} of {total}
            </p>
            <div className="flex gap-2">
              <button
                onClick={() => setPage((current) => Math.max(1, current - 1))}
                disabled={page === 1 || loading}
                className="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm"
              >
                <ChevronLeft className="w-4 h-4" />
                Previous
              </button>
              <button
                onClick={() => setPage((current) => Math.min(lastPage, current + 1))}
                disabled={page === lastPage || loading}
                className="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm"
              >
                Next
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
