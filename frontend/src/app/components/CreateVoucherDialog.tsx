import { useState, useEffect } from 'react';
import { X, Ticket, Clock, Database, Zap, DollarSign, Users, Package } from 'lucide-react';

interface SalesPoint {
  id: number;
  name: string;
  location: string | null;
}

interface VoucherType {
  id: number;
  type_name: string;
  duration_hours: number;
  base_amount: number;
  description: string;
  data_limit_mb: number | null;
  speed_limit_kbps: number | null;
}

interface CreateVoucherDialogProps {
  onClose: () => void;
  onSuccess: () => void;
}

export function CreateVoucherDialog({ onClose, onSuccess }: CreateVoucherDialogProps) {
  const [salesPoints, setSalesPoints] = useState<SalesPoint[]>([]);
  const [voucherTypes, setVoucherTypes] = useState<VoucherType[]>([]);
  const [loading, setLoading] = useState(false);
  const [formData, setFormData] = useState({
    group_name: '',
    description: '',
    voucher_type_id: 0,
    profile_name: 'default',
    quantity: 10,
    sales_point_id: 0,
    code_prefix: '',
    code_length: 6,
  });

  useEffect(() => {
    loadSalesPoints();
    loadVoucherTypes();
  }, []);

  const loadSalesPoints = async () => {
    try {
      const response = await fetch('/api/mikrotik_api.php?action=sales_points');
      const data = await response.json();
      if (data.sales_points) {
        setSalesPoints(data.sales_points);
      }
    } catch (error) {
      console.error('Failed to load sales points:', error);
    }
  };

  const loadVoucherTypes = async () => {
    try {
      const response = await fetch('/api/voucher_types_api.php?action=list');
      const data = await response.json();
      if (data.types) {
        setVoucherTypes(data.types);
      }
    } catch (error) {
      console.error('Failed to load voucher types:', error);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);

    try {
      const response = await fetch('/api/mikrotik_api.php?action=create_vouchers', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData),
      });

      const data = await response.json();

      if (data.success) {
        onSuccess();
      } else {
        alert(data.error || 'Failed to create vouchers');
      }
    } catch (error) {
      console.error('Failed to create vouchers:', error);
      alert('Failed to create vouchers');
    } finally {
      setLoading(false);
    }
  };

  const handleChange = (field: string, value: string | number) => {
    setFormData(prev => ({ ...prev, [field]: value }));
  };

  const selectedType = voucherTypes.find(t => t.id === formData.voucher_type_id);
  const validity_hours = selectedType?.duration_hours || 0;
  const price = selectedType?.base_amount || 0;
  const data_limit_mb = selectedType?.data_limit_mb || 0;
  const speed_limit_kbps = selectedType?.speed_limit_kbps || 0;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-card border border-border rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        {/* Header */}
        <div className="sticky top-0 bg-card border-b border-border px-6 py-4 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Ticket className="w-5 h-5 text-primary" />
            <h2 className="text-xl font-semibold text-card-foreground">Create Vouchers</h2>
          </div>
          <button
            onClick={onClose}
            className="p-2 hover:bg-muted rounded-lg transition-colors"
          >
            <X className="w-5 h-5 text-muted-foreground" />
          </button>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="p-6 space-y-6">
          {/* Group Information */}
          <div className="space-y-4">
            <h3 className="text-sm font-semibold text-card-foreground uppercase tracking-wide">Group Information</h3>
            
            <div>
              <label className="block text-sm font-medium text-card-foreground mb-2">
                Group Name *
              </label>
              <input
                type="text"
                required
                value={formData.group_name}
                onChange={(e) => handleChange('group_name', e.target.value)}
                className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                placeholder="e.g., Daily Pass - January 2024"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-card-foreground mb-2">
                Description
              </label>
              <textarea
                value={formData.description}
                onChange={(e) => handleChange('description', e.target.value)}
                className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                rows={2}
                placeholder="Optional description for this voucher group"
              />
            </div>
          </div>

          {/* Voucher Type Selection */}
          <div className="space-y-4">
            <h3 className="text-sm font-semibold text-card-foreground uppercase tracking-wide">Voucher Type</h3>
            
            <div>
              <label className="block text-sm font-medium text-card-foreground mb-2">
                <Clock className="w-4 h-4 inline mr-1" />
                Select Voucher Type *
              </label>
              <select
                required
                value={formData.voucher_type_id}
                onChange={(e) => handleChange('voucher_type_id', parseInt(e.target.value))}
                className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
              >
                <option value="0">-- Select a voucher type --</option>
                {voucherTypes.map((type) => (
                  <option key={type.id} value={type.id}>
                    {type.type_name} - {type.duration_hours}h - UGX {type.base_amount.toLocaleString()}
                  </option>
                ))}
              </select>
              <p className="text-xs text-muted-foreground mt-1">
                Voucher types define duration, price, and limits. Manage types in Vouchers → Voucher Types.
              </p>
            </div>

            {selectedType && (
              <div className="bg-muted/50 rounded-lg p-4 space-y-2">
                <p className="text-sm font-medium text-card-foreground">Selected Type Details:</p>
                <div className="grid grid-cols-2 gap-2 text-sm text-muted-foreground">
                  <div>Duration: {selectedType.duration_hours} hours</div>
                  <div>Price: UGX {selectedType.base_amount.toLocaleString()}</div>
                  <div>Data Limit: {selectedType.data_limit_mb ? `${selectedType.data_limit_mb} MB` : 'Unlimited'}</div>
                  <div>Speed Limit: {selectedType.speed_limit_kbps ? `${(selectedType.speed_limit_kbps / 1024).toFixed(1)} Mbps` : 'Unlimited'}</div>
                </div>
                {selectedType.description && (
                  <p className="text-xs text-muted-foreground pt-2 border-t border-border">{selectedType.description}</p>
                )}
              </div>
            )}
          </div>

          {/* Generation Settings */}
          <div className="space-y-4">
            <h3 className="text-sm font-semibold text-card-foreground uppercase tracking-wide">Generation Settings</h3>
            
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  <Package className="w-4 h-4 inline mr-1" />
                  Quantity *
                </label>
                <input
                  type="number"
                  required
                  min="1"
                  max="1000"
                  value={formData.quantity}
                  onChange={(e) => handleChange('quantity', parseInt(e.target.value))}
                  className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                />
                <p className="text-xs text-muted-foreground mt-1">Max 1000 vouchers per batch</p>
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  <Users className="w-4 h-4 inline mr-1" />
                  Sales Point
                </label>
                <select
                  value={formData.sales_point_id}
                  onChange={(e) => handleChange('sales_point_id', parseInt(e.target.value))}
                  className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                >
                  <option value="0">None</option>
                  {salesPoints.map((point) => (
                    <option key={point.id} value={point.id}>
                      {point.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Code Prefix (Optional)
                </label>
                <input
                  type="text"
                  value={formData.code_prefix}
                  onChange={(e) => handleChange('code_prefix', e.target.value)}
                  className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                  placeholder="Leave empty for no prefix"
                />
                <p className="text-xs text-muted-foreground mt-1">Optional prefix for voucher codes</p>
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Code Length
                </label>
                <input
                  type="number"
                  min="6"
                  max="16"
                  value={formData.code_length}
                  onChange={(e) => handleChange('code_length', parseInt(e.target.value))}
                  className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                />
                <p className="text-xs text-muted-foreground mt-1">Default: 6 characters</p>
              </div>
            </div>
          </div>

          {/* Preview */}
          {selectedType && (
            <div className="bg-muted/50 rounded-lg p-4">
              <p className="text-sm font-medium text-card-foreground mb-2">Preview</p>
              <div className="space-y-1 text-sm text-muted-foreground">
                <p>• {formData.quantity} vouchers will be created</p>
                <p>• Type: {selectedType.type_name}</p>
                <p>• Valid for {validity_hours} hours</p>
                <p>• Price: UGX {price.toLocaleString()} each</p>
                {data_limit_mb > 0 && <p>• Data limit: {data_limit_mb} MB</p>}
                {speed_limit_kbps > 0 && <p>• Speed limit: {(speed_limit_kbps / 1024).toFixed(1)} Mbps</p>}
                <p className="font-semibold text-primary pt-2">
                  Total value: UGX {(formData.quantity * price).toLocaleString()}
                </p>
              </div>
            </div>
          )}

          {/* Actions */}
          <div className="flex items-center justify-end gap-3 pt-4 border-t border-border">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-card-foreground hover:bg-muted rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading}
              className="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors disabled:opacity-50"
            >
              {loading ? 'Creating...' : 'Create Vouchers'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
