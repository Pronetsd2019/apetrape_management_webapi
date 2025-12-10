<?php
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

    // Build query
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
            ua.id as address_id,
            ua.street as address_street,
            ua.plot as address_plot,
            ua.created_at as address_created_at,
            ua.updated_at as address_updated_at,
            c.id as city_id,
            c.name as city_name,
            r.id as region_id,
            r.name as region_name,
            co.id as country_id,
            co.name as country_name
        FROM users u
        LEFT JOIN user_address ua ON u.id = ua.user_id
        LEFT JOIN city c ON ua.city = c.id
        LEFT JOIN region r ON c.region_id = r.id
        LEFT JOIN country co ON r.country_id = co.id
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

        // Add address if it exists
        if ($row['address_id']) {
            $users[$userId]['addresses'][] = [
                'id' => (int)$row['address_id'],
                'street' => $row['address_street'],
                'plot' => $row['address_plot'],
                'city' => $row['city_name'],
                'region' => $row['region_name'],
                'country' => $row['country_name'],
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

