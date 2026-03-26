import { useState, useEffect, useRef } from 'react';
import { Plus, Edit2, Trash2, Printer, Star, Eye, Palette, Layout, FileText } from 'lucide-react';

interface VoucherTemplate {
  id: number;
  name: string;
  description: string | null;
  layout: 'single' | 'grid-2x2' | 'grid-2x4' | 'grid-3x3';
  paper_size: string;
  logo_url: string | null;
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
  header_text: string | null;
  footer_text: string | null;
  instructions: string | null;
  is_default: boolean;
  is_active: boolean;
}

interface Voucher {
  id: number;
  code: string;
  voucher_type?: { type_name: string; duration_hours: number; base_amount: number };
  sales_point?: { name: string };
  expires_at?: string;
}

const LAYOUT_OPTIONS = [
  { value: 'single', label: 'Single (1 per page)', cols: 1, rows: 1 },
  { value: 'grid-2x2', label: 'Grid 2x2 (4 per page)', cols: 2, rows: 2 },
  { value: 'grid-2x4', label: 'Grid 2x4 (8 per page)', cols: 2, rows: 4 },
  { value: 'grid-3x3', label: 'Grid 3x3 (9 per page)', cols: 3, rows: 3 },
];

const DEFAULT_SKINS = [
  {
    id: 'classic',
    name: 'Classic',
    description: 'Clean and professional look',
    preview: 'bg-white border-gray-200',
    settings: {
      background_color: '#ffffff',
      text_color: '#1f2937',
      accent_color: '#3b82f6',
      header_text: 'WiFi Voucher',
      footer_text: 'Thank you for choosing us!',
      instructions: 'Connect to WiFi network and enter the code above',
    }
  },
  {
    id: 'modern-dark',
    name: 'Modern Dark',
    description: 'Sleek dark theme',
    preview: 'bg-slate-900 border-slate-700',
    settings: {
      background_color: '#0f172a',
      text_color: '#f1f5f9',
      accent_color: '#22d3ee',
      header_text: 'Internet Access',
      footer_text: 'Enjoy your connection!',
      instructions: 'Select our network and enter this code',
    }
  },
  {
    id: 'vibrant',
    name: 'Vibrant',
    description: 'Bold and colorful',
    preview: 'bg-gradient-to-br from-purple-600 to-pink-500',
    settings: {
      background_color: '#7c3aed',
      text_color: '#ffffff',
      accent_color: '#fbbf24',
      header_text: '🌐 WiFi Pass',
      footer_text: 'Stay Connected!',
      instructions: 'Join our network with this code',
    }
  },
  {
    id: 'minimal',
    name: 'Minimal',
    description: 'Simple and clean',
    preview: 'bg-gray-50 border-gray-100',
    settings: {
      background_color: '#f9fafb',
      text_color: '#374151',
      accent_color: '#6b7280',
      header_text: '',
      footer_text: '',
      instructions: '',
    }
  },
  {
    id: 'nature',
    name: 'Nature',
    description: 'Fresh green theme',
    preview: 'bg-emerald-50 border-emerald-200',
    settings: {
      background_color: '#ecfdf5',
      text_color: '#065f46',
      accent_color: '#10b981',
      header_text: 'WiFi Access Code',
      footer_text: 'Enjoy browsing!',
      instructions: 'Connect to our WiFi and use this code',
    }
  },
  {
    id: 'sunset',
    name: 'Sunset',
    description: 'Warm orange tones',
    preview: 'bg-orange-50 border-orange-200',
    settings: {
      background_color: '#fff7ed',
      text_color: '#9a3412',
      accent_color: '#f97316',
      header_text: 'Internet Voucher',
      footer_text: 'Happy Surfing!',
      instructions: 'Enter this code after connecting',
    }
  },
];

