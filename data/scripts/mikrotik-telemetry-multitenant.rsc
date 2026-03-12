# ============================================
# OnLiFi Multi-Tenant Router Telemetry Script
# ============================================
# CRITICAL: This script includes tenant API authentication
# Each router must be configured with tenant-specific credentials

#---------- CONFIGURATION (MUST BE SET PER TENANT) ----------
:local dashboardUrl "https://yourdomain.com/api/routers/telemetry/ingest"
:local tenantApiKey "YOUR_TENANT_API_KEY_HERE"
:local tenantApiSecret "YOUR_TENANT_API_SECRET_HERE"
:local routerId "1"
:local schedulerName "onlifi-telemetry-scheduler"

#---------- TELEMETRY COLLECTION FUNCTIONS ----------

:global getSystemStats do={
  :local stats {"cpu"=0; "memory_total"=0; "memory_free"=0; "uptime"="0s";}
  
  :do {
    :set ($stats->"cpu") [/system resource get cpu-load]
    :set ($stats->"memory_total") [/system resource get total-memory]
    :set ($stats->"memory_free") [/system resource get free-memory]
    :set ($stats->"uptime") [/system resource get uptime]
  } on-error={}
  
  :return $stats
}

:global getInterfaceStats do={
  :local totalTxBytes 0
  :local totalRxBytes 0
  
  :do {
    :foreach interface in=[/interface find] do={
      :local running false
      :do {
        :set running [/interface get $interface running]
      } on-error={}
      
      :if ($running = true) do={
        :local txBytes 0
        :local rxBytes 0
        
        :do {
          :set txBytes [/interface get $interface tx-byte]
          :set rxBytes [/interface get $interface rx-byte]
        } on-error={}
        
        :set totalTxBytes ($totalTxBytes + $txBytes)
        :set totalRxBytes ($totalRxBytes + $rxBytes)
      }
    }
  } on-error={}
  
  :return {"total_tx_bytes"=$totalTxBytes; "total_rx_bytes"=$totalRxBytes;}
}

:global getHotspotStats do={
  :local activeUsers 0
  :do {
    :set activeUsers [/ip hotspot active print count-only]
  } on-error={}
  :return $activeUsers
}

:global uptimeToSeconds do={
  :local uptime $1
  :local seconds 0
  
  :do {
    :local str [:tostr $uptime]
    :local weeks 0
    :local days 0
    :local hours 0
    :local minutes 0
    :local secs 0
    
    :if ([:find $str "w"] >= 0) do={
      :set weeks [:pick $str 0 [:find $str "w"]]
      :set str [:pick $str ([:find $str "w"] + 1) [:len $str]]
    }
    
    :if ([:find $str "d"] >= 0) do={
      :set days [:pick $str 0 [:find $str "d"]]
      :set str [:pick $str ([:find $str "d"] + 1) [:len $str]]
    }
    
    :local colonPos1 [:find $str ":"]
    :if ($colonPos1 >= 0) do={
      :set hours [:pick $str 0 $colonPos1]
      :local remaining [:pick $str ($colonPos1 + 1) [:len $str]]
      :local colonPos2 [:find $remaining ":"]
      :if ($colonPos2 >= 0) do={
        :set minutes [:pick $remaining 0 $colonPos2]
        :set secs [:pick $remaining ($colonPos2 + 1) [:len $remaining]]
      }
    }
    
    :set seconds ([:tonum $weeks] * 604800 + [:tonum $days] * 86400 + [:tonum $hours] * 3600 + [:tonum $minutes] * 60 + [:tonum $secs])
  } on-error={}
  
  :return $seconds
}

