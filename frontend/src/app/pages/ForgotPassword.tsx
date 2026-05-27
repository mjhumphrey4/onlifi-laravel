import { FormEvent, useState } from 'react';
import { Link } from 'react-router';
import { Mail, ArrowLeft, Loader2 } from 'lucide-react';
import { tenantForgotPassword } from '../utils/api';

export function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
    setLoading(true);

    try {
      const response = await tenantForgotPassword(email.trim());
      setMessage(response.message || 'If that email is registered, a reset link has been sent.');
    } catch (err: any) {
      setError(err.message || 'Failed to send reset link.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-background flex items-center justify-center p-4">
      <div className="w-full max-w-md bg-card border border-border rounded-xl p-8">
        <Link to="/login" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground mb-6">
          <ArrowLeft className="w-4 h-4" />
          Back to sign in
        </Link>
        <div className="w-12 h-12 rounded-lg bg-primary/10 text-primary grid place-items-center mb-5">
          <Mail className="w-6 h-6" />
        </div>
        <h1 className="text-2xl font-semibold">Reset your password</h1>
        <p className="text-sm text-muted-foreground mt-2">Enter your registered email address and we will send a reset link.</p>

        {message && <div className="mt-5 rounded-lg border border-green-500/30 bg-green-500/10 text-green-700 p-3 text-sm">{message}</div>}
        {error && <div className="mt-5 rounded-lg border border-destructive/20 bg-destructive/10 text-destructive p-3 text-sm">{error}</div>}

        <form onSubmit={submit} className="mt-6 space-y-4">
          <label className="block text-sm">
            <span className="text-card-foreground">Email</span>
            <input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              className="mt-2 w-full px-4 py-3 bg-input-background border border-border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              placeholder="you@example.com"
              required
            />
          </label>
          <button disabled={loading} className="w-full inline-flex items-center justify-center gap-2 py-3 bg-primary text-primary-foreground rounded-lg font-medium hover:bg-primary/90 disabled:opacity-60">
            {loading && <Loader2 className="w-4 h-4 animate-spin" />}
            Send Reset Link
          </button>
        </form>
      </div>
    </div>
  );
}
