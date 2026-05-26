import { useEffect, useMemo, useState } from 'react';
import { BarChart3, CalendarDays, DollarSign, RefreshCw, Repeat, Users } from 'lucide-react';

type DateFilter = 'today' | 'yesterday' | 'week' | 'month' | 'all';

const filters: { id: DateFilter; label: string }[] = [
  { id: 'today', label: 'Today' },
  { id: 'yesterday', label: 'Yesterday' },
  { id: 'week', label: 'This Week' },
  { id: 'month', label: 'This Month' },
  { id: 'all', label: 'All' },
];

const fmt = (value: number) => `UGX ${Math.round(value || 0).toLocaleString()}`;

export function Reports() {
  const [filter, setFilter] = useState<DateFilter>('today');
  const [stats, setStats] = useState<any>(null);
  const [transactions, setTransactions] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const range = useMemo(() => {
    const now = new Date();
    const start = new Date(now);
    const end = new Date(now);
    const iso = (date: Date) => date.toISOString().slice(0, 10);

    if (filter === 'all') return {};
    if (filter === 'yesterday') {
      start.setDate(now.getDate() - 1);
      end.setDate(now.getDate() - 1);
    }
    if (filter === 'week') {
      const day = now.getDay() || 7;
      start.setDate(now.getDate() - day + 1);
    }
    if (filter === 'month') start.setDate(1);

    return { from_date: iso(start), to_date: iso(end) };
  }, [filter]);

  const load = async () => {
    setLoading(true);
    const token = localStorage.getItem('tenant_token');
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
    const params = new URLSearchParams(range as Record<string, string>);
    const txParams = new URLSearchParams({ per_page: '200', ...(range as Record<string, string>) });

    try {
      const [statsResponse, txResponse] = await Promise.all([
        fetch(`/api/transactions/statistics?${params.toString()}`, { headers }),
        fetch(`/api/transactions?${txParams.toString()}`, { headers }),
      ]);
      setStats(statsResponse.ok ? await statsResponse.json() : null);
      const txData = txResponse.ok ? await txResponse.json() : { data: [] };
      setTransactions(txData.data || txData.transactions || []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, [filter]);

  const uniqueCustomers = new Set(transactions.map((tx) => tx.msisdn).filter(Boolean)).size;
  const successRate = stats?.total_transactions
    ? Math.round((Number(stats.successful_transactions || 0) / Number(stats.total_transactions)) * 100)
    : 0;

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Reports</h1>
          <p className="text-muted-foreground mt-1">Analytics, revenue movement, and customer purchase behavior.</p>
        </div>
        <button onClick={load} className="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-2 text-sm text-muted-foreground mr-1">
          <CalendarDays className="w-4 h-4" />
          Period
        </div>
        {filters.map((item) => (
          <button
            key={item.id}
            onClick={() => setFilter(item.id)}
            className={`px-3 py-1.5 rounded-lg text-sm border transition-colors ${
              filter === item.id ? 'bg-primary text-primary-foreground border-primary' : 'bg-card border-border hover:bg-muted'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      <div className="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <Metric title="Revenue" value={fmt(Number(stats?.total_revenue || 0))} icon={DollarSign} />
        <Metric title="Purchases" value={Number(stats?.successful_transactions || 0).toLocaleString()} icon={BarChart3} />
        <Metric title="Unique Customers" value={uniqueCustomers.toLocaleString()} icon={Users} />
        <Metric title="Success Rate" value={`${successRate}%`} icon={Repeat} />
      </div>

      <div className="grid xl:grid-cols-3 gap-6">
        <Panel title="Top Packages">
          {(stats?.transactions_by_package || []).length === 0 ? (
            <Empty />
          ) : stats.transactions_by_package.map((row: any) => (
            <Row key={row.voucher_type} label={row.voucher_type || 'Unknown'} value={fmt(Number(row.total || 0))} meta={`${row.count} purchases`} />
          ))}
        </Panel>

        <Panel title="Sales By Site">
          {(stats?.transactions_by_origin || []).length === 0 ? (
            <Empty />
          ) : stats.transactions_by_origin.map((row: any) => (
            <Row key={row.origin_site} label={row.origin_site || 'Default Site'} value={fmt(Number(row.total || 0))} meta={`${row.count} transactions`} />
          ))}
        </Panel>

        <Panel title="Repeat Customers">
          {(stats?.repeat_customers || []).length === 0 ? (
            <Empty />
          ) : stats.repeat_customers.map((row: any) => (
            <Row key={row.msisdn} label={row.msisdn} value={fmt(Number(row.total_spent || 0))} meta={`${row.purchases} purchases`} />
          ))}
        </Panel>
      </div>

      <Panel title="Recent Purchases">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-muted-foreground">
                <th className="text-left py-3">Customer</th>
                <th className="text-left py-3">Package</th>
                <th className="text-left py-3">Site</th>
                <th className="text-right py-3">Amount</th>
                <th className="text-right py-3">Status</th>
              </tr>
            </thead>
            <tbody>
              {transactions.slice(0, 30).map((tx) => (
                <tr key={tx.id} className="border-b border-border/50">
                  <td className="py-3">{tx.msisdn}</td>
                  <td className="py-3">{tx.voucher_type || tx.voucher_code || '-'}</td>
                  <td className="py-3">{tx.origin_site || '-'}</td>
                  <td className="py-3 text-right">{fmt(Number(tx.amount || 0))}</td>
                  <td className="py-3 text-right capitalize">{tx.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
}

function Metric({ title, value, icon: Icon }: { title: string; value: string; icon: any }) {
  return (
    <div className="bg-card border border-border rounded-lg p-5">
      <Icon className="w-5 h-5 text-primary" />
      <p className="text-sm text-muted-foreground mt-3">{title}</p>
      <p className="text-2xl font-semibold mt-1">{value}</p>
    </div>
  );
}

function Panel({ title, children }: { title: string; children: any }) {
  return (
    <div className="bg-card border border-border rounded-lg p-5">
      <h2 className="font-semibold mb-4">{title}</h2>
      <div className="space-y-3">{children}</div>
    </div>
  );
}

function Row({ label, value, meta }: { label: string; value: string; meta: string }) {
  return (
    <div className="flex items-center justify-between gap-4 rounded-lg bg-muted/40 p-3">
      <div>
        <p className="font-medium">{label}</p>
        <p className="text-xs text-muted-foreground">{meta}</p>
      </div>
      <p className="font-semibold text-right">{value}</p>
    </div>
  );
}

function Empty() {
  return <p className="text-sm text-muted-foreground py-4">No data for this period.</p>;
}
