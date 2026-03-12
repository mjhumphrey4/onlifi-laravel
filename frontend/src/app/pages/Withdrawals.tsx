import { useState, useEffect, useCallback } from 'react';
import { ChevronLeft, ChevronRight, Download, Plus, X, RefreshCw } from 'lucide-react';
import { apiWithdrawals, apiRequestWithdrawal, apiStats } from '../utils/api';
import { useAuth } from '../context/AuthContext';

const ITEMS_PER_PAGE = 15;

interface WithdrawalRow {
  id: string;
  transaction_reference: string;
  username: string;
  phone_number: string;
  amount: number;
  status: string;
  created_at: string;
  site_label: string;
}

function fmt(n: number) { return 'UGX ' + Math.round(n).toLocaleString(); }

function statusStyle(s: string) {
  switch (s?.toUpperCase()) {
    case 'SUCCEEDED': return 'bg-primary/10 text-primary';
    case 'PENDING':   return 'bg-yellow-500/10 text-yellow-500';
    case 'FAILED':    return 'bg-destructive/10 text-destructive';
    default:          return 'bg-muted text-muted-foreground';
  }
}

export function Withdrawals() {
  const { userSites, isAdmin } = useAuth();
  const sites = userSites();

  const [withdrawals, setWithdrawals] = useState<WithdrawalRow[]>([]);
  const [total, setTotal] = useState(0);
  const [currentPage, setCurrentPage] = useState(1);
  const [loading, setLoading] = useState(true);

  // Summary
  const [totalWithdrawn, setTotalWithdrawn] = useState(0);
  const [pendingAmount, setPendingAmount] = useState(0);
  const [balances, setBalances] = useState<Record<string, number>>({});

  // Request form
  const [showForm, setShowForm] = useState(false);
  const [formSite, setFormSite] = useState(sites[0] ?? '');
  const [formAmount, setFormAmount] = useState('');
  const [formPhone, setFormPhone] = useState('');
  const [formError, setFormError] = useState('');
  const [formSuccess, setFormSuccess] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const loadWithdrawals = useCallback(async (page: number) => {
    setLoading(true);
    try {
      const res = await apiWithdrawals({ page, limit: ITEMS_PER_PAGE });
      setWithdrawals(res.withdrawals ?? []);
      setTotal(res.total ?? 0);
    } catch (e) { console.error(e); }
    finally { setLoading(false); }
  }, []);

  const loadStats = useCallback(async () => {
    try {
      const res = await apiStats();
      const siteData: Record<string, { withdrawn: number; pending_withdraw: number; balance: number }> = res.sites ?? {};
      let tw = 0, pa = 0;
      const bal: Record<string, number> = {};
      Object.entries(siteData).forEach(([site, d]) => {
        tw += d.withdrawn;
        pa += d.pending_withdraw;
        bal[site] = d.balance;
      });
      setTotalWithdrawn(tw);
      setPendingAmount(pa);
      setBalances(bal);
    } catch (e) { console.error(e); }
  }, []);

  useEffect(() => { loadWithdrawals(currentPage); }, [currentPage, loadWithdrawals]);
  useEffect(() => { loadStats(); }, [loadStats]);

  const availableBalance = balances[formSite] ?? 0;
  const enteredAmount = parseFloat(formAmount);
  const amountExceedsBalance = !isNaN(enteredAmount) && enteredAmount > availableBalance;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError('');
    setFormSuccess('');
    const amount = parseFloat(formAmount);
    if (!formSite) { setFormError('Please select a site'); return; }
    if (isNaN(amount) || amount < 1000) { setFormError('Minimum withdrawal is UGX 1,000'); return; }
    if (amount > availableBalance) { setFormError(`Amount exceeds available balance of ${fmt(availableBalance)}`); return; }
    if (!/^\d{10,12}$/.test(formPhone)) { setFormError('Enter a valid phone number (10–12 digits)'); return; }
    setSubmitting(true);
    try {
      const res = await apiRequestWithdrawal({ site: formSite, amount, phone: formPhone });
      setFormSuccess(`Request submitted! ID: ${res.transaction_id}`);
      setFormAmount('');
      setFormPhone('');
      loadWithdrawals(1);
      loadStats();
      setCurrentPage(1);
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : 'Submission failed');
    } finally { setSubmitting(false); }
  };

  const totalPages = Math.ceil(total / ITEMS_PER_PAGE);

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-2">Withdrawals</h1>
          <p className="text-sm sm:text-base text-muted-foreground">Track and request withdrawal transactions</p>
        </div>
        <button onClick={() => { setShowForm((v) => !v); setFormError(''); setFormSuccess(''); }}
          className="flex items-center gap-2 px-4 py-2.5 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors text-sm font-medium">
          {showForm ? <><X className="w-4 h-4" /> Cancel</> : <><Plus className="w-4 h-4" /> Request Withdrawal</>}
        </button>
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 mb-6">
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <p className="text-xs sm:text-sm text-muted-foreground mb-1">Total Withdrawn</p>
          <h3 className="text-2xl sm:text-3xl text-card-foreground font-semibold">{fmt(totalWithdrawn)}</h3>
        </div>
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <p className="text-xs sm:text-sm text-muted-foreground mb-1">Pending Withdrawals</p>
          <h3 className="text-2xl sm:text-3xl text-yellow-500 font-semibold">{fmt(pendingAmount)}</h3>
        </div>
      </div>

      {/* Balance per site */}
      {Object.keys(balances).length > 0 && (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
          {Object.entries(balances).map(([site, bal]) => (
            <div key={site} className="bg-card border border-border rounded-lg p-3 text-center">
              <p className="text-xs text-muted-foreground mb-1">{site}</p>
              <p className="text-sm font-semibold text-primary">{fmt(bal)}</p>
              <p className="text-xs text-muted-foreground">available</p>
            </div>
          ))}
        </div>
      )}

      {/* Request form */}
      {showForm && (
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6 mb-6">
          <h2 className="text-lg text-card-foreground mb-4">New Withdrawal Request</h2>
          {formError && <div className="mb-4 p-3 rounded-lg bg-destructive/10 border border-destructive/20 text-destructive text-sm">{formError}</div>}
          {formSuccess && <div className="mb-4 p-3 rounded-lg bg-primary/10 border border-primary/20 text-primary text-sm">{formSuccess}</div>}
          <form onSubmit={handleSubmit} className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {(isAdmin() || sites.length > 1) && (
              <div>
                <label className="block text-sm text-card-foreground mb-2">Site</label>
                <select value={formSite} onChange={(e) => setFormSite(e.target.value)} required
                  className="w-full px-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm">
                  {sites.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
              </div>
            )}
            <div>
              <label className="block text-sm text-card-foreground mb-2">Amount (UGX)</label>
              <input type="number" min="1000" step="500" value={formAmount} onChange={(e) => setFormAmount(e.target.value)} required
                placeholder="e.g. 50000"
                className={`w-full px-4 py-2.5 bg-input-background border rounded-lg text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm ${amountExceedsBalance ? 'border-destructive focus:ring-destructive' : 'border-border'}`} />
              <p className={`text-xs mt-1 ${amountExceedsBalance ? 'text-destructive font-medium' : 'text-muted-foreground'}`}>
                {amountExceedsBalance ? `Exceeds balance! Max: ${fmt(availableBalance)}` : `Available: ${fmt(availableBalance)} · Min: UGX 1,000`}
              </p>
            </div>
            <div>
              <label className="block text-sm text-card-foreground mb-2">Phone Number</label>
              <input type="tel" value={formPhone} onChange={(e) => setFormPhone(e.target.value)} required
                placeholder="256XXXXXXXXX"
                className="w-full px-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm" />
              <p className="text-xs text-muted-foreground mt-1">Format: 256XXXXXXXXX</p>
            </div>
            <div className="sm:col-span-3">
              <button type="submit" disabled={submitting || amountExceedsBalance}
                className="px-6 py-2.5 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors text-sm font-medium disabled:opacity-60">
                {submitting ? 'Submitting…' : 'Submit Request'}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* History table */}
      <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
        <div className="flex items-center justify-between mb-6">
          <h2 className="text-lg sm:text-xl text-card-foreground">Withdrawal History</h2>
          <button onClick={() => window.print()}
            className="flex items-center gap-2 px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors text-sm">
            <Download className="w-4 h-4" /> Export
          </button>
        </div>

        <div className="overflow-x-auto -mx-4 sm:mx-0 mb-6">
          <div className="inline-block min-w-full align-middle">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border">
                  {['Transaction ID', ...(isAdmin() ? ['Site'] : []), 'Phone', 'Amount', 'Status', 'Date'].map((h) => (
                    <th key={h} className="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr><td colSpan={isAdmin() ? 6 : 5} className="py-10 text-center"><RefreshCw className="w-5 h-5 text-primary animate-spin mx-auto" /></td></tr>
                ) : withdrawals.length === 0 ? (
                  <tr><td colSpan={isAdmin() ? 6 : 5} className="py-8 text-center text-muted-foreground text-sm">No withdrawals found.</td></tr>
                ) : withdrawals.map((w, i) => (
                  <tr key={`${w.id}-${i}`} className="border-b border-border/50 hover:bg-muted/50 transition-colors">
                    <td className="py-3 px-2 sm:px-4 text-xs text-muted-foreground whitespace-nowrap font-mono">{w.transaction_reference}</td>
                    {isAdmin() && <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{w.username}</td>}
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">{w.phone_number}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap font-semibold">{fmt(w.amount)}</td>
                    <td className="py-3 px-2 sm:px-4 whitespace-nowrap">
                      <span className={`inline-block px-2 py-1 rounded-full text-xs capitalize ${statusStyle(w.status)}`}>{w.status}</span>
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {new Date(w.created_at).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {totalPages > 1 && (
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p className="text-xs sm:text-sm text-muted-foreground">
              Showing {(currentPage - 1) * ITEMS_PER_PAGE + 1}–{Math.min(currentPage * ITEMS_PER_PAGE, total)} of {total}
            </p>
            <div className="flex gap-2">
              <button onClick={() => setCurrentPage((p) => Math.max(1, p - 1))} disabled={currentPage === 1}
                className="px-3 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm">
                <ChevronLeft className="w-4 h-4" />
              </button>
              {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
                const p = totalPages <= 5 ? i + 1 : currentPage <= 3 ? i + 1 : currentPage >= totalPages - 2 ? totalPages - 4 + i : currentPage - 2 + i;
                return (
                  <button key={p} onClick={() => setCurrentPage(p)}
                    className={`w-9 h-9 rounded-lg text-xs sm:text-sm ${currentPage === p ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'}`}>
                    {p}
                  </button>
                );
              })}
              <button onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))} disabled={currentPage === totalPages}
                className="px-3 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 disabled:opacity-50 flex items-center gap-1 text-xs sm:text-sm">
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
