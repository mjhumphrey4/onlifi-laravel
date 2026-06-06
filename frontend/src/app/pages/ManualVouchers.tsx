import { useEffect, useMemo, useState } from 'react';
import { CheckCircle, Clock, Database, RefreshCw, Ticket, Zap } from 'lucide-react';
import { createManualVoucher, getVoucherTypes } from '../utils/api';
import { useSite } from '../context/SiteContext';

interface VoucherType {
  id: number;
  type_name: string;
  duration_hours: number;
  validity_minutes?: number | null;
  base_amount: number;
  description?: string | null;
  data_limit_mb?: number | null;
  speed_limit_kbps?: number | null;
  is_active?: boolean | number;
}

interface CreatedVoucher {
  voucher_code: string;
  status: string;
  price?: string | number | null;
  group?: {
    group_name?: string | null;
  } | null;
}

function formatDuration(type?: Pick<VoucherType, 'duration_hours' | 'validity_minutes'> | null) {
  if (!type) return '-';
  const minutes = type.validity_minutes || type.duration_hours * 60;

  if (minutes >= 1440 && minutes % 1440 === 0) {
    const days = minutes / 1440;
    return `${days} day${days === 1 ? '' : 's'}`;
  }

  if (minutes >= 60 && minutes % 60 === 0) {
    return `${minutes / 60}h`;
  }

  return `${minutes} min`;
}

function formatCurrency(value?: string | number | null) {
  const amount = Number(value ?? 0);
  return new Intl.NumberFormat('en-UG', {
    style: 'currency',
    currency: 'UGX',
    minimumFractionDigits: 0,
  }).format(Number.isFinite(amount) ? amount : 0);
}

function formatSpeed(kbps?: number | null) {
  if (!kbps) return 'Unlimited';
  if (kbps >= 1024) return `${(kbps / 1024).toLocaleString(undefined, { maximumFractionDigits: 1 })} Mbps`;
  return `${kbps} Kbps`;
}

