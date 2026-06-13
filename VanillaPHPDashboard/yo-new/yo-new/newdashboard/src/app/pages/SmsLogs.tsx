import { useCallback, useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight, MessageSquareText, RefreshCw } from 'lucide-react';
import { apiSites, apiSmsLogs } from '../utils/api';

const ITEMS_PER_PAGE = 25;

interface SmsLog {
  id: number;
  site_label?: string;
  site_slug?: string;
  external_ref?: string;
  recipient: string;
  sender_id?: string;
  message_category?: string;
  message: string;
  status: string;
  provider_message?: string;
  provider_cost?: string | number | null;
  provider_balance?: string | number | null;
  created_at: string;
}

interface SiteRecord {
  slug: string;
  display_name: string;
}

function statusStyle(status: string) {
  switch (status.toLowerCase()) {
    case 'sent': return 'bg-primary/10 text-primary';
    case 'failed': return 'bg-destructive/10 text-destructive';
    default: return 'bg-muted text-muted-foreground';
  }
}

export function SmsLogs() {
  const [logs, setLogs] = useState<SmsLog[]>([]);
  const [sites, setSites] = useState<SiteRecord[]>([]);
  const [site, setSite] = useState('');
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [logRes, siteRes] = await Promise.all([
        apiSmsLogs({ page, limit: ITEMS_PER_PAGE, site }),
        apiSites(),
      ]);
      setLogs(logRes.logs ?? []);
      setTotal(logRes.total ?? 0);
      setSites(siteRes.sites ?? []);
    } finally {
      setLoading(false);
    }
  }, [page, site]);

  useEffect(() => { load(); }, [load]);

  const totalPages = Math.max(1, Math.ceil(total / ITEMS_PER_PAGE));

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-2">SMS Logs</h1>
          <p className="text-sm sm:text-base text-muted-foreground">Track every MamboSMS message sent after successful transactions.</p>
        </div>
        <button
          onClick={load}
          className="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-muted text-muted-foreground rounded-lg text-sm hover:bg-muted/80"
        >
          <RefreshCw className="w-4 h-4" />
          Refresh
        </button>
      </div>

      <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
        <div className="flex flex-col sm:flex-row sm:justify-end gap-3 mb-6">
          <select
            value={site}
            onChange={(e) => { setSite(e.target.value); setPage(1); }}
            className="px-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm"
          >
            <option value="">All Sites</option>
            {sites.map((row) => <option key={row.slug} value={row.slug}>{row.display_name}</option>)}
          </select>
        </div>

        <div className="overflow-x-auto -mx-4 sm:mx-0 mb-6">
          <div className="inline-block min-w-full align-middle">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border">
                  {['Site', 'Recipient', 'Message', 'Status', 'Cost', 'Balance', 'Reference', 'Date'].map((h) => (
                    <th key={h} className="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr><td colSpan={8} className="py-10 text-center"><RefreshCw className="w-5 h-5 text-primary animate-spin mx-auto" /></td></tr>
                ) : logs.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="py-10 text-center text-muted-foreground">
                      <MessageSquareText className="w-6 h-6 mx-auto mb-2" />
                      No SMS logs yet.
                    </td>
                  </tr>
                ) : logs.map((log) => (
                  <tr key={log.id} className="border-b border-border/50 hover:bg-muted/50 transition-colors">
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">{log.site_label || log.site_slug || '-'}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">{log.recipient}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs text-muted-foreground min-w-[280px] max-w-xl">{log.message}</td>
                    <td className="py-3 px-2 sm:px-4 whitespace-nowrap">
                      <span className={`inline-block px-2 py-1 rounded-full text-xs capitalize ${statusStyle(log.status)}`}>{log.status}</span>
                      {log.provider_message && <p className="text-[11px] text-muted-foreground mt-1 max-w-[180px] truncate">{log.provider_message}</p>}
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{log.provider_cost ?? '-'}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{log.provider_balance ?? '-'}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs font-mono text-muted-foreground whitespace-nowrap">{log.external_ref ? String(log.external_ref).slice(0, 18) : '-'}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {new Date(log.created_at).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
          <p className="text-xs sm:text-sm text-muted-foreground">
            Showing {total === 0 ? 0 : (page - 1) * ITEMS_PER_PAGE + 1}-{Math.min(page * ITEMS_PER_PAGE, total)} of {total}
          </p>
          <div className="flex gap-2">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1}
              className="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm"
            >
              <ChevronLeft className="w-4 h-4" /> Prev
            </button>
            <button
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              disabled={page >= totalPages}
              className="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm"
            >
              Next <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
