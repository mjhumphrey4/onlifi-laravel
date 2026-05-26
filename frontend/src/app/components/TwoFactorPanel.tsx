import { useEffect, useState } from 'react';
import { Shield, Copy, Check, RefreshCw } from 'lucide-react';

interface TwoFactorPanelProps {
  endpointPrefix: '/tenant' | '/super-admin';
}

export function TwoFactorPanel({ endpointPrefix }: TwoFactorPanelProps) {
  const [enabled, setEnabled] = useState(false);
  const [loading, setLoading] = useState(true);
  const [secret, setSecret] = useState('');
  const [otpUri, setOtpUri] = useState('');
  const [code, setCode] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [copied, setCopied] = useState(false);

  const headers = () => ({
    Authorization: `Bearer ${localStorage.getItem(endpointPrefix === '/super-admin' ? 'admin_token' : 'tenant_token')}`,
    'Content-Type': 'application/json',
    Accept: 'application/json',
  });

  const loadStatus = async () => {
    setLoading(true);
    try {
      const response = await fetch(`/api${endpointPrefix}/2fa/status`, { headers: headers() });
      if (response.ok) {
        const data = await response.json();
        setEnabled(Boolean(data.enabled));
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadStatus();
  }, []);

  const startSetup = async () => {
    const response = await fetch(`/api${endpointPrefix}/2fa/setup`, {
      method: 'POST',
      headers: headers(),
    });
    const data = await response.json();
    if (response.ok) {
      setSecret(data.secret);
      setOtpUri(data.otpauth_uri);
      setRecoveryCodes([]);
    } else {
      alert(data.message || 'Failed to start 2FA setup');
    }
  };

  const confirmSetup = async () => {
    const response = await fetch(`/api${endpointPrefix}/2fa/confirm`, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify({ code }),
    });
    const data = await response.json();
    if (response.ok) {
      setEnabled(true);
      setSecret('');
      setOtpUri('');
      setCode('');
      setRecoveryCodes(data.recovery_codes || []);
    } else {
      alert(data.message || 'Invalid authenticator code');
    }
  };

  const disable = async () => {
    const value = prompt('Enter your password or current authenticator code to disable 2FA');
    if (!value) return;

    const response = await fetch(`/api${endpointPrefix}/2fa/disable`, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify({ password: value, code: value }),
    });
    const data = await response.json();
    if (response.ok) {
      setEnabled(false);
      setRecoveryCodes([]);
    } else {
      alert(data.message || 'Failed to disable 2FA');
    }
  };

  const copySecret = async () => {
    await navigator.clipboard.writeText(secret);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  if (loading) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <RefreshCw className="w-4 h-4 animate-spin" />
        Loading 2FA status...
      </div>
    );
  }

  return (
    <div className="bg-card border border-border rounded-lg p-5 space-y-4">
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          <div className="p-2 bg-primary/10 rounded-lg">
            <Shield className="w-5 h-5 text-primary" />
          </div>
          <div>
            <h3 className="font-semibold text-card-foreground">Two-Factor Authentication</h3>
            <p className="text-sm text-muted-foreground">Optional authenticator-app protection for this account.</p>
          </div>
        </div>
        <span className={`text-xs px-2 py-1 rounded-full ${enabled ? 'bg-emerald-500/10 text-emerald-500' : 'bg-muted text-muted-foreground'}`}>
          {enabled ? 'Enabled' : 'Disabled'}
        </span>
      </div>

      {secret && (
        <div className="space-y-3">
          <p className="text-sm text-muted-foreground">Enter this secret in Google Authenticator, Authy, 1Password, or any TOTP app.</p>
          <div className="flex items-center gap-2">
            <code className="flex-1 bg-muted px-3 py-2 rounded text-sm break-all">{secret}</code>
            <button onClick={copySecret} className="p-2 rounded hover:bg-muted" title="Copy secret">
              {copied ? <Check className="w-4 h-4 text-emerald-500" /> : <Copy className="w-4 h-4" />}
            </button>
          </div>
          <code className="block bg-muted px-3 py-2 rounded text-xs break-all">{otpUri}</code>
          <div className="flex gap-2">
            <input
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="6-digit code"
              inputMode="numeric"
              className="flex-1 px-3 py-2 bg-background border border-border rounded-lg"
            />
            <button onClick={confirmSetup} className="px-4 py-2 bg-primary text-primary-foreground rounded-lg">
              Confirm
            </button>
          </div>
        </div>
      )}

      {recoveryCodes.length > 0 && (
        <div className="bg-muted rounded-lg p-3">
          <p className="text-sm font-medium mb-2">Recovery codes</p>
          <div className="grid grid-cols-2 gap-2">
            {recoveryCodes.map((item) => <code key={item} className="text-xs">{item}</code>)}
          </div>
        </div>
      )}

      {!secret && (
        <button
          onClick={enabled ? disable : startSetup}
          className={`px-4 py-2 rounded-lg ${enabled ? 'bg-destructive text-destructive-foreground' : 'bg-primary text-primary-foreground'}`}
        >
          {enabled ? 'Disable 2FA' : 'Enable 2FA'}
        </button>
      )}
    </div>
  );
}