#---------- MAIN TELEMETRY JOB ----------
:do {
  :put "OnLiFi Multi-Tenant: Starting telemetry collection..."
  
  :local sysStats [$getSystemStats]
  :local interfaceData [$getInterfaceStats]
  :local hotspotUsers [$getHotspotStats]
  
  :local routerIdentity [/system identity get name]
  :local routerVersion [/system resource get version]
  :local routerBoard [/system resource get board-name]
  
  :local cpuVal ($sysStats->"cpu")
  :local memTotal ($sysStats->"memory_total")
  :local memFree ($sysStats->"memory_free")
  :local memUsed ($memTotal - $memFree)
  :local rawUptime ($sysStats->"uptime")
  :local uptimeSeconds [$uptimeToSeconds $rawUptime]
  :local totalTxBytes ($interfaceData->"total_tx_bytes")
  :local totalRxBytes ($interfaceData->"total_rx_bytes")
  
  :if ([:typeof $cpuVal] != "num") do={ :set cpuVal 0 }
  :if ([:typeof $memTotal] != "num") do={ :set memTotal 0 }
  :if ([:typeof $memFree] != "num") do={ :set memFree 0 }
  :if ([:typeof $memUsed] != "num") do={ :set memUsed 0 }
  :if ([:typeof $uptimeSeconds] != "num") do={ :set uptimeSeconds 0 }
  :if ([:typeof $hotspotUsers] != "num") do={ :set hotspotUsers 0 }
  :if ([:typeof $totalTxBytes] != "num") do={ :set totalTxBytes 0 }
  :if ([:typeof $totalRxBytes] != "num") do={ :set totalRxBytes 0 }
  
  :local memUsedMb ($memUsed / 1048576)
  :local memTotalMb ($memTotal / 1048576)
  
  # Build JSON payload with router_id for tenant routing
  :local reportJson "{"
  :set reportJson ($reportJson . "\"router_id\":" . $routerId . ",")
  :set reportJson ($reportJson . "\"cpu_load\":" . $cpuVal . ",")
  :set reportJson ($reportJson . "\"memory_used_mb\":" . $memUsedMb . ",")
  :set reportJson ($reportJson . "\"memory_total_mb\":" . $memTotalMb . ",")
  :set reportJson ($reportJson . "\"uptime_seconds\":" . $uptimeSeconds . ",")
  :set reportJson ($reportJson . "\"active_connections\":" . $hotspotUsers . ",")
  :set reportJson ($reportJson . "\"total_clients\":" . $hotspotUsers . ",")
  :set reportJson ($reportJson . "\"bandwidth_upload_kbps\":" . (($totalTxBytes * 8) / (300 * 1024)) . ",")
  :set reportJson ($reportJson . "\"bandwidth_download_kbps\":" . (($totalRxBytes * 8) / (300 * 1024)) . "")
  :set reportJson ($reportJson . "}")
  
  :put ("OnLiFi: Router ID: " . $routerId)
  :put ("OnLiFi: CPU: " . $cpuVal . "%")
  :put ("OnLiFi: Memory: " . $memUsedMb . "/" . $memTotalMb . " MB")
  :put ("OnLiFi: Active Users: " . $hotspotUsers)
  :put ("OnLiFi: Tenant API Key: " . [:pick $tenantApiKey 0 15] . "...")
  
  # POST with tenant authentication headers
  :do {
    /tool fetch url=$dashboardUrl mode=https http-method=post http-data=$reportJson \
      http-header-field="X-API-Key: $tenantApiKey,X-API-Secret: $tenantApiSecret,Content-Type: application/json" \
      keep-result=no
    :log info "onlifi-telemetry: data posted successfully for router_id=$routerId"
    :put "SUCCESS: Telemetry posted with tenant authentication"
  } on-error={
    :log warning "onlifi-telemetry: failed to post data for router_id=$routerId"
    :put "FAILED: Could not post telemetry data - check API credentials"
  }
  
  :log info ("onlifi-telemetry: RouterID=" . $routerId . " CPU=" . $cpuVal . "% Users=" . $hotspotUsers)

} on-error={
  :log warning "onlifi-telemetry: collection failed"
  :put "FAILED: Telemetry collection aborted"
}

#---------- SCHEDULER SETUP ----------
:if ([:len [/system scheduler find name=$schedulerName]] = 0) do={
  /system scheduler add name=$schedulerName start-time=startup interval=5m on-event="/system script run onlifi-telemetry"
  :log info "onlifi-telemetry: scheduler created - runs every 5 minutes"
  :put "Scheduler created: runs every 5 minutes"
} else={
  :put "Scheduler already exists"
}