export function ManualVouchers() {
  const { selectedSite } = useSite();
  const [voucherTypes, setVoucherTypes] = useState<VoucherType[]>([]);
  const [voucherCode, setVoucherCode] = useState('');
  const [voucherTypeId, setVoucherTypeId] = useState('');
  const [loadingTypes, setLoadingTypes] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [createdVoucher, setCreatedVoucher] = useState<CreatedVoucher | null>(null);

  useEffect(() => {
    loadTypes();
  }, [selectedSite?.id]);

  const loadTypes = async () => {
    setLoadingTypes(true);
    setError('');

    try {
      const response = await getVoucherTypes();
      const types = response.types || (Array.isArray(response) ? response : []);
      setVoucherTypes(types.filter((type: VoucherType) => type.is_active !== false && type.is_active !== 0));
    } catch (loadError) {
      setVoucherTypes([]);
      setError(loadError instanceof Error ? loadError.message : 'Failed to load voucher types');
    } finally {
      setLoadingTypes(false);
    }
  };

  const selectedType = useMemo(
    () => voucherTypes.find((type) => String(type.id) === voucherTypeId) || null,
    [voucherTypeId, voucherTypes]
  );

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (!selectedSite?.id) {
      setError('Please select a site before creating a manual voucher.');
      return;
    }

    if (!voucherTypeId) {
      setError('Please choose a voucher type.');
      return;
    }

    const cleanCode = voucherCode.trim().toUpperCase();
    if (!cleanCode) {
      setError('Enter the voucher code to create.');
      return;
    }

    setSaving(true);
    setError('');
    setSuccess('');
    setCreatedVoucher(null);

    try {
      const response = await createManualVoucher({
        voucher_code: cleanCode,
        voucher_type_id: Number(voucherTypeId),
      });

      setCreatedVoucher(response.voucher || null);
      setSuccess(response.message || 'Manual voucher created successfully.');
      setVoucherCode('');
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : 'Failed to create manual voucher');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-2">Manual Vouchers</h1>
          <p className="text-sm sm:text-base text-muted-foreground">
            Create one voucher code at a time using an existing voucher type for validity and limits.
          </p>
        </div>
        <button
          onClick={loadTypes}
          disabled={loadingTypes}
          className="inline-flex items-center justify-center gap-2 px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors text-sm disabled:opacity-50"
        >
          <RefreshCw className={`w-4 h-4 ${loadingTypes ? 'animate-spin' : ''}`} />
          Refresh Types
        </button>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_380px] gap-6">
        <form onSubmit={handleSubmit} className="bg-card border border-border rounded-lg p-5 sm:p-6 space-y-6">
          <div>
            <label className="block text-sm font-medium text-card-foreground mb-2">
              Voucher Code
            </label>
            <input
              type="text"
              value={voucherCode}
              onChange={(event) => setVoucherCode(event.target.value.toUpperCase())}
              placeholder="Enter custom voucher code"
              className="w-full px-3 py-2.5 bg-input-background border border-border rounded-lg text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm font-mono tracking-wider uppercase"
              maxLength={64}
              disabled={saving}
            />
            <p className="text-xs text-muted-foreground mt-2">
              Use letters, numbers, dots, dashes, or underscores. The voucher password will match this code.
            </p>
          </div>

          <div>
            <label className="block text-sm font-medium text-card-foreground mb-2">
              Validity / Voucher Type
            </label>
            <select
              value={voucherTypeId}
              onChange={(event) => setVoucherTypeId(event.target.value)}
              className="w-full px-3 py-2.5 bg-input-background border border-border rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm"
              disabled={saving || loadingTypes}
            >
              <option value="">Select a voucher type</option>
              {voucherTypes.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.type_name} - {formatDuration(type)} - {formatCurrency(type.base_amount)}
                </option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground mt-2">
              The selected type controls duration, price, data limit, and speed limit.
            </p>
          </div>

          {selectedType && (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-muted/40 border border-border rounded-lg p-4">
              <div className="flex items-center gap-3">
                <Clock className="w-4 h-4 text-primary" />
                <div>
                  <p className="text-xs text-muted-foreground">Duration</p>
                  <p className="text-sm font-medium text-card-foreground">{formatDuration(selectedType)}</p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <Ticket className="w-4 h-4 text-primary" />
                <div>
                  <p className="text-xs text-muted-foreground">Amount</p>
                  <p className="text-sm font-medium text-card-foreground">{formatCurrency(selectedType.base_amount)}</p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <Database className="w-4 h-4 text-primary" />
                <div>
                  <p className="text-xs text-muted-foreground">Data Limit</p>
                  <p className="text-sm font-medium text-card-foreground">
                    {selectedType.data_limit_mb ? `${selectedType.data_limit_mb.toLocaleString()} MB` : 'Unlimited'}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <Zap className="w-4 h-4 text-primary" />
                <div>
                  <p className="text-xs text-muted-foreground">Speed Limit</p>
                  <p className="text-sm font-medium text-card-foreground">{formatSpeed(selectedType.speed_limit_kbps)}</p>
                </div>
              </div>
            </div>
          )}

          {error && (
            <div className="p-4 rounded-lg bg-destructive/10 border border-destructive/20 text-destructive text-sm">
              {error}
            </div>
          )}

          {success && (
            <div className="flex items-start gap-3 p-4 rounded-lg bg-primary/10 border border-primary/20 text-primary text-sm">
              <CheckCircle className="w-4 h-4 mt-0.5 flex-shrink-0" />
              <div>
                <p className="font-medium">{success}</p>
                {createdVoucher && (
                  <p className="text-xs mt-1 text-muted-foreground">
                    {createdVoucher.voucher_code} is available under {createdVoucher.group?.group_name || 'Manual vouchers'}.
                  </p>
                )}
              </div>
            </div>
          )}

          <button
            type="submit"
            disabled={saving || loadingTypes || !selectedSite?.id}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors text-sm font-medium disabled:opacity-60"
          >
            {saving ? (
              <>
                <RefreshCw className="w-4 h-4 animate-spin" />
                Creating...
              </>
            ) : (
              <>
                <Ticket className="w-4 h-4" />
                Create Manual Voucher
              </>
            )}
          </button>
        </form>

        <aside className="bg-card border border-border rounded-lg p-5 sm:p-6 h-fit">
          <h2 className="text-base font-semibold text-card-foreground mb-3">How It Works</h2>
          <div className="space-y-3 text-sm text-muted-foreground">
            <p>The voucher is created as unused stock for the active site.</p>
            <p>The selected voucher type supplies validity, data limit, speed limit, and price.</p>
            <p>OnLiFi syncs the voucher to RADIUS immediately, so it can be used right away.</p>
          </div>
        </aside>
      </div>
    </div>
  );
}
