import { useState, useEffect } from 'react';
import { X, Users, Plus, MapPin, Phone, User, TrendingUp } from 'lucide-react';

interface SalesPoint {
  id: number;
  name: string;
  location: string | null;
  contact_person: string | null;
  contact_phone: string | null;
  is_active: boolean;
  total_vouchers: number;
  total_revenue: number;
}

interface SalesPointsDialogProps {
  onClose: () => void;
  onUpdate: () => void;
}

export function SalesPointsDialog({ onClose, onUpdate }: SalesPointsDialogProps) {
  const [salesPoints, setSalesPoints] = useState<SalesPoint[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAddForm, setShowAddForm] = useState(false);
  const [formData, setFormData] = useState({
    name: '',
    location: '',
    contact_person: '',
    contact_phone: '',
  });

  useEffect(() => {
    loadSalesPoints();
  }, []);

  const loadSalesPoints = async () => {
    try {
      setLoading(true);
      const response = await fetch('/api/mikrotik_api.php?action=sales_points');
      const data = await response.json();
      if (data.sales_points) {
        setSalesPoints(data.sales_points);
      }
    } catch (error) {
      console.error('Failed to load sales points:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    try {
      const response = await fetch('/api/mikrotik_api.php?action=create_sales_point', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData),
      });

      const data = await response.json();

      if (data.success) {
        setFormData({ name: '', location: '', contact_person: '', contact_phone: '' });
        setShowAddForm(false);
        loadSalesPoints();
        onUpdate();
      } else {
        alert(data.error || 'Failed to create sales point');
      }
    } catch (error) {
      console.error('Failed to create sales point:', error);
      alert('Failed to create sales point');
    }
  };

  const formatCurrency = (amount: number) => {
    return `UGX ${amount.toLocaleString()}`;
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-card border border-border rounded-lg max-w-3xl w-full max-h-[90vh] overflow-y-auto">
        {/* Header */}
        <div className="sticky top-0 bg-card border-b border-border px-6 py-4 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Users className="w-5 h-5 text-primary" />
            <h2 className="text-xl font-semibold text-card-foreground">Sales Points</h2>
          </div>
          <button
            onClick={onClose}
            className="p-2 hover:bg-muted rounded-lg transition-colors"
          >
            <X className="w-5 h-5 text-muted-foreground" />
          </button>
        </div>

        {/* Content */}
        <div className="p-6">
          {/* Add Button */}
          {!showAddForm && (
            <button
              onClick={() => setShowAddForm(true)}
              className="w-full flex items-center justify-center gap-2 px-4 py-3 mb-6 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
            >
              <Plus className="w-4 h-4" />
              Add New Sales Point
            </button>
          )}

          {/* Add Form */}
          {showAddForm && (
            <form onSubmit={handleSubmit} className="bg-muted/50 rounded-lg p-4 mb-6 space-y-4">
              <h3 className="text-sm font-semibold text-card-foreground uppercase tracking-wide">
                New Sales Point
              </h3>
              
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Name *
                </label>
                <input
                  type="text"
                  required
                  value={formData.name}
                  onChange={(e) => setFormData(prev => ({ ...prev, name: e.target.value }))}
                  className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                  placeholder="e.g., Downtown Shop"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-2">
                    Location
                  </label>
                  <input
                    type="text"
                    value={formData.location}
                    onChange={(e) => setFormData(prev => ({ ...prev, location: e.target.value }))}
                    className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    placeholder="e.g., Main Street"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-2">
                    Contact Person
                  </label>
                  <input
                    type="text"
                    value={formData.contact_person}
                    onChange={(e) => setFormData(prev => ({ ...prev, contact_person: e.target.value }))}
                    className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    placeholder="e.g., John Doe"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Contact Phone
                </label>
                <input
                  type="tel"
                  value={formData.contact_phone}
                  onChange={(e) => setFormData(prev => ({ ...prev, contact_phone: e.target.value }))}
                  className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                  placeholder="e.g., +256 700 000 000"
                />
              </div>

              <div className="flex items-center gap-3">
                <button
                  type="submit"
                  className="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
                >
                  Create Sales Point
                </button>
                <button
                  type="button"
                  onClick={() => setShowAddForm(false)}
                  className="px-4 py-2 text-card-foreground hover:bg-muted rounded-lg transition-colors"
                >
                  Cancel
                </button>
              </div>
            </form>
          )}

          {/* Sales Points List */}
          {loading ? (
            <div className="text-center py-8">
              <Users className="w-8 h-8 text-muted-foreground mx-auto mb-2 animate-pulse" />
              <p className="text-sm text-muted-foreground">Loading sales points...</p>
            </div>
          ) : salesPoints.length === 0 ? (
            <div className="text-center py-8">
              <Users className="w-12 h-12 text-muted-foreground mx-auto mb-3" />
              <p className="text-muted-foreground">No sales points created yet</p>
            </div>
          ) : (
            <div className="space-y-3">
              {salesPoints.map((point) => (
                <div
                  key={point.id}
                  className="bg-background border border-border rounded-lg p-4 hover:shadow-md transition-all"
                >
                  <div className="flex items-start justify-between mb-3">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center">
                        <Users className="w-5 h-5 text-primary" />
                      </div>
                      <div>
                        <h3 className="font-semibold text-card-foreground">{point.name}</h3>
                        {point.location && (
                          <p className="text-sm text-muted-foreground flex items-center gap-1">
                            <MapPin className="w-3 h-3" />
                            {point.location}
                          </p>
                        )}
                      </div>
                    </div>
                    <div className={`px-2 py-1 rounded text-xs font-semibold ${
                      point.is_active ? 'bg-emerald-500/10 text-emerald-500' : 'bg-muted text-muted-foreground'
                    }`}>
                      {point.is_active ? 'Active' : 'Inactive'}
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4 mb-3">
                    <div>
                      <p className="text-xs text-muted-foreground mb-1">Total Vouchers</p>
                      <p className="text-lg font-bold text-card-foreground">{point.total_vouchers}</p>
                    </div>
                    <div>
                      <p className="text-xs text-muted-foreground mb-1">Total Revenue</p>
                      <p className="text-lg font-bold text-primary">{formatCurrency(point.total_revenue)}</p>
                    </div>
                  </div>

                  {(point.contact_person || point.contact_phone) && (
                    <div className="border-t border-border pt-3 space-y-1 text-sm">
                      {point.contact_person && (
                        <div className="flex items-center gap-2 text-muted-foreground">
                          <User className="w-4 h-4" />
                          <span>{point.contact_person}</span>
                        </div>
                      )}
                      {point.contact_phone && (
                        <div className="flex items-center gap-2 text-muted-foreground">
                          <Phone className="w-4 h-4" />
                          <span>{point.contact_phone}</span>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
