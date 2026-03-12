import { useState, useEffect } from 'react';
import { Plus, Edit2, Trash2, Clock, DollarSign, Database, Zap } from 'lucide-react';

interface VoucherType {
  id: number;
  type_name: string;
  duration_hours: number;
  base_amount: number;
  description: string;
  data_limit_mb: number | null;
  speed_limit_kbps: number | null;
  is_active: number;
  total_vouchers: number;
  unused_count: number;
  used_count: number;
  created_at: string;
}

export function VoucherTypes() {
  const [types, setTypes] = useState<VoucherType[]>([]);
  const [loading, setLoading] = useState(true);
  const [showDialog, setShowDialog] = useState(false);
  const [editingType, setEditingType] = useState<VoucherType | null>(null);
  const [formData, setFormData] = useState({
    type_name: '',
    duration_hours: 1,
    base_amount: '',
    description: '',
    data_limit_mb: '',
    speed_limit_mbps: ''
  });

  useEffect(() => {
    loadTypes();
  }, []);

  const loadTypes = async () => {
    try {
      setLoading(true);
      const res = await fetch('/api/voucher_types_api.php?action=list');
      const data = await res.json();
      if (data.types) setTypes(data.types);
    } catch (error) {
      console.error('Failed to load voucher types:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    const payload = {
      ...formData,
      base_amount: formData.base_amount ? parseFloat(formData.base_amount) : 0,
      data_limit_mb: formData.data_limit_mb ? parseInt(formData.data_limit_mb) : null,
      speed_limit_kbps: formData.speed_limit_mbps ? parseInt(formData.speed_limit_mbps) * 1024 : null,
      ...(editingType && { id: editingType.id })
    };

    try {
      const action = editingType ? 'update' : 'create';
      const res = await fetch(`/api/voucher_types_api.php?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const data = await res.json();
      
      if (data.error) {
        alert(data.error);
        return;
      }

      setShowDialog(false);
      setEditingType(null);
      resetForm();
      loadTypes();
    } catch (error) {
      console.error('Failed to save voucher type:', error);
      alert('Failed to save voucher type');
    }
  };

  const handleEdit = (type: VoucherType) => {
    setEditingType(type);
    setFormData({
      type_name: type.type_name,
      duration_hours: type.duration_hours,
      base_amount: type.base_amount.toString(),
      description: type.description || '',
      data_limit_mb: type.data_limit_mb?.toString() || '',
      speed_limit_mbps: type.speed_limit_kbps ? (type.speed_limit_kbps / 1024).toString() : ''
    });
    setShowDialog(true);
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this voucher type?')) return;

    try {
      const res = await fetch('/api/voucher_types_api.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });

      const data = await res.json();
      
      if (data.error) {
        alert(data.error);
        return;
      }

      loadTypes();
    } catch (error) {
      console.error('Failed to delete voucher type:', error);
      alert('Failed to delete voucher type');
    }
  };

  const resetForm = () => {
    setFormData({
      type_name: '',
      duration_hours: 1,
      base_amount: '',
      description: '',
      data_limit_mb: '',
      speed_limit_mbps: ''
    });
  };

  const openCreateDialog = () => {
    setEditingType(null);
    resetForm();
    setShowDialog(true);
  };

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-UG', {
      style: 'currency',
      currency: 'UGX',
      minimumFractionDigits: 0
    }).format(amount);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Voucher Types</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Manage voucher profiles with duration and pricing
          </p>
        </div>
        <button
          onClick={openCreateDialog}
          className="flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
        >
          <Plus className="w-4 h-4" />
          Create Voucher Type
        </button>
      </div>

      {/* Voucher Types Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {types.map((type) => (
          <div
            key={type.id}
            className="bg-card border border-border rounded-lg p-6 hover:shadow-lg transition-shadow"
          >
            <div className="flex justify-between items-start mb-4">
              <div>
                <h3 className="text-lg font-semibold text-card-foreground">{type.type_name}</h3>
                <p className="text-sm text-muted-foreground mt-1">{type.description}</p>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => handleEdit(type)}
                  className="p-2 text-muted-foreground hover:text-primary hover:bg-muted rounded-lg transition-colors"
                >
                  <Edit2 className="w-4 h-4" />
                </button>
                <button
                  onClick={() => handleDelete(type.id)}
                  className="p-2 text-muted-foreground hover:text-destructive hover:bg-muted rounded-lg transition-colors"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>
            </div>

            <div className="space-y-3">
              <div className="flex items-center gap-2 text-sm">
                <Clock className="w-4 h-4 text-primary" />
                <span className="text-muted-foreground">Duration:</span>
                <span className="font-medium text-card-foreground">{type.duration_hours}h</span>
              </div>

              <div className="flex items-center gap-2 text-sm">
                <DollarSign className="w-4 h-4 text-primary" />
                <span className="text-muted-foreground">Base Amount:</span>
                <span className="font-medium text-card-foreground">{formatCurrency(type.base_amount)}</span>
              </div>

              {type.data_limit_mb && (
                <div className="flex items-center gap-2 text-sm">
                  <Database className="w-4 h-4 text-primary" />
                  <span className="text-muted-foreground">Data Limit:</span>
                  <span className="font-medium text-card-foreground">{type.data_limit_mb} MB</span>
                </div>
              )}

              {type.speed_limit_kbps && (
                <div className="flex items-center gap-2 text-sm">
                  <Zap className="w-4 h-4 text-primary" />
                  <span className="text-muted-foreground">Speed Limit:</span>
                  <span className="font-medium text-card-foreground">{(type.speed_limit_kbps / 1024).toFixed(1)} Mbps</span>
                </div>
              )}
            </div>

            <div className="mt-4 pt-4 border-t border-border grid grid-cols-3 gap-2 text-center">
              <div>
                <p className="text-xs text-muted-foreground">Total</p>
                <p className="text-lg font-semibold text-card-foreground">{type.total_vouchers || 0}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Unused</p>
                <p className="text-lg font-semibold text-green-600">{type.unused_count || 0}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Used</p>
                <p className="text-lg font-semibold text-blue-600">{type.used_count || 0}</p>
              </div>
            </div>
          </div>
        ))}
      </div>

      {types.length === 0 && (
        <div className="text-center py-12">
          <Clock className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
          <h3 className="text-lg font-medium text-card-foreground mb-2">No voucher types yet</h3>
          <p className="text-sm text-muted-foreground mb-4">
            Create your first voucher type to get started
          </p>
          <button
            onClick={openCreateDialog}
            className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
          >
            <Plus className="w-4 h-4" />
            Create Voucher Type
          </button>
        </div>
      )}

      {/* Create/Edit Dialog */}
      {showDialog && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-card border border-border rounded-lg w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div className="p-6 border-b border-border">
              <h2 className="text-xl font-semibold text-card-foreground">
                {editingType ? 'Edit Voucher Type' : 'Create Voucher Type'}
              </h2>
            </div>

            <form onSubmit={handleSubmit} className="p-6 space-y-4">
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Type Name *
                </label>
                <input
                  type="text"
                  required
                  value={formData.type_name}
                  onChange={(e) => setFormData({ ...formData, type_name: e.target.value })}
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="e.g., 1 Hour, 2 Hours"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Duration (Hours) *
                </label>
                <input
                  type="number"
                  required
                  min="1"
                  value={formData.duration_hours}
                  onChange={(e) => setFormData({ ...formData, duration_hours: parseInt(e.target.value) || 1 })}
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Base Amount (UGX) *
                </label>
                <input
                  type="number"
                  required
                  min="0"
                  step="100"
                  value={formData.base_amount}
                  onChange={(e) => setFormData({ ...formData, base_amount: e.target.value })}
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="Enter amount"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Description
                </label>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                  rows={3}
                  placeholder="Optional description"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Data Limit (MB)
                </label>
                <input
                  type="number"
                  min="0"
                  value={formData.data_limit_mb}
                  onChange={(e) => setFormData({ ...formData, data_limit_mb: e.target.value })}
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="Leave empty for unlimited"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Speed Limit (Mbps)
                </label>
                <input
                  type="number"
                  min="0"
                  step="0.1"
                  value={formData.speed_limit_mbps}
                  onChange={(e) => setFormData({ ...formData, speed_limit_mbps: e.target.value })}
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                  placeholder="Leave empty for unlimited"
                />
              </div>

              <div className="flex gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => {
                    setShowDialog(false);
                    setEditingType(null);
                    resetForm();
                  }}
                  className="flex-1 px-4 py-2 border border-border text-card-foreground rounded-lg hover:bg-muted transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="flex-1 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
                >
                  {editingType ? 'Update' : 'Create'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
