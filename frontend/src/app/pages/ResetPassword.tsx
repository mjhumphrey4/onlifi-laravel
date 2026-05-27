import { FormEvent, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { ArrowLeft, Loader2, LockKeyhole } from 'lucide-react';
import { tenantResetPassword } from '../utils/api';

export function ResetPassword() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const emailFromLink = useMemo(() => params.get('email') || '', [params]);
  const token = useMemo(() => params.get('token') || '', [params]);
  const [email, setEmail] = useState(emailFromLink);
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
    setLoading(true);

    try {
      const response = await tenantResetPassword(email.trim(), token, password, confirmation);
      setMessage(response.message || 'Password reset successfully.');
      window.setTimeout(() => navigate('/login'), 1500);
    } catch (err: any) {
      setError(err.message || 'Failed to reset password.');
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
          <LockKeyhole className="w-6 h-6" />
        </div>
        <h1 className="text-2xl font-semibold">Create a new password</h1>
        <p className="text-sm text-muted-foreground mt-2">Use at least 8 characters.</p>

        {!token && <div className="mt-5 rounded-lg border border-destructive/20 bg-destructive/10 text-destructive p-3 text-sm">Missing reset token. Request a fresh reset link.</div>}
        {message && <div className="mt-5 rounded-lg border border-green-500/30 bg-green-500/10 text-green-700 p-3 text-sm">{message}</div>}
        {error && <div className="mt-5 rounded-lg border border-destructive/20 bg-destructive/10 text-destructive p-3 text-sm">{error}</div>}

        <form onSubmit={submit} className="mt-6 space-y-4">
          <label className="block text-sm">
            <span className="text-card-foreground">Email</span>
            <input value={email} onChange={(event) => setEmail(event.target.value)} type="email" className="mt-2 w-full px-4 py-3 bg-input-background border border-border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-ring" required />
          </label>
          <label className="block text-sm">
            <span className="text-card-foreground">New password</span>
            <input value={password} onChange={(event) => setPassword(event.target.value)} type="password" className="mt-2 w-full px-4 py-3 bg-input-background border border-border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-ring" required minLength={8} />
          </label>
          <label className="block text-sm">
            <span className="text-card-foreground">Confirm password</span>
            <input value={confirmation} onChange={(event) => setConfirmation(event.target.value)} type="password" className="mt-2 w-full px-4 py-3 bg-input-background border border-border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-ring" required minLength={8} />
          </label>
          <button disabled={loading || !token} className="w-full inline-flex items-center justify-center gap-2 py-3 bg-primary text-primary-foreground rounded-lg font-medium hover:bg-primary/90 disabled:opacity-60">
            {loading && <Loader2 className="w-4 h-4 animate-spin" />}
            Reset Password
          </button>
        </form>
      </div>
    </div>
  );
}
