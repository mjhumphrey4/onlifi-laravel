import { useState, useEffect } from 'react';
import { X, Ticket, Filter, Search, Printer } from 'lucide-react';
import { getDefaultVoucherTemplate, getVouchers } from '../utils/api';

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
  layout: 'single' | 'grid-2x2' | 'grid-2x4' | 'grid-3x3' | 'grid-4x5' | 'grid-5x8' | 'grid-8x10';
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

  const handleDownloadAll = () => {
    printTemplateVouchers(
      filteredVouchers,
      statusFilter === 'all' ? 'All Vouchers' : `${formatStatus(statusFilter)} Vouchers`
    );
  };

  const handleDownloadUnused = () => {
    const unusedVouchers = vouchers.filter(v => v.status === 'unused');
    printTemplateVouchers(unusedVouchers, 'Unused Vouchers');
  };

  const handlePrintUnused = () => {
    const unusedVouchers = vouchers.filter(v => v.status === 'unused');
    printTemplateVouchers(unusedVouchers, 'Unused Vouchers');
  };

  const escapeHtml = (value: string | number | null | undefined) =>
    String(value ?? '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    }[char] || char));

  const isDenseLayout = (layout: VoucherTemplate['layout']) => ['grid-4x5', 'grid-5x8', 'grid-8x10'].includes(layout);

  const buildVoucherCard = (voucher: Voucher, activeTemplate: VoucherTemplate) => {
    const salesPoint = voucher.sales_point?.name || voucher.sales_point_name || '';
    const design = (activeTemplate as any).design || {};
    const style = design.style || 'blue-strip';
    const number = String(vouchers.findIndex((item) => item.id === voucher.id) + 1).padStart(4, '0');
    const duration = voucher.validity_hours ? `${voucher.validity_hours}h` : `${group.validity_hours}h`;
    const price = `UGX ${Number(voucher.price || group.price).toLocaleString()}`;

    return `
      <div class="voucher-card style-${escapeHtml(style)}">
        <div class="voucher-header">
          ${(design.numbering ?? true) !== false ? `<span class="voucher-number">#${escapeHtml(number)}</span>` : ''}
          <span>${escapeHtml(activeTemplate.header_text || 'STK WIFI POINT')}</span>
        </div>
        <div class="wifi-name">${escapeHtml(group.group_name || activeTemplate.name || 'WiFi Access')}</div>
        <div class="voucher-body">
          ${activeTemplate.logo_url ? `<img class="voucher-logo" src="${escapeHtml(activeTemplate.logo_url)}" alt="Logo" />` : ''}
          <div class="voucher-code-panel">
            <span class="code-label">Voucher Code</span>
            ${activeTemplate.show_voucher_code ? `<strong class="voucher-code">${escapeHtml(voucher.voucher_code)}</strong>` : ''}
          </div>
          <div class="voucher-meta">
            ${activeTemplate.show_voucher_type ? `<div><span>Package:</span><strong>${escapeHtml(voucher.voucher_type || group.group_name)}</strong></div>` : ''}
            ${activeTemplate.show_duration ? `<div><span>Duration:</span><strong>${escapeHtml(duration)}</strong></div>` : ''}
            ${activeTemplate.show_price ? `<div><span>Price:</span><strong>${escapeHtml(price)}</strong></div>` : ''}
            ${activeTemplate.show_sales_point && salesPoint ? `<div><span>Sales Point:</span><strong>${escapeHtml(salesPoint)}</strong></div>` : ''}
            ${activeTemplate.show_expiry && voucher.expires_at ? `<div><span>Expiry:</span><strong>${escapeHtml(new Date(voucher.expires_at).toLocaleDateString())}</strong></div>` : ''}
          </div>
        </div>
        <div class="voucher-footer-block">
          ${activeTemplate.instructions ? `<div class="voucher-instructions">${escapeHtml(activeTemplate.instructions)}</div>` : ''}
          ${activeTemplate.footer_text ? `<div class="voucher-footer">${escapeHtml(activeTemplate.footer_text)}</div>` : ''}
          <div class="powered">Powered by onlifi.net</div>
        </div>
      </div>
    `;
  };

  const buildTemplateHtml = (vouchersToRender: Voucher[], heading: string) => {
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
    const dense = isDenseLayout(activeTemplate.layout);

    return `
      <!DOCTYPE html>
      <html>
      <head>
        <title>${escapeHtml(heading)} - ${escapeHtml(group.group_name)}</title>
        <style>
          @page { size: ${escapeHtml(activeTemplate.paper_size || 'A4')} landscape; margin: ${dense ? '5mm' : '8mm'}; }
          * { box-sizing: border-box; }
          body { font-family: Arial, sans-serif; padding: 0; margin: 0; color: ${escapeHtml(activeTemplate.text_color)}; background: #fff; }
          .print-toolbar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; margin: 0 0 ${dense ? '6px' : '12px'}; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #334155; }
          .print-toolbar button { border: 0; border-radius: 6px; padding: 8px 12px; background: #0f172a; color: #fff; font-weight: 700; cursor: pointer; }
          h1 { text-align: center; margin: 0 0 ${dense ? '6px' : '14px'}; font-size: ${dense ? '12px' : '20px'}; }
          .voucher-grid { font-size: 0; }
          .voucher-card {
            position: relative;
            display: inline-flex;
            flex-direction: column;
            vertical-align: top;
            width: ${dense ? '24mm' : '54mm'};
            min-height: ${dense ? '29mm' : '45mm'};
            margin: ${dense ? '1mm' : '1.6mm'};
            overflow: hidden;
            background: ${escapeHtml(activeTemplate.background_color)};
            color: ${escapeHtml(activeTemplate.text_color)};
            border: 2px solid ${escapeHtml(activeTemplate.accent_color)};
            border-radius: ${dense ? '3px' : '6px'};
            page-break-inside: avoid;
            break-inside: avoid;
            font-size: ${dense ? '5.5px' : '10.8px'};
          }
          .style-modern-blue { width: ${dense ? '31mm' : '64mm'}; min-height: ${dense ? '31mm' : '48mm'}; border-color: #0444cf; }
          .voucher-header { position: relative; min-height: ${dense ? '4mm' : '8mm'}; padding: ${dense ? '1mm 1.5mm' : '1.9mm 2.6mm'}; text-align: center; color: #fff; font-weight: 800; background: ${escapeHtml(activeTemplate.accent_color)}; font-size: ${dense ? '5px' : '11.5px'}; line-height: 1.1; }
          .style-modern-blue .voucher-header { background: #064fe0; text-align: left; padding-left: ${dense ? '2mm' : '7mm'}; }
          .voucher-number { position: absolute; left: ${dense ? '1mm' : '2mm'}; top: 50%; transform: translateY(-50%); font-size: ${dense ? '4.5px' : '8.5px'}; background: rgba(255,255,255,.24); padding: 1px 4px; border-radius: 3px; }
          .style-blue-strip .voucher-number, .style-modern-blue .voucher-number { left: auto; right: ${dense ? '1mm' : '2mm'}; }
          .wifi-name { text-align: center; font-size: ${dense ? '4.8px' : '10px'}; font-weight: 800; color: ${escapeHtml(activeTemplate.accent_color)}; padding: ${dense ? '0.8mm 0' : '1.2mm 0'}; background: rgba(46,204,113,.06); }
          .style-blue-strip .wifi-name, .style-modern-blue .wifi-name { color: #1e8449; background: #fff; }
          .voucher-body { flex: 1 1 auto; display: flex; flex-direction: column; gap: ${dense ? '.8mm' : '1.5mm'}; padding: ${dense ? '1mm 1.5mm' : '2mm 2.8mm'}; }
          .voucher-logo { align-self: center; max-height: ${dense ? '5mm' : '9mm'}; max-width: ${dense ? '12mm' : '22mm'}; object-fit: contain; margin-bottom: ${dense ? '.3mm' : '.6mm'}; }
          .style-modern-blue .voucher-body { padding-top: ${dense ? '1.2mm' : '3mm'}; }
          .voucher-code-panel { border: 1px solid rgba(15,23,42,.16); background: rgba(15,23,42,.035); border-radius: ${dense ? '2px' : '4px'}; padding: ${dense ? '.8mm 1mm' : '1.5mm 1.8mm'}; text-align: center; }
          .code-label { display: block; margin-bottom: ${dense ? '.2mm' : '.7mm'}; color: #64748b; font-size: ${dense ? '3.8px' : '7px'}; font-weight: 700; text-transform: uppercase; }
          .voucher-code { display: block; color: ${escapeHtml(activeTemplate.accent_color)}; font-size: ${dense ? '7px' : '16px'}; line-height: 1.05; word-break: break-all; letter-spacing: .3px; }
          .style-modern-blue .voucher-code { color: #1e3a8a; }
          .voucher-meta { font-size: ${dense ? '4.8px' : '10.8px'}; line-height: 1.25; }
          .voucher-meta div { display: flex; justify-content: space-between; gap: 1mm; margin: ${dense ? '0' : '.6mm'} 0; border-bottom: 1px solid rgba(15,23,42,.06); padding-bottom: ${dense ? '0' : '.4mm'}; }
          .voucher-meta span { display: inline-block; min-width: ${dense ? '10mm' : '17mm'}; color: #111827; }
          .voucher-meta strong { font-weight: 700; }
          .style-modern-blue .voucher-meta { display: flex; flex-wrap: wrap; gap: 1mm 3mm; color: #374151; }
          .style-modern-blue .voucher-meta span { min-width: auto; font-weight: 700; }
          .voucher-footer-block { flex: 0 0 auto; margin: ${dense ? '.5mm 1.5mm 1mm' : '1mm 2.8mm 1.6mm'}; padding-top: ${dense ? '.5mm' : '1mm'}; border-top: 1px solid rgba(15,23,42,.12); text-align: center; }
          .voucher-instructions { margin-bottom: ${dense ? '.3mm' : '.8mm'}; font-size: ${dense ? '4px' : '7px'}; line-height: 1.15; color: #666; }
          .voucher-footer { margin-bottom: ${dense ? '.2mm' : '.5mm'}; font-size: ${dense ? '4px' : '7px'}; line-height: 1.15; color: #666; }
          .powered { color: ${escapeHtml(activeTemplate.accent_color)}; font-size: ${dense ? '3.8px' : '7px'}; font-weight: 700; line-height: 1.15; }
          @media print { .print-toolbar, h1 { display: none; } .voucher-card { page-break-inside: avoid; } }
        </style>
      </head>
      <body>
        <div class="print-toolbar">
          <span>${escapeHtml(heading)} - ${escapeHtml(group.group_name)} (${vouchersToRender.length} vouchers)</span>
          <button type="button" onclick="window.print()">Print this page</button>
        </div>
        <h1>${escapeHtml(group.group_name)} - ${escapeHtml(heading)}</h1>
        <div class="voucher-grid">
          ${vouchersToRender.map((voucher) => buildVoucherCard(voucher, activeTemplate)).join('')}
        </div>
      </body>
      </html>
    `;
  };

  const printTemplateVouchers = (vouchersToPrint: Voucher[], heading: string) => {
    const html = buildTemplateHtml(vouchersToPrint, heading);
    const url = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
    window.open(url, '_blank', 'noopener,noreferrer');
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
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
              <Printer className="w-4 h-4" />
              Print Unused
            </button>
            <button
              onClick={handleDownloadAll}
              disabled={filteredVouchers.length === 0}
              className="flex items-center gap-2 px-3 py-1.5 text-sm bg-primary hover:bg-primary/90 disabled:bg-muted disabled:text-muted-foreground text-primary-foreground rounded-lg transition-colors"
            >
              <Printer className="w-4 h-4" />
              Print Current
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
