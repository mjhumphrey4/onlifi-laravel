import { useState, useEffect } from 'react';
import { Server, Plus, Trash2, ExternalLink, CheckCircle, XCircle, RefreshCw, Globe, MapPin } from 'lucide-react';

interface Router {
  id: number;
  name: string;
  ip_address: string;
  location: string | null;
  uptime_kuma_url: string | null;
  is_active: boolean;
  status: 'online' | 'offline' | 'unknown';
}

export function Devices() {
  const [routers, setRouters] = useState<Router[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAddModal, setShowAddModal] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formData, setFormData] = useState({
    name: '',
    ip_address: '',
    location: '',
    uptime_kuma_url: '',
  });

  useEffect(() => {
    loadData();
    const interval = setInterval(loadData, 30000); // Refresh every 30 seconds
    return () => clearInterval(interval);
  }, []);

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return headers;
  };

  const loadData = async () => {
    try {
      const headers = getAuthHeaders();
      const routersRes = await fetch('/api/routers', { headers });

      if (routersRes.ok) {
        const routersData = await routersRes.json();
        const routersList = Array.isArray(routersData) ? routersData : routersData.data || [];
        
        // Check status for each router (simple ping check via Uptime Kuma if configured)
        const routersWithStatus = routersList.map((router: any) => ({
          ...router,
          status: router.last_seen && (Date.now() - new Date(router.last_seen).getTime() < 600000) 
            ? 'online' 
            : router.last_seen ? 'offline' : 'unknown'
        }));
        
        setRouters(routersWithStatus);
      }
    } catch (error) {
      console.error('Failed to load devices:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleAddRouter = async () => {
    if (!formData.name.trim() || !formData.ip_address.trim()) return;
    
    setSaving(true);
    try {
      const headers = getAuthHeaders();
      const res = await fetch('/api/routers', {
        method: 'POST',
        headers,
        body: JSON.stringify(formData),
      });

      if (res.ok) {
        setShowAddModal(false);
        setFormData({ name: '', ip_address: '', location: '', uptime_kuma_url: '' });
        loadData();
      } else {
        const error = await res.json();
        alert(error.message || 'Failed to add router');
      }
    } catch (error) {
      console.error('Failed to add router:', error);
      alert('Failed to add router');
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteRouter = async (id: number) => {
    if (!confirm('Are you sure you want to delete this router?')) return;
    
    try {
      const headers = getAuthHeaders();
      const res = await fetch(`/api/routers/${id}`, {
        method: 'DELETE',
        headers,
      });

      if (res.ok) {
        loadData();
      }
    } catch (error) {
      console.error('Failed to delete router:', error);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'online':
        return (
          <span className="flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-emerald-500/10 text-emerald-500">
            <CheckCircle className="w-3 h-3" /> Online
          </span>
        );
      case 'offline':
        return (
          <span className="flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-red-500/10 text-red-500">
            <XCircle className="w-3 h-3" /> Offline
          </span>
        );
      default:
        return (
          <span className="flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-muted text-muted-foreground">
            Unknown
          </span>
        );
    }
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
      {/* Header */}
      <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-1 flex items-center gap-2">
            <Server className="w-8 h-8 text-primary" />
            Routers
          </h1>
          <p className="text-sm text-muted-foreground">
            Register and monitor your network routers via Uptime Kuma
          </p>
        </div>
        <button
          onClick={() => setShowAddModal(true)}
          className="flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
        >
          <Plus className="w-4 h-4" />
          Add Router
        </button>
      </div>

      {/* Routers List */}
      <div className="bg-card border border-border rounded-lg overflow-hidden">
        {routers.length === 0 ? (
          <div className="p-12 text-center">
            <Server className="w-16 h-16 text-muted-foreground mx-auto mb-4" />
            <h3 className="text-lg font-semibold text-card-foreground mb-2">No routers registered</h3>
            <p className="text-sm text-muted-foreground mb-4">
              Add your first router to start monitoring its uptime
            </p>
            <button
              onClick={() => setShowAddModal(true)}
              className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
            >
              <Plus className="w-4 h-4" />
              Add Router
            </button>
          </div>
        ) : (
          <div className="divide-y divide-border">
            {routers.map((router) => (
              <div key={router.id} className="p-4 hover:bg-muted/30 transition-colors">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-4">
                    <div className="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center">
                      <Server className="w-6 h-6 text-primary" />
                    </div>
                    <div>
                      <div className="flex items-center gap-2">
                        <h3 className="text-base font-semibold text-card-foreground">{router.name}</h3>
                        {getStatusBadge(router.status)}
                      </div>
                      <div className="flex items-center gap-3 mt-1 text-sm text-muted-foreground">
                        <span className="flex items-center gap-1 font-mono">
                          <Globe className="w-3 h-3" />
                          {router.ip_address}
                        </span>
                        {router.location && (
                          <span className="flex items-center gap-1">
                            <MapPin className="w-3 h-3" />
                            {router.location}
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    {router.uptime_kuma_url && (
                      <a
                        href={router.uptime_kuma_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="p-2 text-muted-foreground hover:text-primary hover:bg-muted rounded-lg transition-colors"
                        title="View in Uptime Kuma"
                      >
                        <ExternalLink className="w-4 h-4" />
                      </a>
                    )}
                    <button
                      onClick={() => handleDeleteRouter(router.id)}
                      className="p-2 text-muted-foreground hover:text-destructive hover:bg-destructive/10 rounded-lg transition-colors"
                      title="Delete router"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Uptime Kuma Info */}
      <div className="mt-6 bg-blue-500/10 border border-blue-500/20 rounded-lg p-4">
        <div className="flex items-start gap-3">
          <Globe className="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" />
          <div>
            <p className="text-sm font-medium text-blue-500 mb-1">Uptime Kuma Integration</p>
            <p className="text-xs text-muted-foreground">
              For advanced monitoring, connect your routers to Uptime Kuma. Add the Uptime Kuma status page URL 
              when registering a router to enable direct links to detailed monitoring dashboards.
            </p>
          </div>
        </div>
      </div>

      {/* Add Router Modal */}
      {showAddModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-card border border-border rounded-lg w-full max-w-md">
            <div className="p-6 border-b border-border">
              <h2 className="text-xl font-semibold text-card-foreground flex items-center gap-2">
                <Server className="w-5 h-5 text-primary" />
                Add Router
              </h2>
              <p className="text-sm text-muted-foreground mt-1">
                Register a new router for monitoring
              </p>
            </div>
            <div className="p-6 space-y-4">
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Router Name *
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g., Main Office Router"
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary text-foreground"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  IP Address *
                </label>
                <input
                  type="text"
                  value={formData.ip_address}
                  onChange={(e) => setFormData({ ...formData, ip_address: e.target.value })}
                  placeholder="e.g., 192.168.1.1"
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary text-foreground font-mono"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Location (Optional)
                </label>
                <input
                  type="text"
                  value={formData.location}
                  onChange={(e) => setFormData({ ...formData, location: e.target.value })}
                  placeholder="e.g., Server Room A"
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary text-foreground"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Uptime Kuma URL (Optional)
                </label>
                <input
                  type="url"
                  value={formData.uptime_kuma_url}
                  onChange={(e) => setFormData({ ...formData, uptime_kuma_url: e.target.value })}
                  placeholder="e.g., https://uptime.example.com/status/router"
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary text-foreground"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  Link to the Uptime Kuma status page for this router
                </p>
              </div>
            </div>
            <div className="p-6 border-t border-border flex gap-3">
              <button
                onClick={() => {
                  setShowAddModal(false);
                  setFormData({ name: '', ip_address: '', location: '', uptime_kuma_url: '' });
                }}
                className="flex-1 px-4 py-2 border border-border text-card-foreground rounded-lg hover:bg-muted transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleAddRouter}
                disabled={!formData.name.trim() || !formData.ip_address.trim() || saving}
                className="flex-1 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors disabled:opacity-50"
              >
                {saving ? 'Adding...' : 'Add Router'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
