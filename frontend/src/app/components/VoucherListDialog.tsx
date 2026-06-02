import { useState, useEffect } from 'react';
import { X, Download, Ticket, Filter, Search, Printer } from 'lucide-react';
import { API_BASE, getDefaultVoucherTemplate, getVouchers } from '../utils/api';

interface Voucher {
  id: number;
  voucher_code: string;
  status: 'unused' | 'reserved' | 'in_use' | 'used' | 'expired' | 'disabled';
  price: number;
  validity_hours: number;
  first_used_at: string | null;
  expires_at: string | null;
  used_by_mac: string | null;
  created_at: string;
  voucher_type?: string;
  sales_point?: { name: string } | null;
  sales_point_name?: string | null;
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

interface VoucherTemplate {
  name: string;
  layout: 'single' | 'grid-2x2' | 'grid-2x4' | 'grid-3x3';
  paper_size: string;
  logo_url?: string | null;
  background_color: string;
  text_color: string;
  accent_color: string;
  show_voucher_code: boolean;
  show_voucher_type: boolean;
  show_sales_point: boolean;
  show_duration: boolean;
  show_price: boolean;
  show_expiry: boolean;
  show_qr_code: boolean;
  header_text?: string | null;
  footer_text?: string | null;
  instructions?: string | null;
}

export function VoucherListDialog({ group, onClose }: VoucherListDialogProps) {
  const [vouchers, setVouchers] = useState<Voucher[]>([]);
  const [template, setTemplate] = useState<VoucherTemplate | null>(null);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState('');

  useEffect(() => {
    loadVouchers();
  }, [group.id, statusFilter]);

  useEffect(() => {
    loadTemplate();
  }, []);

  const loadTemplate = async () => {
    try {
      const data = await getDefaultVoucherTemplate();
      setTemplate(data.template || null);
    } catch (error) {
      console.error('Failed to load voucher template:', error);
    }
  };

  const loadVouchers = async () => {
    try {
      setLoading(true);
      const data = await getVouchers({
        group_id: group.id,
        status: statusFilter === 'all' ? undefined : statusFilter,
        per_page: 5000,
      });
      setVouchers(data.data || data || []);
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

  const downloadTemplateVouchers = (vouchersToDownload: Voucher[], heading: string) => {
    const status = heading.toLowerCase().includes('unused') ? 'unused' : statusFilter === 'all' ? 'all' : statusFilter;
    downloadGroupPdf(status);
  };

  const downloadGroupPdf = async (status: string) => {
    try {
      const token = localStorage.getItem('tenant_token');
      const headers: HeadersInit = { Accept: 'application/pdf' };
      if (token) headers.Authorization = `Bearer ${token}`;
      const siteId = localStorage.getItem('selected_site_id');
      if (siteId) headers['X-Site-ID'] = siteId;
      const response = await fetch(`${API_BASE}/vouchers/groups/${group.id}/export-pdf?status=${encodeURIComponent(status)}`, {
        headers,
        credentials: 'include',
      });
      if (!response.ok) throw new Error('Failed to download PDF');
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `${group.group_name.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase() || 'vouchers'}-${status}.pdf`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (error) {
      console.error('Failed to download PDF:', error);
      alert('Failed to download PDF');
    }
  };

  const handleDownloadAll = () => {
    downloadTemplateVouchers(
      filteredVouchers,
      statusFilter === 'all' ? 'All Vouchers' : `${formatStatus(statusFilter)} Vouchers`
    );
  };

  const handleDownloadUnused = () => {
    const unusedVouchers = vouchers.filter(v => v.status === 'unused');
    downloadTemplateVouchers(unusedVouchers, 'Unused Vouchers');
  };

  const handlePrintUnused = () => {
    downloadGroupPdf('unused');
  };

  const escapeHtml = (value: string | number | null | undefined) =>
    String(value ?? '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    }[char] || char));

  const layoutColumns = (layout: VoucherTemplate['layout']) => {
    switch (layout) {
      case 'single': return 1;
      case 'grid-2x2': return 2;
      case 'grid-3x3': return 3;
      case 'grid-2x4':
      default: return 2;
    }
  };

  const buildVoucherCard = (voucher: Voucher, activeTemplate: VoucherTemplate) => {
    const salesPoint = voucher.sales_point?.name || voucher.sales_point_name || '';
    return `
      <div class="voucher-card">
        ${activeTemplate.logo_url ? `<img class="voucher-logo" src="${escapeHtml(activeTemplate.logo_url)}" alt="Logo" />` : ''}
        ${activeTemplate.header_text ? `<div class="voucher-header">${escapeHtml(activeTemplate.header_text)}</div>` : ''}
        ${activeTemplate.show_voucher_code ? `<div class="voucher-code">${escapeHtml(voucher.voucher_code)}</div>` : ''}
        <div class="voucher-meta">
          ${activeTemplate.show_voucher_type ? `<div><span>Type</span><strong>${escapeHtml(voucher.voucher_type || group.group_name)}</strong></div>` : ''}
          ${activeTemplate.show_duration ? `<div><span>Duration</span><strong>${escapeHtml(voucher.validity_hours)}h</strong></div>` : ''}
          ${activeTemplate.show_price ? `<div><span>Price</span><strong>UGX ${Number(voucher.price || group.price).toLocaleString()}</strong></div>` : ''}
          ${activeTemplate.show_sales_point && salesPoint ? `<div><span>Sales Point</span><strong>${escapeHtml(salesPoint)}</strong></div>` : ''}
          ${activeTemplate.show_expiry && voucher.expires_at ? `<div><span>Expiry</span><strong>${escapeHtml(new Date(voucher.expires_at).toLocaleDateString())}</strong></div>` : ''}
        </div>
        ${activeTemplate.instructions ? `<div class="voucher-instructions">${escapeHtml(activeTemplate.instructions)}</div>` : ''}
        ${activeTemplate.footer_text ? `<div class="voucher-footer">${escapeHtml(activeTemplate.footer_text)}</div>` : ''}
      </div>
    `;
  };

  const buildTemplateHtml = (vouchersToRender: Voucher[], autoPrint: boolean, heading: string) => {
    const activeTemplate = template || {
      name: 'Default',
      layout: 'grid-2x4',
      paper_size: 'A4',
      background_color: '#ffffff',
      text_color: '#000000',
      accent_color: '#3b82f6',
      show_voucher_code: true,
      show_voucher_type: true,
      show_sales_point: true,
      show_duration: true,
      show_price: true,
      show_expiry: false,
      show_qr_code: false,
    } as VoucherTemplate;
    const columns = layoutColumns(activeTemplate.layout);

    return `
      <!DOCTYPE html>
      <html>
      <head>
        <title>${escapeHtml(heading)} - ${escapeHtml(group.group_name)}</title>
        <style>
          @page { size: ${escapeHtml(activeTemplate.paper_size || 'A4')}; margin: 12mm; }
          body { font-family: Arial, sans-serif; padding: 0; margin: 0; color: ${escapeHtml(activeTemplate.text_color)}; }
          h1 { text-align: center; margin: 0 0 14px; font-size: 20px; }
          .voucher-grid { display: grid; grid-template-columns: repeat(${columns}, minmax(0, 1fr)); gap: 10px; }
          .voucher-card {
            background: ${escapeHtml(activeTemplate.background_color)};
            color: ${escapeHtml(activeTemplate.text_color)};
            border: 1.5px dashed ${escapeHtml(activeTemplate.accent_color)};
            border-radius: 8px;
            padding: 12px;
            min-height: 140px;
            page-break-inside: avoid;
            break-inside: avoid;
          }
          .voucher-logo { max-height: 42px; max-width: 120px; object-fit: contain; display: block; margin: 0 auto 8px; }
          .voucher-header, .voucher-footer { text-align: center; color: ${escapeHtml(activeTemplate.accent_color)}; font-weight: 700; font-size: 12px; }
          .voucher-code { text-align: center; color: ${escapeHtml(activeTemplate.accent_color)}; font-size: 24px; font-weight: 800; letter-spacing: 1px; margin: 8px 0; }
          .voucher-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; font-size: 11px; }
          .voucher-meta span { display: block; opacity: .65; }
          .voucher-meta strong { display: block; font-size: 12px; }
          .voucher-instructions { margin-top: 8px; padding-top: 8px; border-top: 1px solid ${escapeHtml(activeTemplate.accent_color)}; font-size: 10px; opacity: .75; text-align: center; }
          @media print { .voucher-card { page-break-inside: avoid; } }
        </style>
      </head>
      <body>
        <h1>${escapeHtml(group.group_name)} - ${escapeHtml(heading)}</h1>
        <div class="voucher-grid">
          ${vouchersToRender.map((voucher) => buildVoucherCard(voucher, activeTemplate)).join('')}
        </div>
        ${autoPrint ? '<script>window.print();</script>' : ''}
      </body>
      </html>
    `;
  };

  const printTemplateVouchers = (vouchersToPrint: Voucher[], heading: string) => {
    const printWindow = window.open('', '_blank');
    if (!printWindow) return;
    printWindow.document.write(buildTemplateHtml(vouchersToPrint, true, heading));
    printWindow.document.close();
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'unused': return 'bg-blue-500/10 text-blue-500';
      case 'reserved': return 'bg-amber-500/10 text-amber-500';
      case 'in_use': return 'bg-yellow-500/10 text-yellow-600';
      case 'used': return 'bg-yellow-500/10 text-yellow-600';
      case 'expired': return 'bg-yellow-500/10 text-yellow-600';
      case 'disabled': return 'bg-gray-500/10 text-gray-500';
      default: return 'bg-muted text-muted-foreground';
    }
  };

  const formatStatus = (status: string) => {
    switch (status) {
      case 'consumed': return 'Used';
      case 'in_use': return 'Used';
      case 'used': return 'Used';
      case 'expired': return 'Used';
      default:
        return status.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
    }
  };

  const unusedCount = vouchers.filter(v => v.status === 'unused').length;
  const usedCount = vouchers.filter(v => ['in_use', 'used', 'expired'].includes(v.status)).length;

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
              <option value="reserved">Reserved</option>
              <option value="consumed">Used ({usedCount})</option>
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
              Unused PDF
            </button>
            <button
              onClick={handleDownloadAll}
              disabled={filteredVouchers.length === 0}
              className="flex items-center gap-2 px-3 py-1.5 text-sm bg-primary hover:bg-primary/90 disabled:bg-muted disabled:text-muted-foreground text-primary-foreground rounded-lg transition-colors"
            >
              <Download className="w-4 h-4" />
              Current PDF
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
                    <th className="text-left py-3 px-4 font-semibold text-card-foreground">MAC Address</th>
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
                        {voucher.used_by_mac || '-'}
                      </td>
                      <td className="py-3 px-4">
                        <span className={`px-2 py-1 rounded text-xs font-semibold ${getStatusColor(voucher.status)}`}>
                          {formatStatus(voucher.status)}
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
