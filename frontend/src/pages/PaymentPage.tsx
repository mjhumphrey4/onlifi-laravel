import React, { useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Loader2 } from 'lucide-react';

const CURRENT_ORIGIN_SITE = 'SiteA';
const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

interface PaymentStatus {
  type: 'info' | 'success' | 'error' | null;
  message: string;
}

export default function PaymentPage() {
  const [amount, setAmount] = useState('200');
  const [msisdn, setMsisdn] = useState('');
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState<PaymentStatus>({ type: null, message: '' });
  const [voucherCode, setVoucherCode] = useState('');
  const [pollInterval, setPollInterval] = useState<NodeJS.Timeout | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!/^256\d{9}$/.test(msisdn)) {
      setStatus({
        type: 'error',
        message: 'Invalid phone number format. Use 256XXXXXXXXX (e.g., 256771234567).',
      });
      return;
    }

    setLoading(true);
    setStatus({ type: 'info', message: 'Initiating payment...' });
    setVoucherCode('');

    if (pollInterval) {
      clearInterval(pollInterval);
    }

    try {
      const response = await fetch(`${API_BASE_URL}/payments/initiate`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: parseFloat(amount),
          msisdn: msisdn.trim(),
          origin_site: CURRENT_ORIGIN_SITE,
          client_mac: '00:00:00:00:00:00',
          voucher_type: `Feature_${amount}`,
          origin_url: window.location.href,
        }),
      });

      const data = await response.json();

      if (data.status === -1) {
        setStatus({ type: 'error', message: data.errorMessage || 'Payment initiation failed' });
        setLoading(false);
      } else if (data.status === 1) {
        setStatus({
          type: 'info',
          message: '📱 Check your phone to confirm payment. Waiting for confirmation...',
        });
        startPolling(data.transactionReference);
      } else {
        setStatus({ type: 'error', message: 'Payment initiation returned unexpected status.' });
        setLoading(false);
      }
    } catch (err) {
      setStatus({ type: 'error', message: 'Network error during initiation. Please try again.' });
      setLoading(false);
    }
  };

  const startPolling = (ref: string) => {
    let pollCount = 0;
    const MAX_POLLS = 25;

    const interval = setInterval(async () => {
      pollCount++;

      if (pollCount > MAX_POLLS) {
        clearInterval(interval);
        setStatus({
          type: 'error',
          message: 'Payment confirmation timeout. Please contact support if you were charged.',
        });
        setLoading(false);
        return;
      }

      try {
        const response = await fetch(`${API_BASE_URL}/payments/check-status?ref=${encodeURIComponent(ref)}&t=${Date.now()}`);
        const data = await response.json();

        if (data.transactionStatus === 1) {
          clearInterval(interval);
          setStatus({ type: 'success', message: '✅ Payment successful! Feature unlocked.' });
          setVoucherCode(data.voucherCode || '');
          setLoading(false);
        } else if (data.transactionStatus < 0) {
          clearInterval(interval);
          setStatus({ type: 'error', message: data.errorMessage || 'Payment failed' });
          setLoading(false);
        } else {
          setStatus({
            type: 'info',
            message: `⏳ Waiting for payment confirmation... (${pollCount * 5}s)`,
          });
        }
      } catch (err) {
        setStatus({
          type: 'info',
          message: `⏳ Network issue during check... retrying (${pollCount * 5}s)`,
        });
      }
    }, 5000);

    setPollInterval(interval);
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-purple-600 to-purple-900 flex items-center justify-center p-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-2xl text-center">🎯 Feature Payment</CardTitle>
          <CardDescription className="text-center">Pay with Mobile Money</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="feature">Select Feature</Label>
              <Select value={amount} onValueChange={setAmount}>
                <SelectTrigger id="feature">
                  <SelectValue placeholder="Select a feature" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="200">Feature A - UGX 200</SelectItem>
                  <SelectItem value="1000">Feature B - UGX 1,000</SelectItem>
                  <SelectItem value="2000">Feature C - UGX 2,000</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="phone">Phone Number</Label>
              <Input
                id="phone"
                type="tel"
                placeholder="256771234567"
                pattern="256[0-9]{9}"
                value={msisdn}
                onChange={(e) => setMsisdn(e.target.value)}
                required
              />
              <p className="text-xs text-muted-foreground">Format: 256XXXXXXXXX (e.g., 256771234567)</p>
            </div>

            <Button type="submit" className="w-full" disabled={loading}>
              {loading ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Processing...
                </>
              ) : (
                'Pay Now'
              )}
            </Button>
          </form>

          {status.type && (
            <Alert className={`mt-4 ${status.type === 'success' ? 'border-green-500' : status.type === 'error' ? 'border-red-500' : 'border-blue-500'}`}>
              <AlertDescription>{status.message}</AlertDescription>
            </Alert>
          )}

          {voucherCode && (
            <div className="mt-6 p-4 bg-gradient-to-r from-purple-600 to-purple-800 rounded-lg text-white text-center">
              <h3 className="text-lg font-semibold mb-2">🎉 Feature Unlocked!</h3>
              <p className="mb-2">Your voucher code:</p>
              <p className="text-2xl font-bold tracking-wider">{voucherCode}</p>
              <p className="text-sm mt-2 opacity-90">You now have access to premium content!</p>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
