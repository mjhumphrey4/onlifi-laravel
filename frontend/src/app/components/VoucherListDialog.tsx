import { useState, useEffect } from 'react';
import { X, Download, Ticket, Filter, Search, Printer } from 'lucide-react';

interface Voucher {
  id: number;
  voucher_code: string;
  password: string;
  status: 'unused' | 'used' | 'expired' | 'disabled';
  price: number;
  validity_hours: number;
  first_used_at: string | null;
  expires_at: string | null;
  created_at: string;
}

interface VoucherGroup {
  id: number;
  group_name: string;
  price: number;
  validity_hours: number;
}

interface VoucherListDialogProps {
  group: VoucherGroup;
  onClose: () => void;
}

export function VoucherListDialog({ group, onClose }: VoucherListDialogProps) {
  const [vouchers, setVouchers] = useState<Voucher[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState('');

  useEffect(() => {
    loadVouchers();
  }, [group.id, statusFilter]);

  const loadVouchers = async () => {
    try {
      setLoading(true);
      const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
      const headers: HeadersInit = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      };
      if (token) headers['Authorization'] = `Bearer ${token}`;
      const siteId = localStorage.getItem('selected_site_id');
      if (siteId) headers['X-Site-ID'] = siteId;

      let url = `/api/vouchers?group_id=${group.id}`;
      if (statusFilter !== 'all') {
        url += `&status=${statusFilter}`;
      }

      const response = await fetch(url, { headers });
      if (response.ok) {
        const data = await response.json();
        setVouchers(data.data || data || []);
      }
    } catch (error) {
      console.error('Failed to load vouchers:', error);
    } finally {
      setLoading(false);
    }
  };

  const filteredVouchers = vouchers.filter(v => 
    searchQuery === '' || 
    v.voucher_code.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const downloadVouchers = (vouchersToDownload: Voucher[], filename: string) => {
    const csvContent = [
      ['Voucher Code', 'Password', 'Status', 'Price', 'Validity (Hours)', 'Created At'].join(','),
      ...vouchersToDownload.map(v => [
        v.voucher_code,
        v.password,
        v.status,
        v.price,
        v.validity_hours,
        new Date(v.created_at).toLocaleString()
      ].join(','))
    ].join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
  };

  const handleDownloadAll = () => {
    downloadVouchers(filteredVouchers, `${group.group_name}_all_vouchers.csv`);
  };

  const handleDownloadUnused = () => {
    const unusedVouchers = vouchers.filter(v => v.status === 'unused');
    downloadVouchers(unusedVouchers, `${group.group_name}_unused_vouchers.csv`);
  };

  const handlePrintUnused = () => {
    const unusedVouchers = vouchers.filter(v => v.status === 'unused');
    const printWindow = window.open('', '_blank');
    if (!printWindow) return;

    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <title>Vouchers - ${group.group_name}</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 20px; }
          h1 { text-align: center; margin-bottom: 20px; }
          .voucher-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
          .voucher-card { 
            border: 2px dashed #333; 
            padding: 15px; 
            text-align: center;
            border-radius: 8px;
          }
          .voucher-code { font-size: 18px; font-weight: bold; margin-bottom: 5px; }
          .voucher-password { font-size: 14px; color: #666; margin-bottom: 10px; }
          .voucher-details { font-size: 12px; color: #888; }
          @media print {
            .voucher-card { page-break-inside: avoid; }
          }
        </style>
      </head>
      <body>
        <h1>${group.group_name} - Unused Vouchers</h1>
        <p style="text-align: center; margin-bottom: 20px;">
          Price: UGX ${group.price.toLocaleString()} | Validity: ${group.validity_hours} hours
        </p>
        <div class="voucher-grid">
          ${unusedVouchers.map(v => `
            <div class="voucher-card">
              <div class="voucher-code">${v.voucher_code}</div>
              <div class="voucher-password">Password: ${v.password}</div>
              <div class="voucher-details">
                UGX ${v.price.toLocaleString()} | ${v.validity_hours}h
              </div>
            </div>
          `).join('')}
        </div>
        <script>window.print();</script>
      </body>
      </html>
    `;

    printWindow.document.write(html);
    printWindow.document.close();
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'unused': return 'bg-blue-500/10 text-blue-500';
      case 'used': return 'bg-emerald-500/10 text-emerald-500';
      case 'expired': return 'bg-red-500/10 text-red-500';
      case 'disabled': return 'bg-gray-500/10 text-gray-500';
      default: return 'bg-muted text-muted-foreground';
    }
  };

  const unusedCount = vouchers.filter(v => v.status === 'unused').length;
  const usedCount = vouchers.filter(v => v.status === 'used').length;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-card border border-border rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="sticky top-0 bg-card border-b border-border px-6 py-4 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Ticket className="w-5 h-5 text-primary" />
            <h2 className="text-xl font-semibold text-card-foreground">{group.group_name}</h2>
            <span className="text-sm text-muted-foreground">({filteredVouchers.length} vouchers)</span>
          </div>
          <button
            onClick={onClose}
            className="p-2 hover:bg-muted rounded-lg transition-colors"
          >
            <X className="w-5 h-5 text-muted-foreground" />
          </button>
        </div>

        {/* Toolbar */}
        <div className="px-6 py-3 border-b border-border bg-muted/30 flex flex-wrap items-center gap-3">
          {/* Status Filter */}
          <div className="flex items-center gap-2">
            <Filter className="w-4 h-4 text-muted-foreground" />
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="bg-background border border-border rounded-lg px-3 py-1.5 text-sm text-card-foreground"
            >
              <option value="all">All Status</option>
              <option value="unused">Unused ({unusedCount})</option>
              <option value="used">Used ({usedCount})</option>
              <option value="expired">Expired</option>
            </select>
          </div>

          {/* Search */}
          <div className="flex items-center gap-2 flex-1 min-w-[200px]">
            <Search className="w-4 h-4 text-muted-foreground" />
            <input
              type="text"
              placeholder="Search voucher code..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="flex-1 bg-background border border-border rounded-lg px-3 py-1.5 text-sm text-card-foreground"
            />
          </div>

          {/* Actions */}
          <div className="flex items-center gap-2">
            <button
              onClick={handleDownloadUnused}
              disabled={unusedCount === 0}
              className="flex items-center gap-2 px-3 py-1.5 text-sm bg-emerald-500 hover:bg-emerald-600 disabled:bg-muted disabled:text-muted-foreground text-white rounded-lg transition-colors"
            >
              <Download className="w-4 h-4" />
              Download Unused
            </button>
            <button
              onClick={handleDownloadAll}
              disabled={filteredVouchers.length === 0}
              className="flex items-center gap-2 px-3 py-1.5 text-sm bg-primary hover:bg-primary/90 disabled:bg-muted disabled:text-muted-foreground text-primary-foreground rounded-lg transition-colors"
            >
              <Download className="w-4 h-4" />
              Download All
            </button>
            <button
              onClick={handlePrintUnused}
              disabled={unusedCount === 0}
              className="flex items-center gap-2 px-3 py-1.5 text-sm bg-blue-500 hover:bg-blue-600 disabled:bg-muted disabled:text-muted-foreground text-white rounded-lg transition-colors"
            >
              <Printer className="w-4 h-4" />
              Print
            </button>
          </div>
        </div>

        {/* Voucher List */}
        <div className="flex-1 overflow-y-auto p-6">
          {loading ? (
            <div className="text-center py-8">
              <Ticket className="w-8 h-8 text-muted-foreground mx-auto mb-2 animate-pulse" />
              <p className="text-sm text-muted-foreground">Loading vouchers...</p>
            </div>
          ) : filteredVouchers.length === 0 ? (
            <div className="text-center py-8">
              <Ticket className="w-12 h-12 text-muted-foreground mx-auto mb-3" />
              <p className="text-muted-foreground">No vouchers found</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border">
                    <th className="text-left py-3 px-4 font-semibold text-card-foreground">Code</th>
                    <th className="text-left py-3 px-4 font-semibold text-card-foreground">Password</th>
                    <th className="text-left py-3 px-4 font-semibold text-card-foreground">Status</th>
                    <th className="text-left py-3 px-4 font-semibold text-card-foreground">Price</th>
                    <th className="text-left py-3 px-4 font-semibold text-card-foreground">Validity</th>
                    <th className="text-left py-3 px-4 font-semibold text-card-foreground">Created</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredVouchers.map((voucher) => (
                    <tr key={voucher.id} className="border-b border-border hover:bg-muted/50">
                      <td className="py-3 px-4 font-mono font-semibold text-card-foreground">
                        {voucher.voucher_code}
                      </td>
                      <td className="py-3 px-4 font-mono text-muted-foreground">
                        {voucher.password}
                      </td>
                      <td className="py-3 px-4">
                        <span className={`px-2 py-1 rounded text-xs font-semibold ${getStatusColor(voucher.status)}`}>
                          {voucher.status}
                        </span>
                      </td>
                      <td className="py-3 px-4 text-card-foreground">
                        UGX {voucher.price.toLocaleString()}
                      </td>
                      <td className="py-3 px-4 text-muted-foreground">
                        {voucher.validity_hours}h
                      </td>
                      <td className="py-3 px-4 text-muted-foreground">
                        {new Date(voucher.created_at).toLocaleDateString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
