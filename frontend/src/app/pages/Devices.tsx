import { useState, useEffect } from 'react';
import { Server, Activity, Cpu, HardDrive, Clock, AlertCircle, CheckCircle } from 'lucide-react';

interface Router {
  id: number;
  name: string;
  ip_address: string;
  location: string | null;
  is_active: boolean;
  last_seen: string | null;
}

interface Telemetry {
  id: number;
  router_id: number;
  router_name: string;
  ip_address: string;
  cpu_load: number;
  memory_used_mb: number;
  memory_total_mb: number;
  uptime_seconds: number;
  recorded_at: string;
}

export function Devices() {
  const [routers, setRouters] = useState<Router[]>([]);
  const [telemetry, setTelemetry] = useState<Telemetry[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadData();
    const interval = setInterval(loadData, 60000); // Refresh every minute
    return () => clearInterval(interval);
  }, []);

  const loadData = async () => {
    try {
      const [routersRes, telemetryRes] = await Promise.all([
        fetch('/api/mikrotik_api.php?action=routers'),
        fetch('/api/mikrotik_api.php?action=router_telemetry')
      ]);

      const routersData = await routersRes.json();
      const telemetryData = await telemetryRes.json();

      if (routersData.routers) setRouters(routersData.routers);
      if (telemetryData.telemetry) setTelemetry(telemetryData.telemetry);
    } catch (error) {
      console.error('Failed to load devices:', error);
    } finally {
      setLoading(false);
    }
  };

  const formatUptime = (seconds: number) => {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    
    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
  };

  const getLatestTelemetry = (routerId: number) => {
    return telemetry.find(t => t.router_id === routerId);
  };

  const getStatusColor = (lastSeen: string | null) => {
    if (!lastSeen) return 'text-muted-foreground';
    const diff = Date.now() - new Date(lastSeen).getTime();
    if (diff < 300000) return 'text-emerald-500'; // < 5 minutes
    if (diff < 900000) return 'text-yellow-500'; // < 15 minutes
    return 'text-destructive'; // > 15 minutes
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Activity className="w-6 h-6 text-primary animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      {/* Header */}
      <div className="mb-6 sm:mb-8">
        <h1 className="text-2xl sm:text-3xl text-foreground mb-1 flex items-center gap-2">
          <Server className="w-8 h-8 text-primary" />
          Network Devices
        </h1>
        <p className="text-sm text-muted-foreground">
          Monitor your MikroTik routers and network infrastructure
        </p>
      </div>

      {/* Info Banner */}
      <div className="bg-blue-500/10 border border-blue-500/20 rounded-lg p-4 mb-6">
        <div className="flex items-start gap-3">
          <AlertCircle className="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" />
          <div>
            <p className="text-sm font-medium text-blue-500 mb-1">Uptime Kuma Integration Coming Soon</p>
            <p className="text-xs text-muted-foreground">
              This page will be enhanced with Uptime Kuma integration for comprehensive device monitoring, 
              uptime tracking, and alerting capabilities.
            </p>
          </div>
        </div>
      </div>

      {/* Routers Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {routers.length === 0 ? (
          <div className="col-span-2 bg-card border border-border rounded-lg p-8 text-center">
            <Server className="w-12 h-12 text-muted-foreground mx-auto mb-3" />
            <p className="text-muted-foreground">No routers configured yet</p>
          </div>
        ) : (
          routers.map((router) => {
            const tel = getLatestTelemetry(router.id);
            const statusColor = getStatusColor(router.last_seen);
            const memoryPercent = tel ? (tel.memory_used_mb / tel.memory_total_mb) * 100 : 0;

            return (
              <div key={router.id} className="bg-card border border-border rounded-lg p-6">
                {/* Router Header */}
                <div className="flex items-start justify-between mb-4">
                  <div className="flex items-center gap-3">
                    <div className="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center">
                      <Server className="w-6 h-6 text-primary" />
                    </div>
                    <div>
                      <h3 className="text-lg font-semibold text-card-foreground">{router.name}</h3>
                      <p className="text-sm text-muted-foreground font-mono">{router.ip_address}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    {router.is_active ? (
                      <CheckCircle className={`w-5 h-5 ${statusColor}`} />
                    ) : (
                      <AlertCircle className="w-5 h-5 text-muted-foreground" />
                    )}
                  </div>
                </div>

                {/* Location */}
                {router.location && (
                  <p className="text-sm text-muted-foreground mb-4">📍 {router.location}</p>
                )}

                {/* Telemetry Data */}
                {tel ? (
                  <div className="space-y-4">
                    {/* CPU Load */}
                    <div>
                      <div className="flex items-center justify-between mb-2">
                        <div className="flex items-center gap-2">
                          <Cpu className="w-4 h-4 text-muted-foreground" />
                          <span className="text-sm text-muted-foreground">CPU Load</span>
                        </div>
                        <span className="text-sm font-semibold text-card-foreground">{tel.cpu_load}%</span>
                      </div>
                      <div className="w-full bg-muted rounded-full h-2">
                        <div
                          className={`h-2 rounded-full transition-all ${
                            tel.cpu_load > 80 ? 'bg-destructive' : tel.cpu_load > 60 ? 'bg-yellow-500' : 'bg-primary'
                          }`}
                          style={{ width: `${Math.min(tel.cpu_load, 100)}%` }}
                        />
                      </div>
                    </div>

                    {/* Memory Usage */}
                    <div>
                      <div className="flex items-center justify-between mb-2">
                        <div className="flex items-center gap-2">
                          <HardDrive className="w-4 h-4 text-muted-foreground" />
                          <span className="text-sm text-muted-foreground">Memory</span>
                        </div>
                        <span className="text-sm font-semibold text-card-foreground">
                          {tel.memory_used_mb} / {tel.memory_total_mb} MB
                        </span>
                      </div>
                      <div className="w-full bg-muted rounded-full h-2">
                        <div
                          className={`h-2 rounded-full transition-all ${
                            memoryPercent > 90 ? 'bg-destructive' : memoryPercent > 75 ? 'bg-yellow-500' : 'bg-emerald-500'
                          }`}
                          style={{ width: `${Math.min(memoryPercent, 100)}%` }}
                        />
                      </div>
                    </div>

                    {/* Uptime */}
                    <div className="flex items-center justify-between pt-2 border-t border-border">
                      <div className="flex items-center gap-2">
                        <Clock className="w-4 h-4 text-muted-foreground" />
                        <span className="text-sm text-muted-foreground">Uptime</span>
                      </div>
                      <span className="text-sm font-semibold text-card-foreground">
                        {formatUptime(tel.uptime_seconds)}
                      </span>
                    </div>

                    {/* Last Updated */}
                    <div className="text-xs text-muted-foreground text-right">
                      Updated {new Date(tel.recorded_at).toLocaleString()}
                    </div>
                  </div>
                ) : (
                  <div className="text-center py-4">
                    <Activity className="w-8 h-8 text-muted-foreground mx-auto mb-2" />
                    <p className="text-sm text-muted-foreground">No telemetry data available</p>
                  </div>
                )}
              </div>
            );
          })
        )}
      </div>

      {/* Future Integration Section */}
      <div className="mt-8 bg-card border border-border rounded-lg p-6">
        <h2 className="text-lg font-semibold text-card-foreground mb-4">Planned Features</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="flex items-start gap-3">
            <div className="w-8 h-8 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
              <CheckCircle className="w-4 h-4 text-primary" />
            </div>
            <div>
              <p className="text-sm font-medium text-card-foreground">Uptime Kuma Integration</p>
              <p className="text-xs text-muted-foreground">Real-time uptime monitoring and alerting</p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <div className="w-8 h-8 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
              <Activity className="w-4 h-4 text-primary" />
            </div>
            <div>
              <p className="text-sm font-medium text-card-foreground">Historical Metrics</p>
              <p className="text-xs text-muted-foreground">Track performance trends over time</p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <div className="w-8 h-8 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
              <AlertCircle className="w-4 h-4 text-primary" />
            </div>
            <div>
              <p className="text-sm font-medium text-card-foreground">Alert Configuration</p>
              <p className="text-xs text-muted-foreground">Custom alerts for critical events</p>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <div className="w-8 h-8 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
              <Server className="w-4 h-4 text-primary" />
            </div>
            <div>
              <p className="text-sm font-medium text-card-foreground">Multi-Device Support</p>
              <p className="text-xs text-muted-foreground">Monitor all network infrastructure</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
