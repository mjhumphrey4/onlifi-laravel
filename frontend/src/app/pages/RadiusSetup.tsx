import { useState, useEffect } from 'react';
import { Server, Plus, Copy, Download, RefreshCw, Trash2, CheckCircle, AlertCircle } from 'lucide-react';

interface NasEntry {
  id: number;
  router_identifier: string;
  shortname: string;
  description: string;
  secret: string;
  created_at: string;
  updated_at: string;
}

interface RadiusConfig {
  server: string;
  auth_port: number;
  acct_port: number;
  nas_identifier: string;
  secret: string;
}

export function RadiusSetup() {
  const [nasEntries, setNasEntries] = useState<NasEntry[]>([]);
  const [radiusServer, setRadiusServer] = useState('');
  const [radiusPort, setRadiusPort] = useState(1812);
  const [radiusAcctPort, setRadiusAcctPort] = useState(1813);
  const [loading, setLoading] = useState(true);
  const [showAddDialog, setShowAddDialog] = useState(false);
  const [newNasName, setNewNasName] = useState('');
  const [newNasDescription, setNewNasDescription] = useState('');
  const [selectedNas, setSelectedNas] = useState<NasEntry | null>(null);
  const [mikrotikScript, setMikrotikScript] = useState('');
  const [copiedId, setCopiedId] = useState<number | null>(null);

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return headers;
  };

  const loadNasEntries = async () => {
    try {
      const response = await fetch('/api/nas', { headers: getAuthHeaders() });
      if (!response.ok) throw new Error('Failed to fetch NAS entries');
      const data = await response.json();
      setNasEntries(data.nas_entries || []);
      setRadiusServer(data.radius_server || '');
      setRadiusPort(data.radius_port || 1812);
      setRadiusAcctPort(data.radius_acct_port || 1813);
    } catch (error) {
      console.error('Error loading NAS entries:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadNasEntries();
  }, []);

  const handleAddNas = async () => {
    if (!newNasName.trim()) {
      alert('Please enter a router name');
      return;
    }

    try {
      const response = await fetch('/api/nas', {
        method: 'POST',
        headers: getAuthHeaders(),
        body: JSON.stringify({
          name: newNasName,
          description: newNasDescription,
        }),
      });

      if (!response.ok) throw new Error('Failed to create NAS entry');
      
      const data = await response.json();
      setMikrotikScript(data.mikrotik_script || '');
      setShowAddDialog(false);
      setNewNasName('');
      setNewNasDescription('');
      await loadNasEntries();
      
      // Show the script for the newly created NAS
      const newNas = nasEntries.find(n => n.router_identifier === data.router_identifier);
      if (newNas) setSelectedNas(newNas);
    } catch (error) {
      console.error('Error creating NAS entry:', error);
      alert('Failed to create NAS entry');
    }
  };

  const handleDeleteNas = async (id: number) => {
    if (!confirm('Are you sure you want to delete this NAS entry? The router will no longer be able to authenticate users.')) {
      return;
    }

    try {
      const response = await fetch(`/api/nas/${id}`, {
        method: 'DELETE',
        headers: getAuthHeaders(),
      });

      if (!response.ok) throw new Error('Failed to delete NAS entry');
      await loadNasEntries();
    } catch (error) {
      console.error('Error deleting NAS entry:', error);
      alert('Failed to delete NAS entry');
    }
  };

  const handleViewScript = async (nas: NasEntry) => {
    setSelectedNas(nas);
    try {
      const response = await fetch(`/api/nas/${nas.id}`, { headers: getAuthHeaders() });
      if (!response.ok) throw new Error('Failed to fetch NAS details');
      const data = await response.json();
      setMikrotikScript(data.mikrotik_script || '');
    } catch (error) {
      console.error('Error fetching NAS details:', error);
    }
  };

  const handleDownloadScript = async (nas: NasEntry) => {
    try {
      const response = await fetch(`/api/nas/${nas.id}/mikrotik-script`, { headers: getAuthHeaders() });
      if (!response.ok) throw new Error('Failed to download script');
      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `radius-config-${nas.shortname}.rsc`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);
    } catch (error) {
      console.error('Error downloading script:', error);
      alert('Failed to download script');
    }
  };

  const copyToClipboard = (text: string, id: number) => {
    navigator.clipboard.writeText(text);
    setCopiedId(id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <RefreshCw className="w-6 h-6 text-primary animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8">
        <h1 className="text-2xl sm:text-3xl text-foreground mb-2">RADIUS Setup</h1>
        <p className="text-sm text-muted-foreground">Configure your MikroTik routers to authenticate users via FreeRADIUS</p>
      </div>

      {/* RADIUS Server Info */}
      <div className="bg-gradient-to-br from-primary to-primary/80 rounded-lg p-6 mb-6 text-primary-foreground">
        <div className="flex items-center gap-2 mb-4">
          <Server className="w-5 h-5" />
          <h2 className="text-lg font-semibold">RADIUS Server Information</h2>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <p className="text-xs opacity-75 mb-1">Server Address</p>
            <p className="font-mono font-semibold">{radiusServer}</p>
          </div>
          <div>
            <p className="text-xs opacity-75 mb-1">Auth Port</p>
            <p className="font-mono font-semibold">{radiusPort}</p>
          </div>
          <div>
            <p className="text-xs opacity-75 mb-1">Accounting Port</p>
            <p className="font-mono font-semibold">{radiusAcctPort}</p>
          </div>
        </div>
      </div>

      {/* Setup Instructions */}
      <div className="bg-card border border-border rounded-lg p-6 mb-6">
        <h3 className="text-lg font-semibold text-card-foreground mb-4">Quick Setup Guide</h3>
        <ol className="space-y-3 text-sm text-muted-foreground">
          <li className="flex gap-3">
            <span className="flex-shrink-0 w-6 h-6 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-xs font-bold">1</span>
            <span>Click <strong>"Add Router"</strong> below to register a new MikroTik router</span>
          </li>
          <li className="flex gap-3">
            <span className="flex-shrink-0 w-6 h-6 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-xs font-bold">2</span>
            <span>Download or copy the generated MikroTik configuration script</span>
          </li>
          <li className="flex gap-3">
            <span className="flex-shrink-0 w-6 h-6 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-xs font-bold">3</span>
            <span>Run the script on your MikroTik router via Winbox or Terminal</span>
          </li>
          <li className="flex gap-3">
            <span className="flex-shrink-0 w-6 h-6 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-xs font-bold">4</span>
            <span>Your router is now connected! Users can authenticate with voucher codes</span>
          </li>
        </ol>
      </div>

      {/* NAS Entries List */}
      <div className="bg-card border border-border rounded-lg p-6 mb-6">
        <div className="flex items-center justify-between mb-6">
          <h2 className="text-lg font-semibold text-card-foreground">Registered Routers</h2>
          <button
            onClick={() => setShowAddDialog(true)}
            className="flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
          >
            <Plus className="w-4 h-4" />
            Add Router
          </button>
        </div>

        {nasEntries.length === 0 ? (
          <div className="text-center py-12">
            <Server className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
            <p className="text-muted-foreground mb-4">No routers registered yet</p>
            <button
              onClick={() => setShowAddDialog(true)}
              className="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
            >
              Register Your First Router
            </button>
          </div>
        ) : (
          <div className="space-y-4">
            {nasEntries.map((nas) => (
              <div key={nas.id} className="bg-muted/50 rounded-lg p-4 border border-border">
                <div className="flex items-start justify-between mb-3">
                  <div className="flex-1">
                    <h3 className="font-semibold text-card-foreground mb-1">{nas.shortname}</h3>
                    {nas.description && (
                      <p className="text-sm text-muted-foreground mb-2">{nas.description}</p>
                    )}
                    <div className="flex items-center gap-2 mb-2">
                      <span className="text-xs text-muted-foreground">Router ID:</span>
                      <code className="text-xs bg-background px-2 py-1 rounded font-mono">{nas.router_identifier}</code>
                      <button
                        onClick={() => copyToClipboard(nas.router_identifier, nas.id)}
                        className="p-1 hover:bg-background rounded transition-colors"
                        title="Copy Router ID"
                      >
                        {copiedId === nas.id ? (
                          <CheckCircle className="w-3 h-3 text-emerald-500" />
                        ) : (
                          <Copy className="w-3 h-3 text-muted-foreground" />
                        )}
                      </button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      Created: {new Date(nas.created_at).toLocaleString()}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => handleViewScript(nas)}
                      className="p-2 hover:bg-background rounded transition-colors"
                      title="View Configuration"
                    >
                      <Server className="w-4 h-4 text-primary" />
                    </button>
                    <button
                      onClick={() => handleDownloadScript(nas)}
                      className="p-2 hover:bg-background rounded transition-colors"
                      title="Download Script"
                    >
                      <Download className="w-4 h-4 text-blue-500" />
                    </button>
                    <button
                      onClick={() => handleDeleteNas(nas.id)}
                      className="p-2 hover:bg-background rounded transition-colors"
                      title="Delete"
                    >
                      <Trash2 className="w-4 h-4 text-destructive" />
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Add NAS Dialog */}
      {showAddDialog && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-card rounded-lg p-6 max-w-md w-full">
            <h3 className="text-lg font-semibold text-card-foreground mb-4">Register New Router</h3>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Router Name *
                </label>
                <input
                  type="text"
                  value={newNasName}
                  onChange={(e) => setNewNasName(e.target.value)}
                  placeholder="e.g., Main Office Router"
                  className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Description (Optional)
                </label>
                <textarea
                  value={newNasDescription}
                  onChange={(e) => setNewNasDescription(e.target.value)}
                  placeholder="e.g., Router at main office location"
                  rows={3}
                  className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                />
              </div>
              <div className="flex gap-3 pt-4">
                <button
                  onClick={() => {
                    setShowAddDialog(false);
                    setNewNasName('');
                    setNewNasDescription('');
                  }}
                  className="flex-1 px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={handleAddNas}
                  className="flex-1 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
                >
                  Register Router
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* MikroTik Script Viewer */}
      {selectedNas && mikrotikScript && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-card rounded-lg p-6 max-w-3xl w-full max-h-[80vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-semibold text-card-foreground">
                MikroTik Configuration: {selectedNas.shortname}
              </h3>
              <button
                onClick={() => {
                  setSelectedNas(null);
                  setMikrotikScript('');
                }}
                className="text-muted-foreground hover:text-foreground"
              >
                ✕
              </button>
            </div>
            
            <div className="bg-muted/50 rounded-lg p-4 mb-4 border border-border">
              <div className="flex items-start gap-2 mb-2">
                <AlertCircle className="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" />
                <div className="text-sm text-muted-foreground">
                  <p className="font-semibold text-card-foreground mb-1">Important:</p>
                  <p>Copy this script and run it on your MikroTik router. This will configure RADIUS authentication for your hotspot users.</p>
                </div>
              </div>
            </div>

            <div className="relative">
              <pre className="bg-background p-4 rounded-lg overflow-x-auto text-xs font-mono border border-border">
                {mikrotikScript}
              </pre>
              <button
                onClick={() => {
                  navigator.clipboard.writeText(mikrotikScript);
                  alert('Script copied to clipboard!');
                }}
                className="absolute top-2 right-2 px-3 py-1 bg-primary text-primary-foreground rounded text-xs flex items-center gap-1 hover:bg-primary/90"
              >
                <Copy className="w-3 h-3" />
                Copy
              </button>
            </div>

            <div className="flex gap-3 mt-4">
              <button
                onClick={() => handleDownloadScript(selectedNas)}
                className="flex-1 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors flex items-center justify-center gap-2"
              >
                <Download className="w-4 h-4" />
                Download Script
              </button>
              <button
                onClick={() => {
                  setSelectedNas(null);
                  setMikrotikScript('');
                }}
                className="px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
