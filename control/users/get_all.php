<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
/**
 * Get All Users Endpoint
 * GET /users/get_all.php
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'users', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read users.']);
     exit;
 }
 
// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Get optional query parameters for filtering
    $search = $_GET['search'] ?? null;
    $email = $_GET['email'] ?? null;

    // Build query — users plus user_addresses (same table as mobile/v1/address)
    $sql = "
        SELECT
            u.id,
            u.name,
            u.surname,
            u.email,
            u.cell,
            u.status,
            u.created_at,
            u.updated_at,
            ua.id AS address_id,
            ua.place_id,
            ua.formatted_address,
            ua.latitude,
            ua.longitude,
            ua.street_number,
            ua.street,
            ua.sublocality,
            ua.city,
            ua.district,
            ua.region,
            ua.country,
            ua.country_code,
            ua.postal_code,
            ua.nickname,
            ua.created_at AS address_created_at,
            ua.updated_at AS address_updated_at
        FROM users u
        LEFT JOIN user_addresses ua ON u.id = ua.user_id
    ";

    $params = [];
    $conditions = [];

    if ($email) {
        $conditions[] = "email = ?";
        $params[] = $email;
    }

    if ($search) {
        $conditions[] = "(name LIKE ? OR surname LIKE ? OR email LIKE ?)";
        $search_term = '%' . $search . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY u.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rawUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group users and collect their addresses
    $users = [];
    foreach ($rawUsers as $row) {
        $userId = $row['id'];

        if (!isset($users[$userId])) {
            // Create user entry
            $users[$userId] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'surname' => $row['surname'],
                'email' => $row['email'],
                'cell' => $row['cell'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'addresses' => []
            ];
        }

        // Add address if it exists (same shape as mobile/v1/address/get.php)
        if ($row['address_id']) {
            $users[$userId]['addresses'][] = [
                'id' => (int)$row['address_id'],
                'place_id' => $row['place_id'],
                'formatted_address' => $row['formatted_address'],
                'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                'nickname' => $row['nickname'] ?? null,
                'address_components' => [
                    'street_number' => $row['street_number'],
                    'street' => $row['street'],
                    'city' => $row['city'],
                    'sublocality' => $row['sublocality'],
                    'district' => $row['district'],
                    'region' => $row['region'],
                    'country' => $row['country'],
                    'country_code' => $row['country_code'],
                    'postal_code' => $row['postal_code']
                ],
                'created_at' => $row['address_created_at'],
                'updated_at' => $row['address_updated_at']
            ];
        }
    }

    // Convert associative array back to indexed array
    $users = array_values($users);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Users retrieved successfully.',
        'data' => $users,
        'count' => count($users)
    ]);

} catch (PDOException $e) {
    logException('users_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving users: ' . $e->getMessage()
    ]);
}
?>

