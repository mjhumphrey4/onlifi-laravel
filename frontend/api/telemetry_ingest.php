<?php
/**
 * Telemetry Ingestion API
 * Receives telemetry data from MikroTik routers and stores it in the appropriate user database
 * Routes data based on router identity
 */

require_once __DIR__ . '/../../config_multitenant.php';

header('Content-Type: application/json');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail($message, $code = 400) {
    respond(['success' => false, 'error' => $message], $code);
}

// Get JSON payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    fail('Invalid JSON payload', 400);
}

// Validate required fields
$requiredFields = ['router_identity', 'cpu_load', 'memory_total_mb', 'memory_used_mb', 'uptime_seconds'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        fail("Missing required field: $field", 400);
    }
}

// Extract telemetry data
$routerIdentity = trim($data['router_identity']);
$cpuLoad = (float)$data['cpu_load'];
$memoryTotalMB = (int)$data['memory_total_mb'];
$memoryUsedMB = (int)$data['memory_used_mb'];
$uptimeSeconds = (int)$data['uptime_seconds'];
$activeClients = isset($data['active_clients']) ? (int)$data['active_clients'] : 0;
$bandwidthDownloadKbps = isset($data['bandwidth_download_kbps']) ? (float)$data['bandwidth_download_kbps'] : null;
$bandwidthUploadKbps = isset($data['bandwidth_upload_kbps']) ? (float)$data['bandwidth_upload_kbps'] : null;
$routerVersion = isset($data['router_version']) ? trim($data['router_version']) : '';
$routerBoard = isset($data['router_board']) ? trim($data['router_board']) : '';

// Log incoming telemetry for debugging
error_log("Telemetry received from router: $routerIdentity");

// Connect to central database to find which user owns this router
$centralPdo = getCentralDB();
if (!$centralPdo) {
    fail('Central database connection failed', 500);
}

try {
    // Find the router in mikrotik_routers table across all tenant databases
    // First, get all active users and their databases
    $stmt = $centralPdo->query("
        SELECT id, username, database_name 
        FROM users 
        WHERE status = 'approved' AND database_deployed = 1
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $routerFound = false;
    $targetDatabase = null;
    $routerId = null;
    
    // Search for the router in each tenant database
    foreach ($users as $user) {
        $tenantPdo = getTenantDB($user['database_name']);
        if (!$tenantPdo) continue;
        
        try {
            // Look for router by identity (name)
            $stmt = $tenantPdo->prepare("
                SELECT id, name, ip_address 
                FROM mikrotik_routers 
                WHERE name = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$routerIdentity]);
            $router = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($router) {
                $routerFound = true;
                $targetDatabase = $user['database_name'];
                $routerId = $router['id'];
                error_log("Router '$routerIdentity' found in database: {$user['database_name']} (ID: $routerId)");
                break;
            }
        } catch (Exception $e) {
            error_log("Error searching in {$user['database_name']}: " . $e->getMessage());
            continue;
        }
    }
    
    if (!$routerFound) {
        error_log("Router '$routerIdentity' not found in any tenant database");
        fail("Router identity '$routerIdentity' not registered in system. Please add this router to your dashboard first.", 404);
    }
    
    // Connect to the tenant database where the router was found
    $tenantPdo = getTenantDB($targetDatabase);
    if (!$tenantPdo) {
        fail('Tenant database connection failed', 500);
    }
    
    // Store telemetry data using REPLACE INTO (only keeps latest data per router)
    $stmt = $tenantPdo->prepare("
        REPLACE INTO router_telemetry 
        (router_id, cpu_load, memory_used_mb, memory_total_mb, uptime_seconds, 
         active_connections, bandwidth_download_kbps, bandwidth_upload_kbps, recorded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $routerId,
        $cpuLoad,
        $memoryUsedMB,
        $memoryTotalMB,
        $uptimeSeconds,
        $activeClients,
        $bandwidthDownloadKbps,
        $bandwidthUploadKbps
    ]);
    
    // Update router last_seen timestamp
    $stmt = $tenantPdo->prepare("
        UPDATE mikrotik_routers 
        SET last_seen = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$routerId]);
    
    // Log success
    error_log("Telemetry stored successfully for router '$routerIdentity' (ID: $routerId) in database: $targetDatabase");
    
    respond([
        'success' => true,
        'message' => 'Telemetry data received and stored',
        'router_identity' => $routerIdentity,
        'router_id' => $routerId,
        'database' => $targetDatabase,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Telemetry ingestion error: " . $e->getMessage());
    fail('Failed to store telemetry data: ' . $e->getMessage(), 500);
}
