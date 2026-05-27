import { useEffect, useState } from 'react';
import { Loader2, MessageSquare, RefreshCw, ToggleLeft, ToggleRight } from 'lucide-react';
import { getSmsCredits, updateSmsPlan } from '../utils/api';

export function SmsGateway() {
  const [summary, setSummary] = useState<any>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');

  const load = async (nextPage = page) => {
    setLoading(true);
    try {
      setSummary(await getSmsCredits(nextPage));
      setPage(nextPage);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load(1);
  }, []);

  const togglePlan = async () => {
    const next = !summary?.sms_enabled;
    setSaving(true);
    setMessage('');
    try {
      const response = await updateSmsPlan(next);
      setSummary({ ...summary, sms_enabled: response.sms_enabled });
      setMessage(response.message || (next ? 'SMS enabled.' : 'SMS disabled.'));
    } finally {
      setSaving(false);
    }
  };

  if (loading && !summary) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  const logs = summary?.logs?.data || [];
  const currentPage = summary?.logs?.current_page || page;
  const lastPage = summary?.logs?.last_page || 1;

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">SMS Gateway</h1>
          <p className="text-muted-foreground mt-1">Control SMS delivery and review recent voucher messages.</p>
        </div>
        <div className="flex gap-2">
          <button disabled={saving} onClick={togglePlan} className={`inline-flex items-center gap-2 px-4 py-2 rounded-lg border ${summary?.sms_enabled ? 'border-green-500 text-green-600' : 'border-border text-muted-foreground'} hover:bg-muted disabled:opacity-60`}>
            {summary?.sms_enabled ? <ToggleRight className="w-4 h-4" /> : <ToggleLeft className="w-4 h-4" />}
            {summary?.sms_enabled ? 'SMS Enabled' : 'SMS Disabled'}
          </button>
          <button onClick={() => load(currentPage)} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
            <RefreshCw className="w-4 h-4" />
            Refresh
          </button>
        </div>
      </div>

      {message && <div className="rounded-lg border border-border bg-card p-3 text-sm">{message}</div>}

      <div className="grid sm:grid-cols-3 gap-4">
        <div className="bg-card border border-border rounded-lg p-5">
          <MessageSquare className="w-6 h-6 text-primary" />
          <p className="text-sm text-muted-foreground mt-4">SMS status</p>
          <p className="text-2xl font-semibold mt-1">{summary?.sms_enabled ? 'Enabled' : 'Disabled'}</p>
        </div>
        <div className="bg-card border border-border rounded-lg p-5">
          <MessageSquare className="w-6 h-6 text-primary" />
          <p className="text-sm text-muted-foreground mt-4">Sent messages</p>
          <p className="text-2xl font-semibold mt-1">{Number(summary?.sent_count || 0).toLocaleString()}</p>
        </div>
        <div className="bg-card border border-border rounded-lg p-5">
          <MessageSquare className="w-6 h-6 text-primary" />
          <p className="text-sm text-muted-foreground mt-4">Total log entries</p>
          <p className="text-2xl font-semibold mt-1">{Number(summary?.total_count || 0).toLocaleString()}</p>
        </div>
      </div>

      <div className="bg-card border border-border rounded-lg">
        <div className="p-5 border-b border-border flex items-center justify-between gap-3">
          <div>
            <h2 className="font-semibold">SMS Logs</h2>
            <p className="text-sm text-muted-foreground">Most recent messages sent or skipped for this tenant.</p>
          </div>
          {loading && <Loader2 className="w-5 h-5 animate-spin text-primary" />}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-muted-foreground border-b border-border">
              <tr>
                <th className="px-5 py-3 font-medium">Date</th>
                <th className="px-5 py-3 font-medium">Phone</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium">Message</th>
              </tr>
            </thead>
            <tbody>
              {logs.length === 0 ? (
                <tr><td colSpan={4} className="px-5 py-8 text-center text-muted-foreground">No SMS logs yet.</td></tr>
              ) : logs.map((log: any) => (
                <tr key={log.id} className="border-b border-border/70 last:border-0">
                  <td className="px-5 py-3 whitespace-nowrap">{new Date(log.created_at).toLocaleString()}</td>
                  <td className="px-5 py-3 whitespace-nowrap">{log.msisdn}</td>
                  <td className="px-5 py-3 whitespace-nowrap capitalize">{log.status}</td>
                  <td className="px-5 py-3 min-w-[280px]">
                    <p className="line-clamp-2">{log.message}</p>
                    {log.error && <p className="text-xs text-destructive mt-1">{log.error}</p>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="p-4 border-t border-border flex items-center justify-between">
          <button disabled={currentPage <= 1 || loading} onClick={() => load(currentPage - 1)} className="px-3 py-2 rounded-lg border border-border hover:bg-muted disabled:opacity-50">Previous</button>
          <span className="text-sm text-muted-foreground">Page {currentPage} of {lastPage}</span>
          <button disabled={currentPage >= lastPage || loading} onClick={() => load(currentPage + 1)} className="px-3 py-2 rounded-lg border border-border hover:bg-muted disabled:opacity-50">Next</button>
        </div>
      </div>
    </div>
  );
}
