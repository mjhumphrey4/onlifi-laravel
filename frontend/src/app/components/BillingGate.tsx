import { FormEvent, ReactNode, useEffect, useMemo, useState } from 'react';
import { CreditCard, Loader2, Phone, RefreshCw, ShieldCheck } from 'lucide-react';
import { checkSubscriptionPaymentStatus, getTenantBillingStatus, initiateSubscriptionPayment } from '../utils/api';

interface BillingStatus {
  state: 'active' | 'trial' | 'subscribed' | 'expired';
  requires_payment: boolean;
  services_active: boolean;
  monthly_amount: number;
  currency: string;
  renewal_months: number;
  current_period_ends_at?: string | null;
  subscription_ends_at?: string | null;
  trial_ends_at?: string | null;
}

export function BillingGate({ children }: { children: ReactNode }) {
  const [billing, setBilling] = useState<BillingStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [phone, setPhone] = useState('');
  const [months, setMonths] = useState(1);
  const [paymentRef, setPaymentRef] = useState<string | null>(null);
  const [paymentMessage, setPaymentMessage] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const amount = useMemo(() => {
    if (!billing) return 0;
    return billing.monthly_amount * months;
  }, [billing, months]);

  const fetchBilling = async () => {
    setError('');
    try {
      const response = await getTenantBillingStatus();
      setBilling(response.billing);
      setMonths(Math.max(1, response.billing?.renewal_months || 1));
    } catch (err: any) {
      setError(err.message || 'Unable to load billing status');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchBilling();
  }, []);

  useEffect(() => {
    if (!paymentRef) return;

    const interval = window.setInterval(async () => {
      try {
        const response = await checkSubscriptionPaymentStatus(paymentRef);
        if (response.transactionStatus === 1) {
          setPaymentMessage('Payment confirmed. Restoring dashboard...');
          setPaymentRef(null);
          await fetchBilling();
        } else if (response.transactionStatus === -1) {
          setPaymentMessage(response.errorMessage || response.statusMessage || 'Payment failed. Please try again.');
          setPaymentRef(null);
        }
      } catch (err: any) {
        setPaymentMessage(err.message || 'Waiting for payment confirmation...');
      }
    }, 5000);

    return () => window.clearInterval(interval);
  }, [paymentRef]);

  const handleSubscribe = async (event: FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError('');
    setPaymentMessage('');

    try {
      const response = await initiateSubscriptionPayment({ msisdn: phone, months });
      setPaymentRef(response.externalReference || response.transactionReference);
      setPaymentMessage(response.message || 'Confirm the mobile money prompt on your phone.');
    } catch (err: any) {
      setError(err.message || 'Payment initiation failed');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-primary animate-spin" />
      </div>
    );
  }

  if (!billing?.requires_payment) {
    return <>{children}</>;
  }

  return (
    <div className="min-h-screen bg-background flex items-center justify-center p-4">
      <div className="w-full max-w-xl bg-card border border-border rounded-lg shadow-xl overflow-hidden">
        <div className="p-6 border-b border-border">
          <div className="flex items-center gap-3">
            <div className="w-11 h-11 rounded-lg bg-primary/10 flex items-center justify-center">
              <CreditCard className="w-5 h-5 text-primary" />
            </div>
            <div>
              <h1 className="text-xl font-semibold text-card-foreground">Subscription renewal required</h1>
              <p className="text-sm text-muted-foreground mt-1">Your routers, vouchers, and RADIUS services remain active.</p>
            </div>
          </div>
        </div>

        <form onSubmit={handleSubscribe} className="p-6 space-y-5">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div className="sm:col-span-2 rounded-lg border border-border p-4">
              <p className="text-xs text-muted-foreground">Amount due</p>
              <p className="text-2xl font-semibold text-card-foreground mt-1">
                {billing.currency} {amount.toLocaleString()}
              </p>
            </div>
            <div className="rounded-lg border border-border p-4">
              <label className="text-xs text-muted-foreground" htmlFor="renewal-months">Months</label>
              <input
                id="renewal-months"
                type="number"
                min={1}
                max={12}
                value={months}
                onChange={(event) => setMonths(Math.max(1, Math.min(12, Number(event.target.value) || 1)))}
                className="mt-1 w-full bg-background border border-input rounded-md px-3 py-2 text-foreground"
              />
            </div>
          </div>

          <label className="block">
            <span className="text-sm font-medium text-card-foreground">Mobile money phone number</span>
            <div className="mt-2 relative">
              <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
              <input
                type="tel"
                value={phone}
                onChange={(event) => setPhone(event.target.value)}
                placeholder="2567XXXXXXXX"
                required
                className="w-full pl-10 pr-3 py-3 bg-background border border-input rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
              />
            </div>
          </label>

          {paymentMessage && (
            <div className="flex items-start gap-3 rounded-lg bg-primary/10 border border-primary/20 p-3 text-sm text-primary">
              <ShieldCheck className="w-4 h-4 mt-0.5 flex-shrink-0" />
              <span>{paymentMessage}</span>
            </div>
          )}

          {error && (
            <div className="rounded-lg bg-destructive/10 border border-destructive/20 p-3 text-sm text-destructive">
              {error}
            </div>
          )}

          <div className="flex flex-col sm:flex-row gap-3">
            <button
              type="submit"
              disabled={submitting || !!paymentRef}
              className="flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 disabled:opacity-60"
            >
              {submitting || paymentRef ? <Loader2 className="w-4 h-4 animate-spin" /> : <CreditCard className="w-4 h-4" />}
              {paymentRef ? 'Waiting for confirmation' : 'Pay with mobile money'}
            </button>
            <button
              type="button"
              onClick={fetchBilling}
              className="inline-flex items-center justify-center gap-2 px-4 py-3 border border-border text-card-foreground rounded-lg hover:bg-muted"
            >
              <RefreshCw className="w-4 h-4" />
              Refresh
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
