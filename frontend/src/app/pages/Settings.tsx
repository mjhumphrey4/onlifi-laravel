import { useState, useEffect } from 'react';
import { Download, Server, Copy, Check, Settings as SettingsIcon, RefreshCw, Building2 } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { TwoFactorPanel } from '../components/TwoFactorPanel';

const API_BASE = import.meta.env.VITE_API_URL || 'https://api.onlifi.com/api';

interface Site {
  id: number;
  name: string;
  slug: string;
  api_token?: string;
}

export function Settings() {
  const { user } = useAuth();
  const [copied, setCopied] = useState<string | null>(null);
  const [routers, setRouters] = useState<Array<{id: number; name: string; site_id?: number}>>([]);
  const [selectedRouter, setSelectedRouter] = useState<string>('');
  const [sites, setSites] = useState<Site[]>([]);
  const [selectedSite, setSelectedSite] = useState<Site | null>(null);
  const [siteToken, setSiteToken] = useState<string>('');
  const [loadingToken, setLoadingToken] = useState(false);
  
  // Admin configurable telemetry URL
  const [telemetryUrl, setTelemetryUrl] = useState(() => `${API_BASE}/telemetry`);
  const [showUrlConfig, setShowUrlConfig] = useState(false);

  useEffect(() => {
    loadSites();
    loadRouters();
  }, []);

  useEffect(() => {
    if (selectedSite) {
      // Use the token from the site object if available, otherwise fetch it
      if (selectedSite.api_token) {
        setSiteToken(selectedSite.api_token);
      } else {
        loadSiteToken(selectedSite.id);
      }
    }
  }, [selectedSite]);

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return headers;
  };

  const loadSites = async () => {
    try {
      const response = await fetch(`${API_BASE}/sites`, { headers: getAuthHeaders() });
      if (response.ok) {
        const data = await response.json();
        const siteList = data.sites || [];
        setSites(siteList);
        if (siteList.length > 0 && !selectedSite) {
          setSelectedSite(siteList[0]);
        }
      }
    } catch (error) {
      console.error('Failed to load sites:', error);
    }
  };

  const loadSiteToken = async (siteId: number) => {
    try {
      setLoadingToken(true);
      const response = await fetch(`${API_BASE}/sites/${siteId}/token`, { headers: getAuthHeaders() });
      if (response.ok) {
        const data = await response.json();
        setSiteToken(data.api_token || '');
      }
    } catch (error) {
      console.error('Failed to load site token:', error);
    } finally {
      setLoadingToken(false);
    }
  };

  const regenerateSiteToken = async () => {
    if (!selectedSite) return;
    try {
      setLoadingToken(true);
      const response = await fetch(`${API_BASE}/sites/${selectedSite.id}/regenerate-token`, {
        method: 'POST',
        headers: getAuthHeaders(),
      });
      if (response.ok) {
        const data = await response.json();
        setSiteToken(data.api_token || '');
        // Update the selected site object with new token
        setSelectedSite({ ...selectedSite, api_token: data.api_token });
        // Reload sites list to update all site objects
        loadSites();
      }
    } catch (error) {
      console.error('Failed to regenerate token:', error);
    } finally {
      setLoadingToken(false);
    }
  };

  const loadRouters = async () => {
    try {
      const headers = getAuthHeaders();
      const response = await fetch(`${API_BASE}/routers`, { headers });
      
      if (response.ok) {
        const data = await response.json();
        const routerList = data.routers || data.data || data || [];
        setRouters(routerList);
        if (routerList.length > 0) {
          setSelectedRouter(routerList[0].name);
        }
      }
    } catch (error) {
      console.error('Failed to load routers:', error);
    }
  };

  const copyToClipboard = async (text: string, field: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(field);
      setTimeout(() => setCopied(null), 2000);
    } catch (err) {
      console.error('Failed to copy:', err);
    }
  };

  const telemetryScript = `# ============================================
# Onlifi Router Telemetry Script (RouterOS)
# ============================================
# Site: ${selectedSite?.name || 'Default'}
# Router: ${selectedRouter || 'YOUR_ROUTER'}
# Generated: ${new Date().toISOString()}

#---------- CONFIGURATION ----------
:local dashboardUrl "${telemetryUrl}"
:local fetchMode "${telemetryUrl.startsWith('https://') ? 'https' : 'http'}"
:local routerName "${selectedRouter || '[system identity get name]'}"
:local apiToken "${siteToken || 'TOKEN_NOT_LOADED'}"
:local siteSlug "${selectedSite?.slug || 'default'}"
:local schedulerName "onlifi-telemetry-${selectedSite?.slug || 'default'}"

#---------- TELEMETRY COLLECTION FUNCTIONS ----------

:global getSystemStats do={
  :local stats {"cpu"=0; "memory_total"=0; "memory_free"=0; "uptime"="0s";}
  :do {
    :set (\$stats->"cpu") [/system resource get cpu-load]
    :set (\$stats->"memory_total") [/system resource get total-memory]
    :set (\$stats->"memory_free") [/system resource get free-memory]
    :set (\$stats->"uptime") [/system resource get uptime]
  } on-error={}
  :return \$stats
}

:global getInterfaceStats do={
  :local totalTxBytes 0
  :local totalRxBytes 0
  :do {
    :foreach interface in=[/interface find] do={
      :local running false
      :do { :set running [/interface get \$interface running] } on-error={}
      :if (\$running = true) do={
        :local txBytes 0
        :local rxBytes 0
        :do {
          :set txBytes [/interface get \$interface tx-byte]
          :set rxBytes [/interface get \$interface rx-byte]
        } on-error={}
        :set totalTxBytes (\$totalTxBytes + \$txBytes)
        :set totalRxBytes (\$totalRxBytes + \$rxBytes)
      }
    }
  } on-error={}
  :return {"total_tx_bytes"=\$totalTxBytes; "total_rx_bytes"=\$totalRxBytes;}
}

:global getHotspotStats do={
  :local activeUsers 0
  :do { :set activeUsers [/ip hotspot active print count-only] } on-error={}
  :return \$activeUsers
}

:global uptimeToSeconds do={
  :local uptime \$1
  :local seconds 0
  :do {
    :local str [:tostr \$uptime]
    :local weeks 0
    :local days 0
    :local hours 0
    :local minutes 0
    :local secs 0
    :if ([:find \$str "w"] >= 0) do={
      :set weeks [:pick \$str 0 [:find \$str "w"]]
      :set str [:pick \$str ([:find \$str "w"] + 1) [:len \$str]]
    }
    :if ([:find \$str "d"] >= 0) do={
      :set days [:pick \$str 0 [:find \$str "d"]]
      :set str [:pick \$str ([:find \$str "d"] + 1) [:len \$str]]
    }
    :local colonPos1 [:find \$str ":"]
    :if (\$colonPos1 >= 0) do={
      :set hours [:pick \$str 0 \$colonPos1]
      :local remaining [:pick \$str (\$colonPos1 + 1) [:len \$str]]
      :local colonPos2 [:find \$remaining ":"]
      :if (\$colonPos2 >= 0) do={
        :set minutes [:pick \$remaining 0 \$colonPos2]
        :set secs [:pick \$remaining (\$colonPos2 + 1) [:len \$remaining]]
      }
    }
    :set seconds ([:tonum \$weeks] * 604800 + [:tonum \$days] * 86400 + [:tonum \$hours] * 3600 + [:tonum \$minutes] * 60 + [:tonum \$secs])
  } on-error={}
  :return \$seconds
}

#---------- MAIN TELEMETRY JOB ----------
:do {
  :put "Onlifi: Starting telemetry collection..."
  :local sysStats [\$getSystemStats]
  :local interfaceData [\$getInterfaceStats]
  :local hotspotUsers [\$getHotspotStats]
  
  :local routerIdentity [/system identity get name]
  :local routerVersion [/system resource get version]
  :local routerBoard [/system resource get board-name]
  :local currentTime [/system clock get time]
  :local currentDate [/system clock get date]
  :local timestamp (\$currentDate . " " . \$currentTime)
  
  :local cpuVal (\$sysStats->"cpu")
  :local memTotal (\$sysStats->"memory_total")
  :local memFree (\$sysStats->"memory_free")
  :local memUsed (\$memTotal - \$memFree)
  :local rawUptime (\$sysStats->"uptime")
  :local uptimeSeconds [\$uptimeToSeconds \$rawUptime]
  :local totalTxBytes (\$interfaceData->"total_tx_bytes")
  :local totalRxBytes (\$interfaceData->"total_rx_bytes")
  
  :if ([:typeof \$cpuVal] != "num") do={ :set cpuVal 0 }
  :if ([:typeof \$memTotal] != "num") do={ :set memTotal 0 }
  :if ([:typeof \$memFree] != "num") do={ :set memFree 0 }
  :if ([:typeof \$memUsed] != "num") do={ :set memUsed 0 }
  :if ([:typeof \$uptimeSeconds] != "num") do={ :set uptimeSeconds 0 }
  :if ([:typeof \$hotspotUsers] != "num") do={ :set hotspotUsers 0 }
  :if ([:typeof \$totalTxBytes] != "num") do={ :set totalTxBytes 0 }
  :if ([:typeof \$totalRxBytes] != "num") do={ :set totalRxBytes 0 }
  
  # Dashboard calculates bandwidth rate from byte-counter deltas between samples
  :local bandwidthDownKbps 0
  :local bandwidthUpKbps 0
  
  :local reportJson "{"
  :set reportJson (\$reportJson . "\\"site_slug\\":\\"" . \$siteSlug . "\\",")
  :set reportJson (\$reportJson . "\\"router_name\\":\\"" . \$routerIdentity . "\\",")
  :set reportJson (\$reportJson . "\\"router_identity\\":\\"" . \$routerIdentity . "\\",")
  :set reportJson (\$reportJson . "\\"router_version\\":\\"" . \$routerVersion . "\\",")
  :set reportJson (\$reportJson . "\\"router_board\\":\\"" . \$routerBoard . "\\",")
  :set reportJson (\$reportJson . "\\"timestamp\\":\\"" . \$timestamp . "\\",")
  :set reportJson (\$reportJson . "\\"cpu_load\\":" . \$cpuVal . ",")
  :set reportJson (\$reportJson . "\\"memory_total_mb\\":" . (\$memTotal / 1048576) . ",")
  :set reportJson (\$reportJson . "\\"memory_used_mb\\":" . (\$memUsed / 1048576) . ",")
  :set reportJson (\$reportJson . "\\"uptime_seconds\\":" . \$uptimeSeconds . ",")
  :set reportJson (\$reportJson . "\\"active_connections\\":" . \$hotspotUsers . ",")
  :set reportJson (\$reportJson . "\\"bandwidth_download_kbps\\":" . \$bandwidthDownKbps . ",")
  :set reportJson (\$reportJson . "\\"bandwidth_upload_kbps\\":" . \$bandwidthUpKbps . ",")
  :set reportJson (\$reportJson . "\\"total_tx_bytes\\":" . \$totalTxBytes . ",")
  :set reportJson (\$reportJson . "\\"total_rx_bytes\\":" . \$totalRxBytes)
  :set reportJson (\$reportJson . "}")
  
  :put ("Onlifi: Router: " . \$routerIdentity)
  :put ("Onlifi: CPU: " . \$cpuVal . "%")
  :put ("Onlifi: Users: " . \$hotspotUsers)
  
  # FIX: Build headers as a variable so \$apiToken is expanded correctly
  :local authHeader ("Authorization: Bearer " . \$apiToken)
  :local headers (\$authHeader . ",Content-Type: application/json")
  
  :local fetchError ""
  :do {
    /tool fetch url=\$dashboardUrl mode=\$fetchMode http-method=post \\
      http-data=\$reportJson \\
      http-header-field=\$headers \\
      keep-result=no
    :log info "onlifi-telemetry: data posted successfully"
    :put "SUCCESS: Telemetry posted to dashboard"
  } on-error={
    :set fetchError \$1
    :log warning ("onlifi-telemetry: FAILED to post - " . \$fetchError)
    :put ("FAILED: " . \$fetchError)
  }
  :log info ("onlifi-telemetry: CPU=" . \$cpuVal . "% Users=" . \$hotspotUsers . " Identity=" . \$routerIdentity)
} on-error={
  :local collectionError \$1
  :log warning ("onlifi-telemetry: collection failed - " . \$collectionError)
  :put ("FAILED: Telemetry collection aborted - " . \$collectionError)
}

#---------- SCHEDULER SETUP ----------
:if ([:len [/system scheduler find name=\$schedulerName]] = 0) do={
  /system scheduler add name=\$schedulerName start-time=startup interval=30s on-event="/system script run onlifi-telemetry"
  :log info "onlifi-telemetry: scheduler created"
  :put "Scheduler created: runs every 30 seconds"
} else={
  :put "Scheduler already exists"
}
`;

  const handleDownload = () => {
    const blob = new Blob([telemetryScript], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `onlifi-telemetry-${selectedSite?.slug || 'default'}.rsc`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  const handleCopy = async () => {
    await copyToClipboard(telemetryScript, 'script');
  };

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-foreground">Settings</h1>
        <p className="text-sm text-muted-foreground mt-1">
          Configure your system and download router scripts
        </p>
      </div>

      <div className="mb-6">
        <TwoFactorPanel endpointPrefix="/tenant" />
      </div>

      {/* Telemetry URL Configuration (Admin) */}
      <div className="bg-card border border-border rounded-lg p-6 mb-6">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-orange-500/10 rounded-lg">
              <SettingsIcon className="w-6 h-6 text-orange-500" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-card-foreground">Telemetry Configuration</h2>
              <p className="text-sm text-muted-foreground">
                Configure the telemetry endpoint URL
              </p>
            </div>
          </div>
          <button
            onClick={() => setShowUrlConfig(!showUrlConfig)}
            className="px-3 py-1.5 text-sm bg-muted hover:bg-muted/80 rounded-lg transition-colors"
          >
            {showUrlConfig ? 'Hide' : 'Configure'}
          </button>
        </div>

        {showUrlConfig && (
          <div className="space-y-4 pt-4 border-t border-border">
            <div>
              <label className="block text-sm font-medium text-card-foreground mb-2">
                Telemetry API Endpoint
              </label>
              <input
                type="url"
                value={telemetryUrl}
                onChange={(e) => setTelemetryUrl(e.target.value)}
                placeholder="https://api.onlifi.com/api/telemetry"
                className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
              />
              <p className="text-xs text-muted-foreground mt-1">
                This URL will be used in all generated telemetry scripts
              </p>
            </div>
          </div>
        )}

        <div className="mt-4 p-3 bg-muted/50 rounded-lg">
          <p className="text-sm text-muted-foreground">
            <strong>Current URL:</strong> <code className="bg-background px-2 py-0.5 rounded">{telemetryUrl}</code>
          </p>
        </div>
      </div>

      {/* Link Router Section */}
      <div className="bg-card border border-border rounded-lg p-6">
        <div className="flex items-center gap-3 mb-4">
          <div className="p-3 bg-primary/10 rounded-lg">
            <Server className="w-6 h-6 text-primary" />
          </div>
          <div>
            <h2 className="text-lg font-semibold text-card-foreground">Link Router</h2>
            <p className="text-sm text-muted-foreground">
              Download the telemetry push script for your MikroTik router
            </p>
          </div>
        </div>

        <div className="space-y-4">
          {/* Site Selection */}
          <div className="bg-indigo-500/10 border border-indigo-500/20 rounded-lg p-4">
            <div className="flex items-center gap-2 mb-2">
              <Building2 className="w-4 h-4 text-indigo-600" />
              <h3 className="font-medium text-indigo-600">Select Site</h3>
            </div>
            {sites.length === 0 ? (
              <p className="text-sm text-indigo-600/80">No sites created yet. Create a site first to generate telemetry scripts.</p>
            ) : (
              <>
                <select
                  value={selectedSite?.id || ''}
                  onChange={(e) => {
                    const site = sites.find(s => s.id === Number(e.target.value));
                    setSelectedSite(site || null);
                  }}
                  className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                >
                  {sites.map((site) => (
                    <option key={site.id} value={site.id}>
                      {site.name}
                    </option>
                  ))}
                </select>
                <p className="text-xs text-indigo-600/80 mt-2">
                  Each site has its own unique API token for telemetry isolation
                </p>
              </>
            )}
          </div>

          {/* Site API Token */}
          {selectedSite && (
            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-lg p-4">
              <div className="flex items-center justify-between mb-2">
                <h3 className="font-medium text-emerald-600">Site API Token</h3>
                <button
                  onClick={regenerateSiteToken}
                  disabled={loadingToken}
                  className="flex items-center gap-1 px-2 py-1 text-xs bg-emerald-600 text-white rounded hover:bg-emerald-700 transition-colors disabled:opacity-50"
                >
                  <RefreshCw className={`w-3 h-3 ${loadingToken ? 'animate-spin' : ''}`} />
                  Regenerate
                </button>
              </div>
              <div className="flex items-center gap-2">
                <code className="flex-1 px-3 py-2 bg-background border border-border rounded text-xs font-mono truncate">
                  {loadingToken ? 'Loading...' : siteToken || 'No token generated'}
                </code>
                <button
                  onClick={() => copyToClipboard(siteToken, 'token')}
                  className="p-2 hover:bg-emerald-500/20 rounded transition-colors"
                  title="Copy token"
                >
                  {copied === 'token' ? <Check className="w-4 h-4 text-emerald-600" /> : <Copy className="w-4 h-4 text-emerald-600" />}
                </button>
              </div>
              <p className="text-xs text-emerald-600/80 mt-2">
                This token is auto-included in the script. Regenerating will invalidate the old token.
              </p>
            </div>
          )}

          {/* Router Selection */}
          {routers.length > 0 && (
            <div className="bg-blue-500/10 border border-blue-500/20 rounded-lg p-4">
              <h3 className="font-medium text-blue-600 mb-2">Select Router</h3>
              <select
                value={selectedRouter}
                onChange={(e) => setSelectedRouter(e.target.value)}
                className="w-full px-3 py-2 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
              >
                {routers.map((router) => (
                  <option key={router.id} value={router.name}>
                    {router.name}
                  </option>
                ))}
              </select>
              <p className="text-xs text-blue-600/80 mt-2">
                The script below is pre-configured for this router. Just copy and paste!
              </p>
            </div>
          )}

          {/* Instructions */}
          <div className="bg-muted/50 border border-border rounded-lg p-4">
            <h3 className="font-medium text-card-foreground mb-2">Setup Instructions</h3>
            <ol className="list-decimal list-inside space-y-2 text-sm text-muted-foreground">
              <li>Copy the entire RouterOS script below (already configured with your API token)</li>
              <li>Open MikroTik Terminal (Winbox or SSH)</li>
              <li>Paste and run the script directly in the terminal</li>
              <li>The script will automatically:
                <ul className="list-disc list-inside ml-6 mt-1 space-y-1">
                  <li>Create the telemetry collection script</li>
                  <li>Set up a scheduler to run every 30 seconds</li>
                  <li>Start sending data to your dashboard</li>
                </ul>
              </li>
              <li>Verify it's working: <code className="bg-background px-1 py-0.5 rounded">/log print where topics~"info"</code></li>
            </ol>
          </div>

          {/* Script Preview */}
          <div className="bg-background border border-border rounded-lg overflow-hidden">
            <div className="flex items-center justify-between px-4 py-2 bg-muted border-b border-border">
              <span className="text-sm font-medium text-card-foreground">onlifi-telemetry.rsc</span>
              <div className="flex gap-2">
                <button
                  onClick={handleCopy}
                  disabled={!selectedSite || !siteToken}
                  className="flex items-center gap-2 px-3 py-1.5 text-sm bg-background border border-border rounded-lg hover:bg-muted transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {copied === 'script' ? (
                    <>
                      <Check className="w-4 h-4 text-green-600" />
                      <span className="text-green-600">Copied!</span>
                    </>
                  ) : (
                    <>
                      <Copy className="w-4 h-4" />
                      Copy
                    </>
                  )}
                </button>
                <button
                  onClick={handleDownload}
                  disabled={!selectedSite || !siteToken}
                  className="flex items-center gap-2 px-3 py-1.5 text-sm bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <Download className="w-4 h-4" />
                  Download
                </button>
              </div>
            </div>
            <pre className="p-4 text-xs overflow-x-auto max-h-96 overflow-y-auto">
              <code className="text-muted-foreground">{telemetryScript}</code>
            </pre>
          </div>

          {/* Additional Info */}
          <div className="bg-green-500/10 border border-green-500/20 rounded-lg p-4">
            <h3 className="font-medium text-green-600 mb-2">✅ Ready to Use</h3>
            <ul className="list-disc list-inside space-y-1 text-sm text-green-600/80">
              <li>Script is pre-configured with your unique API token</li>
              <li>Router identity is set to: <code className="bg-green-500/10 px-1 py-0.5 rounded font-semibold">{selectedRouter || 'Select a router above'}</code></li>
              <li>No manual editing required - just copy and paste!</li>
              <li>Data will appear on your dashboard within 30 seconds</li>
              <li>Collects: CPU, memory, uptime, active clients, network bandwidth</li>
            </ul>
          </div>

          {/* Test Connection */}
          <div className="flex items-center justify-between p-4 bg-muted/50 border border-border rounded-lg">
            <div>
              <h3 className="font-medium text-card-foreground">Test Connection</h3>
              <p className="text-sm text-muted-foreground">
                After setup, verify the router is sending data to the dashboard
              </p>
            </div>
            <button className="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
              View Router Stats
            </button>
          </div>
        </div>
      </div>

      {/* Future Settings Sections */}
      <div className="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="bg-card border border-border rounded-lg p-6 opacity-50">
          <h3 className="font-semibold text-card-foreground mb-2">API Keys</h3>
          <p className="text-sm text-muted-foreground">Manage your API keys and access tokens</p>
          <button disabled className="mt-4 px-4 py-2 bg-muted text-muted-foreground rounded-lg cursor-not-allowed">
            Coming Soon
          </button>
        </div>

        <div className="bg-card border border-border rounded-lg p-6 opacity-50">
          <h3 className="font-semibold text-card-foreground mb-2">Notifications</h3>
          <p className="text-sm text-muted-foreground">Configure email and SMS notifications</p>
          <button disabled className="mt-4 px-4 py-2 bg-muted text-muted-foreground rounded-lg cursor-not-allowed">
            Coming Soon
          </button>
        </div>
      </div>
    </div>
  );
}
