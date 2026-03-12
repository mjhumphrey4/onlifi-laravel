import { Package, Clock, DollarSign, Users, TrendingUp, Printer, Eye } from 'lucide-react';
import { useState } from 'react';

interface VoucherGroup {
  id: number;
  group_name: string;
  description: string;
  profile_name: string;
  validity_hours: number;
  data_limit_mb: number | null;
  speed_limit_kbps: number | null;
  price: number;
  sales_point_name: string | null;
  created_at: string;
  total_vouchers: number;
  unused_count: number;
  used_count: number;
}

interface VoucherGroupCardProps {
  group: VoucherGroup;
  onSelect: () => void;
}

export function VoucherGroupCard({ group, onSelect }: VoucherGroupCardProps) {
  const [showDetails, setShowDetails] = useState(false);

  const usagePercent = group.total_vouchers > 0 
    ? (group.used_count / group.total_vouchers) * 100 
    : 0;

  const formatCurrency = (amount: number) => {
    return `UGX ${amount.toLocaleString()}`;
  };

  const handlePrintUnused = async () => {
    // Open vouchers page filtered to this group and unused status
    window.open(`/vouchers/print?group_id=${group.id}&status=unused`, '_blank');
  };

  return (
    <div className="bg-card border border-border rounded-lg p-5 hover:shadow-lg transition-all">
      {/* Header */}
      <div className="flex items-start justify-between mb-4">
        <div className="flex-1">
          <h3 className="text-lg font-semibold text-card-foreground mb-1">{group.group_name}</h3>
          {group.description && (
            <p className="text-sm text-muted-foreground line-clamp-2">{group.description}</p>
          )}
        </div>
        <div className="w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0 ml-3">
          <Package className="w-5 h-5 text-primary" />
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-3 gap-3 mb-4">
        <div className="text-center">
          <p className="text-2xl font-bold text-card-foreground">{group.total_vouchers}</p>
          <p className="text-xs text-muted-foreground">Total</p>
        </div>
        <div className="text-center">
          <p className="text-2xl font-bold text-emerald-500">{group.used_count}</p>
          <p className="text-xs text-muted-foreground">Used</p>
        </div>
        <div className="text-center">
          <p className="text-2xl font-bold text-blue-500">{group.unused_count}</p>
          <p className="text-xs text-muted-foreground">Available</p>
        </div>
      </div>

      {/* Usage Bar */}
      <div className="mb-4">
        <div className="flex items-center justify-between mb-1">
          <span className="text-xs text-muted-foreground">Usage</span>
          <span className="text-xs font-semibold text-card-foreground">{usagePercent.toFixed(1)}%</span>
        </div>
        <div className="w-full bg-muted rounded-full h-2">
          <div
            className="bg-primary h-2 rounded-full transition-all"
            style={{ width: `${Math.min(usagePercent, 100)}%` }}
          />
        </div>
      </div>

      {/* Details */}
      <div className="space-y-2 text-sm border-t border-border pt-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 text-muted-foreground">
            <Clock className="w-4 h-4" />
            <span>Validity</span>
          </div>
          <span className="font-medium text-card-foreground">{group.validity_hours}h</span>
        </div>
        
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 text-muted-foreground">
            <DollarSign className="w-4 h-4" />
            <span>Price</span>
          </div>
          <span className="font-medium text-card-foreground">{formatCurrency(group.price)}</span>
        </div>

        {group.sales_point_name && (
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2 text-muted-foreground">
              <Users className="w-4 h-4" />
              <span>Sales Point</span>
            </div>
            <span className="font-medium text-card-foreground">{group.sales_point_name}</span>
          </div>
        )}

        {showDetails && (
          <>
            {group.data_limit_mb && (
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Data Limit</span>
                <span className="font-medium text-card-foreground">{group.data_limit_mb} MB</span>
              </div>
            )}
            
            {group.speed_limit_kbps && (
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Speed Limit</span>
                <span className="font-medium text-card-foreground">{group.speed_limit_kbps} Kbps</span>
              </div>
            )}

            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Profile</span>
              <span className="font-medium text-card-foreground">{group.profile_name}</span>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Created</span>
              <span className="font-medium text-card-foreground">
                {new Date(group.created_at).toLocaleDateString()}
              </span>
            </div>

            <div className="flex items-center justify-between border-t border-border pt-2">
              <div className="flex items-center gap-2 text-muted-foreground">
                <TrendingUp className="w-4 h-4" />
                <span>Potential Revenue</span>
              </div>
              <span className="font-bold text-primary">
                {formatCurrency(group.total_vouchers * group.price)}
              </span>
            </div>
          </>
        )}
      </div>

      {/* Actions */}
      <div className="flex items-center gap-2 mt-4 pt-3 border-t border-border">
        <button
          onClick={() => setShowDetails(!showDetails)}
          className="flex-1 flex items-center justify-center gap-2 px-3 py-2 text-sm bg-muted hover:bg-muted/80 text-card-foreground rounded-lg transition-colors"
        >
          <Eye className="w-4 h-4" />
          {showDetails ? 'Less' : 'More'}
        </button>
        
        <button
          onClick={onSelect}
          className="flex-1 flex items-center justify-center gap-2 px-3 py-2 text-sm bg-primary hover:bg-primary/90 text-primary-foreground rounded-lg transition-colors"
        >
          <Package className="w-4 h-4" />
          View All
        </button>
        
        {group.unused_count > 0 && (
          <button
            onClick={handlePrintUnused}
            className="px-3 py-2 text-sm bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg transition-colors"
            title="Print unused vouchers"
          >
            <Printer className="w-4 h-4" />
          </button>
        )}
      </div>
    </div>
  );
}
