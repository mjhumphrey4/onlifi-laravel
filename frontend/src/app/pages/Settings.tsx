import { useState, useEffect } from 'react';
import { Download, Server, Copy, Check } from 'lucide-react';
import { useAuth } from '../context/AuthContext';

export function Settings() {
  const { user } = useAuth();
  const [copied, setCopied] = useState(false);
  const [apiToken, setApiToken] = useState('');
  const [routers, setRouters] = useState<Array<{id: number; name: string}>>([]);
  const [selectedRouter, setSelectedRouter] = useState<string>('');

  useEffect(() => {
    loadRouters();
    generateApiToken();
  }, []);

  const loadRouters = async () => {
    try {
      const response = await fetch('/api/mikrotik_api.php?action=routers');
      const data = await response.json();
      if (data.routers && data.routers.length > 0) {
        setRouters(data.routers);
        setSelectedRouter(data.routers[0].name);
      }
    } catch (error) {
      console.error('Failed to load routers:', error);
    }
  };

  const generateApiToken = () => {
    // Generate a unique token based on user ID and timestamp
    const token = btoa(`${user?.id}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`);
    setApiToken(token);
  };

  const telemetryScript = `# ============================================
# Onlifi Router Telemetry Script (RouterOS)
# ============================================
# Pre-configured for: ${selectedRouter || 'YOUR_ROUTER'}
# API Token: ${apiToken || 'GENERATING...'}

#---------- CONFIGURATION ----------
:local dashboardUrl "http://192.168.0.180/api/telemetry_ingest.php"
:local apiToken "${apiToken}"
:local schedulerName "onlifi-telemetry-scheduler"

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
  
  :local bandwidthDownKbps 0
  :local bandwidthUpKbps 0
  :if (\$totalRxBytes > 0) do={ :set bandwidthDownKbps ((\$totalRxBytes * 8) / (300 * 1024)) }
  :if (\$totalTxBytes > 0) do={ :set bandwidthUpKbps ((\$totalTxBytes * 8) / (300 * 1024)) }
  
  :local reportJson "{"
  :set reportJson (\$reportJson . "\\"router_identity\\":\\"" . \$routerIdentity . "\\",")
  :set reportJson (\$reportJson . "\\"router_version\\":\\"" . \$routerVersion . "\\",")
  :set reportJson (\$reportJson . "\\"router_board\\":\\"" . \$routerBoard . "\\",")
  :set reportJson (\$reportJson . "\\"timestamp\\":\\"" . \$timestamp . "\\",")
  :set reportJson (\$reportJson . "\\"cpu_load\\":" . \$cpuVal . ",")
  :set reportJson (\$reportJson . "\\"memory_total_mb\\":" . (\$memTotal / 1048576) . ",")
  :set reportJson (\$reportJson . "\\"memory_used_mb\\":" . (\$memUsed / 1048576) . ",")
  :set reportJson (\$reportJson . "\\"uptime_seconds\\":" . \$uptimeSeconds . ",")
  :set reportJson (\$reportJson . "\\"active_clients\\":" . \$hotspotUsers . ",")
  :set reportJson (\$reportJson . "\\"bandwidth_download_kbps\\":" . \$bandwidthDownKbps . ",")
  :set reportJson (\$reportJson . "\\"bandwidth_upload_kbps\\":" . \$bandwidthUpKbps . ",")
  :set reportJson (\$reportJson . "\\"total_tx_bytes\\":" . \$totalTxBytes . ",")
  :set reportJson (\$reportJson . "\\"total_rx_bytes\\":" . \$totalRxBytes)
  :set reportJson (\$reportJson . "}")
  
  :put ("Onlifi: Router: " . \$routerIdentity)
  :put ("Onlifi: CPU: " . \$cpuVal . "%")
  :put ("Onlifi: Users: " . \$hotspotUsers)
  
  :do {
    /tool fetch url=\$dashboardUrl mode=http http-method=post http-data=\$reportJson http-header-field="Authorization: Bearer \$apiToken,Content-Type: application/json" keep-result=no
    :log info "onlifi-telemetry: data posted successfully"
    :put "SUCCESS: Telemetry posted to dashboard"
  } on-error={
    :log warning "onlifi-telemetry: failed to post data"
    :put "FAILED: Could not post telemetry data"
  }
  :log info ("onlifi-telemetry: CPU=" . \$cpuVal . "% Users=" . \$hotspotUsers . " Identity=" . \$routerIdentity)
} on-error={
  :log warning "onlifi-telemetry: collection failed"
  :put "FAILED: Telemetry collection aborted"
}

#---------- SCHEDULER SETUP ----------
:if ([:len [/system scheduler find name=\$schedulerName]] = 0) do={
  /system scheduler add name=\$schedulerName start-time=startup interval=5m on-event="/system script run onlifi-telemetry"
  :log info "onlifi-telemetry: scheduler created"
  :put "Scheduler created: runs every 5 minutes"
} else={
  :put "Scheduler already exists"
}
`;

  const handleDownload = () => {
    const blob = new Blob([telemetryScript], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'onlifi-telemetry.rsc';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(telemetryScript);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      console.error('Failed to copy:', err);
    }
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
                  <li>Set up a scheduler to run every 5 minutes</li>
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
                  className="flex items-center gap-2 px-3 py-1.5 text-sm bg-background border border-border rounded-lg hover:bg-muted transition-colors"
                >
                  {copied ? (
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
                  className="flex items-center gap-2 px-3 py-1.5 text-sm bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
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
              <li>Data will appear on your dashboard within 5 minutes</li>
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
