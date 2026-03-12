<?php
/**
 * MikroTik Router Operations API
 * Handles clients, devices, vouchers, and router telemetry
 */

// Prevent any output before JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('Africa/Nairobi');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Catch any errors during file includes
try {
    require_once __DIR__ . '/../../config_multitenant.php';
    require_once __DIR__ . '/../../MikrotikAPI.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error: ' . $e->getMessage()]);
    exit;
}

// Session management
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = isset($_SERVER['HTTPS']) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ONLIFI_SESSION');
    session_start();
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail($msg, $code = 400) {
    respond(['error' => $msg], $code);
}

function requireAuth() {
    if (empty($_SESSION['user_id'])) fail('Unauthorized', 401);
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['role'] ?? 'user',
        'database_name' => $_SESSION['database_name'] ?? ''
    ];
}

// Get database connection - use tenant database for authenticated users
$pdo = null;
if (!empty($_SESSION['user_id']) && !empty($_SESSION['database_name'])) {
    $pdo = getTenantDB($_SESSION['database_name']);
} else {
    // Fallback for backward compatibility
    $pdo = getDB();
}

// Parse request
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    $body = array_merge($body, $_POST);
}

// ============================================================================
// ROUTER MANAGEMENT
// ============================================================================

switch ($action) {
    
    // Get list of configured routers
    case 'routers':
        requireAuth();
        try {
            $stmt = $pdo->query("SELECT id, name, ip_address, location, is_active, last_seen FROM mikrotik_routers ORDER BY name");
            $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            respond(['routers' => $routers]);
        } catch (Exception $e) {
            fail('Failed to fetch routers: ' . $e->getMessage(), 500);
        }
        break;

    // Get active clients from all routers
    case 'clients':
        requireAuth();
        try {
            // Get from database cache (updated periodically)
            $stmt = $pdo->prepare("
                SELECT 
                    ac.*,
                    mr.name as router_name,
                    v.voucher_code,
                    v.profile_name,
                    v.expires_at
                FROM active_clients ac
                LEFT JOIN mikrotik_routers mr ON ac.router_id = mr.id
                LEFT JOIN vouchers v ON ac.username = v.voucher_code AND v.status = 'used'
                WHERE ac.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ORDER BY ac.last_seen DESC
            ");
            $stmt->execute();
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format data transfer
            foreach ($clients as &$client) {
                $client['data_uploaded_mb'] = round($client['data_uploaded_mb'], 2);
                $client['data_downloaded_mb'] = round($client['data_downloaded_mb'], 2);
                $client['total_data_mb'] = round($client['data_uploaded_mb'] + $client['data_downloaded_mb'], 2);
            }
            
            respond(['clients' => $clients, 'count' => count($clients)]);
        } catch (Exception $e) {
            fail('Failed to fetch clients: ' . $e->getMessage(), 500);
        }
        break;

    // Refresh clients from router (real-time)
    case 'clients_refresh':
        requireAuth();
        try {
            $routerId = (int)($_GET['router_id'] ?? 0);
            
            if ($routerId) {
                $stmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND is_active = 1");
                $stmt->execute([$routerId]);
                $routers = [$stmt->fetch(PDO::FETCH_ASSOC)];
            } else {
                $stmt = $pdo->query("SELECT * FROM mikrotik_routers WHERE is_active = 1");
                $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $allClients = [];
            foreach ($routers as $router) {
                if (!$router) continue;
                
                $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
                
                if ($api->connect()) {
                    $hotspotUsers = $api->getHotspotUsers();
                    
                    // Add router info to each client for identification
                    foreach ($hotspotUsers as &$user) {
                        $user['router_id'] = $router['id'];
                        $user['router_name'] = $router['name'];
                        $user['router_ip'] = $router['ip_address'];
                    }
                    
                    $allClients = array_merge($allClients, $hotspotUsers);
                    
                    // Update router last_seen
                    $stmt = $pdo->prepare("UPDATE mikrotik_routers SET last_seen = NOW() WHERE id = ?");
                    $stmt->execute([$router['id']]);
                }
            }
            
            respond(['clients' => $allClients, 'count' => count($allClients), 'refreshed' => true]);
        } catch (Exception $e) {
            fail('Failed to refresh clients: ' . $e->getMessage(), 500);
        }
        break;

    // Get router telemetry
    case 'router_telemetry':
        requireAuth();
        try {
            $routerId = (int)($_GET['router_id'] ?? 0);
            
            if ($routerId) {
                $stmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND is_active = 1");
                $stmt->execute([$routerId]);
                $router = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$router) {
                    fail('Router not found', 404);
                }
                
                $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
                
                if ($api->connect()) {
                    $resources = $api->getSystemResources();
                    
                    if ($resources) {
                        // Save to database (REPLACE for latest data only)
                        $memUsed = $resources['total_memory'] - $resources['free_memory'];
                        $memTotal = $resources['total_memory'];
                        
                        $stmt = $pdo->prepare("
                            REPLACE INTO router_telemetry 
                            (router_id, cpu_load, memory_used_mb, memory_total_mb, uptime_seconds, recorded_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $routerId,
                            $resources['cpu_load'],
                            round($memUsed / (1024 * 1024)),
                            round($memTotal / (1024 * 1024)),
                            $this->parseUptime($resources['uptime'])
                        ]);
                        
                        respond(['telemetry' => $resources]);
                    }
                }
                
                fail('Failed to connect to router', 500);
            } else {
                // Get latest telemetry for all routers (only latest per router)
                $stmt = $pdo->query("
                    SELECT rt.*, mr.name as router_name, mr.ip_address
                    FROM router_telemetry rt
                    INNER JOIN mikrotik_routers mr ON rt.router_id = mr.id
                    ORDER BY mr.name
                ");
                $telemetry = $stmt->fetchAll(PDO::FETCH_ASSOC);
                respond(['telemetry' => $telemetry]);
            }
        } catch (Exception $e) {
            fail('Failed to fetch telemetry: ' . $e->getMessage(), 500);
        }
        break;

    // ========================================================================
    // VOUCHER MANAGEMENT
    // ========================================================================

    // Get voucher groups
    case 'voucher_groups':
        requireAuth();
        try {
            $stmt = $pdo->query("
                SELECT 
                    vg.*,
                    vsp.name as sales_point_name,
                    COUNT(v.id) as total_vouchers,
                    SUM(CASE WHEN v.status = 'unused' THEN 1 ELSE 0 END) as unused_count,
                    SUM(CASE WHEN v.status = 'used' THEN 1 ELSE 0 END) as used_count
                FROM voucher_groups vg
                LEFT JOIN voucher_sales_points vsp ON vg.sales_point_id = vsp.id
                LEFT JOIN vouchers v ON vg.id = v.group_id
                GROUP BY vg.id
                ORDER BY vg.created_at DESC
            ");
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            respond(['groups' => $groups]);
        } catch (Exception $e) {
            fail('Failed to fetch voucher groups: ' . $e->getMessage(), 500);
        }
        break;

    // Get vouchers by group
    case 'vouchers':
        requireAuth();
        try {
            $groupId = (int)($_GET['group_id'] ?? 0);
            $status = $_GET['status'] ?? '';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            
            $where = [];
            $params = [];
            
            if ($groupId) {
                $where[] = "v.group_id = ?";
                $params[] = $groupId;
            }
            
            if ($status && $status !== 'all') {
                $where[] = "v.status = ?";
                $params[] = $status;
            }
            
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            
            // Get total count
            $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM vouchers v $whereClause");
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get vouchers
            $stmt = $pdo->prepare("
                SELECT 
                    v.*,
                    vg.group_name,
                    vsp.name as sales_point_name
                FROM vouchers v
                LEFT JOIN voucher_groups vg ON v.group_id = vg.id
                LEFT JOIN voucher_sales_points vsp ON v.sales_point_id = vsp.id
                $whereClause
                ORDER BY v.created_at DESC
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute($params);
            $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            respond(['vouchers' => $vouchers, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (Exception $e) {
            fail('Failed to fetch vouchers: ' . $e->getMessage(), 500);
        }
        break;

    // Create voucher group and generate vouchers
    case 'create_vouchers':
        $user = requireAuth();
        
        try {
            $groupName = trim($body['group_name'] ?? '');
            $description = trim($body['description'] ?? '');
            $voucherTypeId = (int)($body['voucher_type_id'] ?? 0);
            $profileName = trim($body['profile_name'] ?? 'default');
            $quantity = (int)($body['quantity'] ?? 1);
            $salesPointId = (int)($body['sales_point_id'] ?? 0) ?: null;
            $codePrefix = trim($body['code_prefix'] ?? '');
            $codeLength = (int)($body['code_length'] ?? 6);
            
            if (!$groupName || $quantity < 1 || $quantity > 1000 || !$voucherTypeId) {
                fail('Invalid parameters: group_name, quantity, and voucher_type_id are required');
            }
            
            // Fetch voucher type details
            $stmt = $pdo->prepare("SELECT * FROM voucher_types WHERE id = ? AND is_active = 1");
            $stmt->execute([$voucherTypeId]);
            $voucherType = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$voucherType) {
                fail('Voucher type not found or inactive');
            }
            
            $validityHours = $voucherType['duration_hours'];
            $price = $voucherType['base_amount'];
            $dataLimitMB = $voucherType['data_limit_mb'];
            $speedLimitKbps = $voucherType['speed_limit_kbps'];
            
            $pdo->beginTransaction();
            
            // Create group
            $stmt = $pdo->prepare("
                INSERT INTO voucher_groups 
                (group_name, description, profile_name, validity_hours, data_limit_mb, speed_limit_kbps, price, sales_point_id, voucher_type_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$groupName, $description, $profileName, $validityHours, $dataLimitMB, $speedLimitKbps, $price, $salesPointId, $voucherTypeId, $user['username']]);
            $groupId = $pdo->lastInsertId();
            
            // Generate vouchers
            $vouchers = [];
            for ($i = 0; $i < $quantity; $i++) {
                // Generate code with optional prefix
                if ($codePrefix) {
                    $code = $codePrefix . '-' . strtoupper(bin2hex(random_bytes($codeLength / 2)));
                } else {
                    $code = strtoupper(bin2hex(random_bytes($codeLength / 2)));
                }
                $password = strtoupper(bin2hex(random_bytes(4)));
                
                $stmt = $pdo->prepare("
                    INSERT INTO vouchers 
                    (voucher_code, password, group_id, profile_name, validity_hours, data_limit_mb, speed_limit_kbps, price, sales_point_id, voucher_type_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $password, $groupId, $profileName, $validityHours, $dataLimitMB, $speedLimitKbps, $price, $salesPointId, $voucherTypeId]);
                
                $vouchers[] = [
                    'voucher_code' => $code,
                    'password' => $password,
                    'validity_hours' => $validityHours,
                    'price' => $price
                ];
            }
            
            $pdo->commit();
            
            respond(['success' => true, 'group_id' => $groupId, 'vouchers' => $vouchers, 'count' => count($vouchers)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            fail('Failed to create vouchers: ' . $e->getMessage(), 500);
        }
        break;

    // Get sales points
    case 'sales_points':
        requireAuth();
        try {
            $stmt = $pdo->query("
                SELECT 
                    vsp.*,
                    COUNT(DISTINCT v.id) as total_vouchers,
                    SUM(CASE WHEN v.status = 'used' THEN v.price ELSE 0 END) as total_revenue
                FROM voucher_sales_points vsp
                LEFT JOIN vouchers v ON vsp.id = v.sales_point_id
                GROUP BY vsp.id
                ORDER BY vsp.name
            ");
            $points = $stmt->fetchAll(PDO::FETCH_ASSOC);
            respond(['sales_points' => $points]);
        } catch (Exception $e) {
            fail('Failed to fetch sales points: ' . $e->getMessage(), 500);
        }
        break;

    // Create sales point
    case 'create_sales_point':
        requireAuth();
        try {
            // Check if table exists
            $checkTable = $pdo->query("SHOW TABLES LIKE 'voucher_sales_points'");
            if ($checkTable->rowCount() === 0) {
                fail('Sales points feature not available. Please run database migration.', 500);
            }
            
            $name = trim($body['name'] ?? '');
            $location = trim($body['location'] ?? '');
            $contactPerson = trim($body['contact_person'] ?? '');
            $contactPhone = trim($body['contact_phone'] ?? '');
            
            if (!$name) {
                fail('Sales point name is required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO voucher_sales_points (name, location, contact_person, contact_phone)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $location, $contactPerson, $contactPhone]);
            
            respond(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (Exception $e) {
            fail('Failed to create sales point: ' . $e->getMessage(), 500);
        }
        break;

    // Get voucher statistics
    case 'voucher_stats':
        requireAuth();
        try {
            $days = (int)($_GET['days'] ?? 30);
            
            // Overall stats
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total_vouchers,
                    SUM(CASE WHEN status = 'unused' THEN 1 ELSE 0 END) as unused,
                    SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as used,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN status = 'used' THEN price ELSE 0 END) as total_revenue
                FROM vouchers
            ");
            $overall = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Daily stats
            $stmt = $pdo->prepare("
                SELECT 
                    DATE(first_used_at) as date,
                    COUNT(*) as vouchers_used,
                    SUM(price) as revenue,
                    COUNT(DISTINCT used_by_mac) as unique_devices
                FROM vouchers
                WHERE status = 'used' AND first_used_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(first_used_at)
                ORDER BY date DESC
            ");
            $stmt->execute([$days]);
            $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // By sales point
            $stmt = $pdo->query("
                SELECT 
                    vsp.name,
                    COUNT(v.id) as total_vouchers,
                    SUM(CASE WHEN v.status = 'used' THEN 1 ELSE 0 END) as used,
                    SUM(CASE WHEN v.status = 'used' THEN v.price ELSE 0 END) as revenue
                FROM voucher_sales_points vsp
                LEFT JOIN vouchers v ON vsp.id = v.sales_point_id
                GROUP BY vsp.id
                ORDER BY revenue DESC
            ");
            $bySalesPoint = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            respond([
                'overall' => $overall,
                'daily' => $daily,
                'by_sales_point' => $bySalesPoint
            ]);
        } catch (Exception $e) {
            fail('Failed to fetch voucher stats: ' . $e->getMessage(), 500);
        }
        break;

    // Sync voucher to FreeRADIUS
    case 'sync_voucher_to_radius':
        requireAuth();
        try {
            $voucherId = (int)($body['voucher_id'] ?? 0);
            
            $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
            $stmt->execute([$voucherId]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$voucher) {
                fail('Voucher not found', 404);
            }
            
            // Add to radcheck (username/password)
            $stmt = $pdo->prepare("
                INSERT INTO radcheck (username, attribute, op, value)
                VALUES (?, 'Cleartext-Password', ':=', ?)
                ON DUPLICATE KEY UPDATE value = VALUES(value)
            ");
            $stmt->execute([$voucher['voucher_code'], $voucher['password']]);
            
            // Add to radreply (session timeout, data limit, etc.)
            if ($voucher['validity_hours']) {
                $seconds = $voucher['validity_hours'] * 3600;
                $stmt = $pdo->prepare("
                    INSERT INTO radreply (username, attribute, op, value)
                    VALUES (?, 'Session-Timeout', ':=', ?)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)
                ");
                $stmt->execute([$voucher['voucher_code'], $seconds]);
            }
            
            respond(['success' => true, 'message' => 'Voucher synced to FreeRADIUS']);
        } catch (Exception $e) {
            fail('Failed to sync voucher: ' . $e->getMessage(), 500);
        }
        break;

    default:
        fail('Invalid action', 400);
}

// Helper function to parse uptime string to seconds
function parseUptime($uptime) {
    $seconds = 0;
    if (preg_match('/(\d+)w/', $uptime, $m)) $seconds += $m[1] * 604800;
    if (preg_match('/(\d+)d/', $uptime, $m)) $seconds += $m[1] * 86400;
    if (preg_match('/(\d+)h/', $uptime, $m)) $seconds += $m[1] * 3600;
    if (preg_match('/(\d+)m/', $uptime, $m)) $seconds += $m[1] * 60;
    if (preg_match('/(\d+)s/', $uptime, $m)) $seconds += $m[1];
    return $seconds;
}
