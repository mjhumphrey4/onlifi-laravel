import { FormEvent, useEffect, useState } from 'react';
import { CreditCard, Loader2, MessageSquare, RefreshCw } from 'lucide-react';
import { checkSmsCreditPaymentStatus, getSmsCredits, topUpSmsCredits, updateSmsPlan } from '../utils/api';

export function SmsGateway() {
  const [summary, setSummary] = useState<any>(null);
  const [phone, setPhone] = useState('');
  const [amount, setAmount] = useState('5000');
  const [loading, setLoading] = useState(true);
  const [paying, setPaying] = useState(false);
  const [paymentRef, setPaymentRef] = useState<string | null>(null);
  const [message, setMessage] = useState('');

  const load = async () => {
    setLoading(true);
    try {
      setSummary(await getSmsCredits());
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  useEffect(() => {
    if (!paymentRef) return;

    const interval = window.setInterval(async () => {
      const status = await checkSmsCreditPaymentStatus(paymentRef);
      if (status.transactionStatus === 1) {
        setMessage('SMS credits added successfully.');
        setPaymentRef(null);
        await load();
      } else if (status.transactionStatus === -1) {
        setMessage(status.errorMessage || status.statusMessage || 'Payment failed.');
        setPaymentRef(null);
      }
    }, 5000);

    return () => window.clearInterval(interval);
  }, [paymentRef]);

  const topUp = async (event: FormEvent) => {
    event.preventDefault();
    setPaying(true);
    setMessage('');
    try {
      const response = await topUpSmsCredits({ msisdn: phone, amount: Number(amount) });
      setPaymentRef(response.externalReference || response.transactionReference);
      setMessage(response.message || 'Confirm the prompt on your phone.');
    } catch (error: any) {
      setMessage(error.message || 'Failed to start payment');
    } finally {
      setPaying(false);
    }
  };

  const togglePlan = async () => {
    const next = !summary?.sms_enabled;
    await updateSmsPlan(next);
    setSummary({ ...summary, sms_enabled: next });
  };

  if (loading) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  const estimatedCredits = Math.floor(Number(amount || 0) / Number(summary?.credit_price || 35));

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">SMS Gateway</h1>
          <p className="text-muted-foreground mt-1">Manage SMS credits for voucher delivery and customer notifications.</p>
        </div>
        <div className="flex gap-2">
          <button onClick={togglePlan} className={`px-4 py-2 rounded-lg border ${summary?.sms_enabled ? 'border-green-500 text-green-600' : 'border-border text-muted-foreground'} hover:bg-muted`}>
            {summary?.sms_enabled ? 'SMS Enabled' : 'SMS Disabled'}
          </button>
          <button onClick={load} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
            <RefreshCw className="w-4 h-4" />
            Refresh
          </button>
        </div>
      </div>

      <div className="grid lg:grid-cols-[1fr_420px] gap-6">
        <div className="grid sm:grid-cols-2 gap-4">
          <div className="bg-card border border-border rounded-lg p-6">
            <MessageSquare className="w-7 h-7 text-primary" />
            <p className="text-sm text-muted-foreground mt-4">Available credits</p>
            <p className="text-4xl font-semibold mt-1">{Number(summary?.credits || 0).toLocaleString()}</p>
          </div>
          <div className="bg-card border border-border rounded-lg p-6">
            <CreditCard className="w-7 h-7 text-primary" />
            <p className="text-sm text-muted-foreground mt-4">Credit price</p>
            <p className="text-4xl font-semibold mt-1">{summary?.currency || 'UGX'} {Number(summary?.credit_price || 0).toLocaleString()}</p>
          </div>
          <div className="sm:col-span-2 bg-card border border-border rounded-lg p-6">
            <h2 className="font-semibold mb-3">Recent top-ups</h2>
            <div className="divide-y divide-border">
              {(summary?.recent_transactions || []).length === 0 ? (
                <p className="text-sm text-muted-foreground py-4">No SMS credit top-ups yet.</p>
              ) : summary.recent_transactions.map((txn: any) => (
                <div key={txn.id} className="py-3 flex items-center justify-between">
                  <div>
                    <p className="font-medium">{txn.msisdn}</p>
                    <p className="text-sm text-muted-foreground">{txn.status}</p>
                  </div>
                  <div className="text-right">
                    <p>{Number(txn.credits).toLocaleString()} credits</p>
                    <p className="text-sm text-muted-foreground">{summary.currency} {Number(txn.amount).toLocaleString()}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        <form onSubmit={topUp} className="bg-card border border-border rounded-lg p-6 space-y-4">
          <h2 className="font-semibold">Top up SMS credits</h2>
          <label className="block text-sm">
            <span className="text-muted-foreground">Mobile money number</span>
            <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="2567XXXXXXXX" className="mt-1 w-full px-3 py-2 rounded-lg bg-background border border-input" required />
          </label>
          <label className="block text-sm">
            <span className="text-muted-foreground">Amount</span>
            <input type="number" min={100} value={amount} onChange={(e) => setAmount(e.target.value)} className="mt-1 w-full px-3 py-2 rounded-lg bg-background border border-input" required />
          </label>
          <div className="rounded-lg bg-muted p-3 text-sm">
            Estimated credits: <strong>{estimatedCredits.toLocaleString()}</strong>
          </div>
          {message && <div className="rounded-lg border border-border p-3 text-sm">{message}</div>}
          <button disabled={!summary?.sms_enabled || paying || !!paymentRef} className="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-60">
            {paying || paymentRef ? <Loader2 className="w-4 h-4 animate-spin" /> : <CreditCard className="w-4 h-4" />}
            {paymentRef ? 'Waiting for confirmation' : 'Top up with Yo Mobile Money'}
          </button>
          {!summary?.sms_enabled && (
            <p className="text-sm text-muted-foreground">Enable the SMS plan before buying credits or sending voucher SMS messages.</p>
          )}
        </form>
      </div>
    </div>
  );
}