export function VoucherTemplates() {
  const [templates, setTemplates] = useState<VoucherTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [showDialog, setShowDialog] = useState(false);
  const [showPreview, setShowPreview] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<VoucherTemplate | null>(null);
  const [previewTemplate, setPreviewTemplate] = useState<VoucherTemplate | null>(null);
  const [selectedSkin, setSelectedSkin] = useState<string | null>(null);
  const printRef = useRef<HTMLDivElement>(null);

  const [formData, setFormData] = useState<{
    name: string;
    description: string;
    layout: 'single' | 'grid-2x2' | 'grid-2x4' | 'grid-3x3';
    paper_size: string;
    logo_url: string;
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
    header_text: string;
    footer_text: string;
    instructions: string;
    is_default: boolean;
  }>({
    name: '',
    description: '',
    layout: 'grid-2x4',
    paper_size: 'A4',
    logo_url: '',
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
    header_text: '',
    footer_text: '',
    instructions: '',
    is_default: false,
  });

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
    return {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...(token && { 'Authorization': `Bearer ${token}` }),
    };
  };

  useEffect(() => {
    loadTemplates();
  }, []);

  const loadTemplates = async () => {
    try {
      setLoading(true);
      const response = await fetch('/api/voucher-templates', { headers: getAuthHeaders() });
      if (response.ok) {
        const data = await response.json();
        setTemplates(data.templates || []);
      }
    } catch (error) {
      console.error('Failed to load templates:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    try {
      const url = editingTemplate 
        ? `/api/voucher-templates/${editingTemplate.id}`
        : '/api/voucher-templates';
      
      const response = await fetch(url, {
        method: editingTemplate ? 'PUT' : 'POST',
        headers: getAuthHeaders(),
        body: JSON.stringify(formData),
      });

      if (!response.ok) {
        const data = await response.json();
        alert(data.error || 'Failed to save template');
        return;
      }

      setShowDialog(false);
      setEditingTemplate(null);
      resetForm();
      loadTemplates();
    } catch (error) {
      console.error('Failed to save template:', error);
      alert('Failed to save template');
    }
  };

  const handleEdit = (template: VoucherTemplate) => {
    setEditingTemplate(template);
    setFormData({
      name: template.name,
      description: template.description || '',
      layout: template.layout as 'single' | 'grid-2x2' | 'grid-2x4' | 'grid-3x3',
      paper_size: template.paper_size,
      logo_url: template.logo_url || '',
      background_color: template.background_color,
      text_color: template.text_color,
      accent_color: template.accent_color,
      show_voucher_code: template.show_voucher_code,
      show_voucher_type: template.show_voucher_type,
      show_sales_point: template.show_sales_point,
      show_duration: template.show_duration,
      show_price: template.show_price,
      show_expiry: template.show_expiry,
      show_qr_code: template.show_qr_code,
      header_text: template.header_text || '',
      footer_text: template.footer_text || '',
      instructions: template.instructions || '',
      is_default: template.is_default,
    });
    setShowDialog(true);
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this template?')) return;

    try {
      const response = await fetch(`/api/voucher-templates/${id}`, {
        method: 'DELETE',
        headers: getAuthHeaders(),
      });

      if (!response.ok) {
        alert('Failed to delete template');
        return;
      }

      loadTemplates();
    } catch (error) {
      console.error('Failed to delete template:', error);
    }
  };

  const handleSetDefault = async (id: number) => {
    try {
      const response = await fetch(`/api/voucher-templates/${id}/set-default`, {
        method: 'POST',
        headers: getAuthHeaders(),
      });

      if (response.ok) {
        loadTemplates();
      }
    } catch (error) {
      console.error('Failed to set default:', error);
    }
  };

  const handlePreview = (template: VoucherTemplate) => {
    setPreviewTemplate(template);
    setShowPreview(true);
  };

  const resetForm = () => {
    setFormData({
      name: '',
      description: '',
      layout: 'grid-2x4',
      paper_size: 'A4',
      logo_url: '',
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
      header_text: '',
      footer_text: '',
      instructions: '',
      is_default: false,
    });
  };

  const openCreateDialog = () => {
    setEditingTemplate(null);
    resetForm();
    setSelectedSkin(null);
    setShowDialog(true);
  };

  const applySkin = (skinId: string) => {
    const skin = DEFAULT_SKINS.find(s => s.id === skinId);
    if (skin) {
      setSelectedSkin(skinId);
      setFormData(prev => ({
        ...prev,
        ...skin.settings,
      }));
    }
  };

  const getLayoutInfo = (layout: string) => {
    return LAYOUT_OPTIONS.find(l => l.value === layout) || LAYOUT_OPTIONS[2];
  };

  // Sample voucher for preview
  const sampleVoucher: Voucher = {
    id: 1,
    code: 'WIFI-ABC123',
    voucher_type: { type_name: '1 Hour', duration_hours: 1, base_amount: 500 },
    sales_point: { name: 'Main Office' },
    expires_at: new Date(Date.now() + 86400000).toISOString(),
  };

  const renderVoucherCard = (template: VoucherTemplate, voucher: Voucher, index: number) => (
    <div
      key={index}
      className="border-2 border-dashed border-gray-300 rounded-lg p-4 flex flex-col"
      style={{
        backgroundColor: template.background_color,
        color: template.text_color,
      }}
    >
      {template.header_text && (
        <div className="text-center text-sm font-semibold mb-2" style={{ color: template.accent_color }}>
          {template.header_text}
        </div>
      )}
      
      {template.show_voucher_code && (
        <div className="text-center mb-2">
          <span className="text-2xl font-bold tracking-wider" style={{ color: template.accent_color }}>
            {voucher.code}
          </span>
        </div>
      )}

      <div className="space-y-1 text-sm flex-1">
        {template.show_voucher_type && voucher.voucher_type && (
          <div className="flex justify-between">
            <span className="opacity-70">Type:</span>
            <span className="font-medium">{voucher.voucher_type.type_name}</span>
          </div>
        )}
        {template.show_duration && voucher.voucher_type && (
          <div className="flex justify-between">
            <span className="opacity-70">Duration:</span>
            <span className="font-medium">{voucher.voucher_type.duration_hours}h</span>
          </div>
        )}
        {template.show_price && voucher.voucher_type && (
          <div className="flex justify-between">
            <span className="opacity-70">Price:</span>
            <span className="font-medium">UGX {voucher.voucher_type.base_amount.toLocaleString()}</span>
          </div>
        )}
        {template.show_sales_point && voucher.sales_point && (
          <div className="flex justify-between">
            <span className="opacity-70">Location:</span>
            <span className="font-medium">{voucher.sales_point.name}</span>
          </div>
        )}
        {template.show_expiry && voucher.expires_at && (
          <div className="flex justify-between">
            <span className="opacity-70">Expires:</span>
            <span className="font-medium">{new Date(voucher.expires_at).toLocaleDateString()}</span>
          </div>
        )}
      </div>

      {template.instructions && (
        <div className="mt-2 pt-2 border-t text-xs opacity-70" style={{ borderColor: template.accent_color }}>
          {template.instructions}
        </div>
      )}

      {template.footer_text && (
        <div className="text-center text-xs mt-2 opacity-60">
          {template.footer_text}
        </div>
      )}
    </div>
  );

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
          <h1 className="text-2xl font-bold text-foreground flex items-center gap-2">
            <FileText className="w-7 h-7 text-primary" />
            Voucher Templates
          </h1>
          <p className="text-sm text-muted-foreground mt-1">
            Design and manage voucher print templates
          </p>
        </div>
        <button
          onClick={openCreateDialog}
          className="flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
        >
          <Plus className="w-4 h-4" />
          Create Template
        </button>
      </div>

      {/* Templates Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {templates.map((template) => {
          const layoutInfo = getLayoutInfo(template.layout);
          return (
            <div
              key={template.id}
              className={`bg-card border rounded-lg p-6 hover:shadow-lg transition-shadow ${
                template.is_default ? 'border-primary border-2' : 'border-border'
              }`}
            >
              <div className="flex justify-between items-start mb-4">
                <div>
                  <div className="flex items-center gap-2">
                    <h3 className="text-lg font-semibold text-card-foreground">{template.name}</h3>
                    {template.is_default && (
                      <span className="px-2 py-0.5 bg-primary/10 text-primary text-xs rounded-full flex items-center gap-1">
                        <Star className="w-3 h-3" /> Default
                      </span>
                    )}
                  </div>
                  <p className="text-sm text-muted-foreground mt-1">{template.description}</p>
                </div>
              </div>

              <div className="space-y-2 mb-4">
                <div className="flex items-center gap-2 text-sm">
                  <Layout className="w-4 h-4 text-primary" />
                  <span className="text-muted-foreground">Layout:</span>
                  <span className="font-medium text-card-foreground">{layoutInfo.label}</span>
                </div>
                <div className="flex items-center gap-2 text-sm">
                  <Palette className="w-4 h-4 text-primary" />
                  <span className="text-muted-foreground">Colors:</span>
                  <div className="flex gap-1">
                    <div className="w-5 h-5 rounded border" style={{ backgroundColor: template.background_color }} />
                    <div className="w-5 h-5 rounded border" style={{ backgroundColor: template.text_color }} />
                    <div className="w-5 h-5 rounded border" style={{ backgroundColor: template.accent_color }} />
                  </div>
                </div>
              </div>

              <div className="flex gap-2 pt-4 border-t border-border">
                <button
                  onClick={() => handlePreview(template)}
                  className="flex-1 flex items-center justify-center gap-1 px-3 py-2 text-sm text-muted-foreground hover:text-primary hover:bg-muted rounded-lg transition-colors"
                >
                  <Eye className="w-4 h-4" /> Preview
                </button>
                <button
                  onClick={() => handleEdit(template)}
                  className="flex-1 flex items-center justify-center gap-1 px-3 py-2 text-sm text-muted-foreground hover:text-primary hover:bg-muted rounded-lg transition-colors"
                >
                  <Edit2 className="w-4 h-4" /> Edit
                </button>
                {!template.is_default && (
                  <button
                    onClick={() => handleSetDefault(template.id)}
                    className="flex items-center justify-center gap-1 px-3 py-2 text-sm text-muted-foreground hover:text-yellow-500 hover:bg-muted rounded-lg transition-colors"
                    title="Set as default"
                  >
                    <Star className="w-4 h-4" />
                  </button>
                )}
                <button
                  onClick={() => handleDelete(template.id)}
                  className="flex items-center justify-center gap-1 px-3 py-2 text-sm text-muted-foreground hover:text-destructive hover:bg-muted rounded-lg transition-colors"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>
            </div>
          );
        })}
      </div>

      {templates.length === 0 && (
        <div className="text-center py-12">
          <FileText className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
          <h3 className="text-lg font-medium text-card-foreground mb-2">No templates yet</h3>
          <p className="text-sm text-muted-foreground mb-4">
            Create your first voucher template to start printing vouchers
          </p>
          <button
            onClick={openCreateDialog}
            className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
          >
            <Plus className="w-4 h-4" />
            Create Template
          </button>
        </div>
      )}

      {/* Create/Edit Dialog */}
      {showDialog && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-card border border-border rounded-lg w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="p-6 border-b border-border">
              <h2 className="text-xl font-semibold text-card-foreground">
                {editingTemplate ? 'Edit Template' : 'Create Template'}
              </h2>
            </div>

            <form onSubmit={handleSubmit} className="p-6 space-y-4">
              {/* Skin Selector - Only show when creating new template */}
              {!editingTemplate && (
                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-3">
                    Start with a Skin (Optional)
                  </label>
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    {DEFAULT_SKINS.map((skin) => (
                      <button
                        key={skin.id}
                        type="button"
                        onClick={() => applySkin(skin.id)}
                        className={`p-3 rounded-lg border-2 text-left transition-all ${
                          selectedSkin === skin.id
                            ? 'border-primary ring-2 ring-primary/20'
                            : 'border-border hover:border-primary/50'
                        }`}
                      >
                        <div 
                          className={`w-full h-8 rounded mb-2 border ${skin.preview}`}
                          style={{ backgroundColor: skin.settings.background_color }}
                        />
                        <p className="text-sm font-medium text-card-foreground">{skin.name}</p>
                        <p className="text-xs text-muted-foreground">{skin.description}</p>
                      </button>
                    ))}
                  </div>
                </div>
              )}

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-2">Name *</label>
                  <input
                    type="text"
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="e.g., Standard Voucher"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-2">Layout *</label>
                  <select
                    value={formData.layout}
                    onChange={(e) => setFormData({ ...formData, layout: e.target.value as any })}
                    className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                  >
                    {LAYOUT_OPTIONS.map(opt => (
                      <option key={opt.value} value={opt.value}>{opt.label}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">Description</label>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                  rows={2}
                  placeholder="Optional description"
                />
              </div>

              {/* Colors */}
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-2">Background</label>
                  <div className="flex gap-2">
                    <input
                      type="color"
                      value={formData.background_color}
                      onChange={(e) => setFormData({ ...formData, background_color: e.target.value })}
                      className="w-10 h-10 rounded border cursor-pointer"
                    />
                    <input
                      type="text"
                      value={formData.background_color}
                      onChange={(e) => setFormData({ ...formData, background_color: e.target.value })}
                      className="flex-1 px-3 py-2 bg-background border border-input rounded-lg text-sm"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-2">Text Color</label>
                  <div className="flex gap-2">
                    <input
                      type="color"
                      value={formData.text_color}
                      onChange={(e) => setFormData({ ...formData, text_color: e.target.value })}
                      className="w-10 h-10 rounded border cursor-pointer"
                    />
                    <input
                      type="text"
                      value={formData.text_color}
                      onChange={(e) => setFormData({ ...formData, text_color: e.target.value })}
                      className="flex-1 px-3 py-2 bg-background border border-input rounded-lg text-sm"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-2">Accent Color</label>
                  <div className="flex gap-2">
                    <input
                      type="color"
                      value={formData.accent_color}
                      onChange={(e) => setFormData({ ...formData, accent_color: e.target.value })}
                      className="w-10 h-10 rounded border cursor-pointer"
                    />
                    <input
                      type="text"
                      value={formData.accent_color}
                      onChange={(e) => setFormData({ ...formData, accent_color: e.target.value })}
                      className="flex-1 px-3 py-2 bg-background border border-input rounded-lg text-sm"
                    />
                  </div>
                </div>
              </div>

              {/* Display Options */}
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-3">Display Options</label>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                  {[
                    { key: 'show_voucher_code', label: 'Voucher Code' },
                    { key: 'show_voucher_type', label: 'Voucher Type' },
                    { key: 'show_sales_point', label: 'Sales Point' },
                    { key: 'show_duration', label: 'Duration' },
                    { key: 'show_price', label: 'Price' },
                    { key: 'show_expiry', label: 'Expiry Date' },
                    { key: 'show_qr_code', label: 'QR Code' },
                  ].map(opt => (
                    <label key={opt.key} className="flex items-center gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={(formData as any)[opt.key]}
                        onChange={(e) => setFormData({ ...formData, [opt.key]: e.target.checked })}
                        className="w-4 h-4 rounded border-input"
                      />
                      <span className="text-sm text-card-foreground">{opt.label}</span>
                    </label>
                  ))}
                </div>
              </div>

              {/* Text Fields */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-2">Header Text</label>
                  <input
                    type="text"
                    value={formData.header_text}
                    onChange={(e) => setFormData({ ...formData, header_text: e.target.value })}
                    className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="e.g., WiFi Voucher"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-card-foreground mb-2">Footer Text</label>
                  <input
                    type="text"
                    value={formData.footer_text}
                    onChange={(e) => setFormData({ ...formData, footer_text: e.target.value })}
                    className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="e.g., Thank you!"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">Instructions</label>
                <textarea
                  value={formData.instructions}
                  onChange={(e) => setFormData({ ...formData, instructions: e.target.value })}
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                  rows={2}
                  placeholder="e.g., Connect to WiFi and enter code"
                />
              </div>

              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={formData.is_default}
                  onChange={(e) => setFormData({ ...formData, is_default: e.target.checked })}
                  className="w-4 h-4 rounded border-input"
                />
                <span className="text-sm text-card-foreground">Set as default template</span>
              </label>

              <div className="flex gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => {
                    setShowDialog(false);
                    setEditingTemplate(null);
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
                  {editingTemplate ? 'Update' : 'Create'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Preview Dialog */}
      {showPreview && previewTemplate && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-card border border-border rounded-lg w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div className="p-6 border-b border-border flex justify-between items-center">
              <h2 className="text-xl font-semibold text-card-foreground">
                Preview: {previewTemplate.name}
              </h2>
              <div className="flex gap-2">
                <button
                  onClick={() => window.print()}
                  className="flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
                >
                  <Printer className="w-4 h-4" /> Print
                </button>
                <button
                  onClick={() => setShowPreview(false)}
                  className="px-4 py-2 border border-border text-card-foreground rounded-lg hover:bg-muted transition-colors"
                >
                  Close
                </button>
              </div>
            </div>

            <div className="p-6" ref={printRef}>
              <div 
                className="bg-white p-8 shadow-lg mx-auto"
                style={{ 
                  width: previewTemplate.paper_size === 'A4' ? '210mm' : '216mm',
                  minHeight: previewTemplate.paper_size === 'A4' ? '297mm' : '279mm',
                }}
              >
                {(() => {
                  const layoutInfo = getLayoutInfo(previewTemplate.layout);
                  const totalCards = layoutInfo.cols * layoutInfo.rows;
                  const vouchers = Array(totalCards).fill(sampleVoucher);
                  
                  return (
                    <div 
                      className="grid gap-4 h-full"
                      style={{
                        gridTemplateColumns: `repeat(${layoutInfo.cols}, 1fr)`,
                        gridTemplateRows: `repeat(${layoutInfo.rows}, 1fr)`,
                      }}
                    >
                      {vouchers.map((v, i) => renderVoucherCard(previewTemplate, v, i))}
                    </div>
                  );
                })()}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
