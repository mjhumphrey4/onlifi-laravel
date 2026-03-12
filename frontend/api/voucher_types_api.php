<?php
/**
 * Voucher Types API
 * Manages voucher type profiles (duration, base amount, etc.)
 */

require_once __DIR__ . '/../../config_multitenant.php';

// Set session name and start session
ini_set('session.name', 'ONLIFI_SESSION');
session_name('ONLIFI_SESSION');
session_start();

// Set session lifetime
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
ini_set('session.cookie_lifetime', SESSION_LIFETIME);

header('Content-Type: application/json');

function respond($data) {
    echo json_encode($data);
    exit;
}

function fail($message, $code = 400) {
    http_response_code($code);
    respond(['error' => $message]);
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

// Get tenant database connection
$user = requireAuth();
$pdo = getTenantDB($user['database_name']);

if (!$pdo) {
    fail('Database connection failed', 500);
}

// Check if voucher_types table exists
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'voucher_types'");
    if ($stmt->rowCount() === 0) {
        fail('Voucher types table does not exist. Please run the database migration first.', 500);
    }
} catch (Exception $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($action) {
    // Get all voucher types
    case 'list':
        try {
            $stmt = $pdo->query("
                SELECT 
                    vt.*,
                    COUNT(DISTINCT v.id) as total_vouchers,
                    SUM(CASE WHEN v.status = 'unused' THEN 1 ELSE 0 END) as unused_count,
                    SUM(CASE WHEN v.status = 'used' THEN 1 ELSE 0 END) as used_count
                FROM voucher_types vt
                LEFT JOIN vouchers v ON vt.id = v.voucher_type_id
                WHERE vt.is_active = 1
                GROUP BY vt.id
                ORDER BY vt.duration_hours ASC
            ");
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            respond(['types' => $types]);
        } catch (Exception $e) {
            fail('Failed to fetch voucher types: ' . $e->getMessage(), 500);
        }
        break;

    // Create new voucher type
    case 'create':
        try {
            $typeName = trim($body['type_name'] ?? '');
            $durationHours = (int)($body['duration_hours'] ?? 0);
            $baseAmount = (float)($body['base_amount'] ?? 0);
            $description = trim($body['description'] ?? '');
            $dataLimitMB = (int)($body['data_limit_mb'] ?? 0) ?: null;
            $speedLimitKbps = (int)($body['speed_limit_kbps'] ?? 0) ?: null;

            if (!$typeName || $durationHours <= 0 || $baseAmount < 0) {
                fail('Invalid parameters: type_name, duration_hours, and base_amount are required');
            }

            $stmt = $pdo->prepare("
                INSERT INTO voucher_types 
                (type_name, duration_hours, base_amount, description, data_limit_mb, speed_limit_kbps)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$typeName, $durationHours, $baseAmount, $description, $dataLimitMB, $speedLimitKbps]);

            respond(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                fail('Voucher type with this name already exists', 409);
            }
            fail('Failed to create voucher type: ' . $e->getMessage(), 500);
        }
        break;

    // Update voucher type
    case 'update':
        try {
            $id = (int)($body['id'] ?? 0);
            $typeName = trim($body['type_name'] ?? '');
            $durationHours = (int)($body['duration_hours'] ?? 0);
            $baseAmount = (float)($body['base_amount'] ?? 0);
            $description = trim($body['description'] ?? '');
            $dataLimitMB = (int)($body['data_limit_mb'] ?? 0) ?: null;
            $speedLimitKbps = (int)($body['speed_limit_kbps'] ?? 0) ?: null;

            if (!$id || !$typeName || $durationHours <= 0 || $baseAmount < 0) {
                fail('Invalid parameters');
            }

            $stmt = $pdo->prepare("
                UPDATE voucher_types 
                SET type_name = ?, 
                    duration_hours = ?, 
                    base_amount = ?, 
                    description = ?,
                    data_limit_mb = ?,
                    speed_limit_kbps = ?
                WHERE id = ?
            ");
            $stmt->execute([$typeName, $durationHours, $baseAmount, $description, $dataLimitMB, $speedLimitKbps, $id]);

            respond(['success' => true]);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                fail('Voucher type with this name already exists', 409);
            }
            fail('Failed to update voucher type: ' . $e->getMessage(), 500);
        }
        break;

    // Delete (deactivate) voucher type
    case 'delete':
        try {
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);

            if (!$id) {
                fail('Voucher type ID is required');
            }

            // Soft delete - just mark as inactive
            $stmt = $pdo->prepare("UPDATE voucher_types SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);

            respond(['success' => true]);
        } catch (Exception $e) {
            fail('Failed to delete voucher type: ' . $e->getMessage(), 500);
        }
        break;

    // Get voucher type by ID
    case 'get':
        try {
            $id = (int)($_GET['id'] ?? 0);

            if (!$id) {
                fail('Voucher type ID is required');
            }

            $stmt = $pdo->prepare("SELECT * FROM voucher_types WHERE id = ?");
            $stmt->execute([$id]);
            $type = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$type) {
                fail('Voucher type not found', 404);
            }

            respond(['type' => $type]);
        } catch (Exception $e) {
            fail('Failed to fetch voucher type: ' . $e->getMessage(), 500);
        }
        break;

    default:
        fail('Invalid action', 400);
}
