<?php
/**
 * Permission Check Utility Function
 * Internal function to check if a user has permission for a specific action on a module
 */

require_once __DIR__ . '/../util/connect.php';

/**
 * Check if a user has permission to perform an action on a module
 *
 * @param int $userId The admin user ID
 * @param string $module The module name (e.g., 'store', 'orders')
 * @param string $action The action to check ('read', 'create', 'update', 'delete')
 * @return bool True if user has permission, false otherwise
 */
function checkUserPermission($userId, $module, $action) {
    // Validate inputs
    if (!$userId || !is_numeric($userId)) {
        return false;
    }

    if (!$module || !$action) {
        return false;
    }

    // Validate action
    $validActions = ['read', 'create', 'update', 'delete'];
    if (!in_array(strtolower($action), $validActions)) {
        return false;
    }

    try {
        global $pdo; // Access the global PDO connection

        // Get user's role
        $stmt = $pdo->prepare("
            SELECT r.id as role_id
            FROM admins a
            INNER JOIN roles r ON a.role_id = r.id
            WHERE a.id = ?
        ");
        $stmt->execute([$userId]);
        $userRole = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userRole) {
            return false; // User has no role
        }

        // Check permission for the specific module and action
        $stmt = $pdo->prepare("
            SELECT rmp.can_read, rmp.can_create, rmp.can_update, rmp.can_delete
            FROM role_module_permissions rmp
            INNER JOIN modules m ON rmp.module_id = m.id
            WHERE rmp.role_id = ? AND LOWER(m.module_name) = LOWER(?)
        ");
        $stmt->execute([$userRole['role_id'], $module]);
        $permission = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$permission) {
            return false; // No permissions defined for this module
        }

        // Check if user has the requested permission
        $actionColumn = 'can_' . strtolower($action);
        return (bool)$permission[$actionColumn];

    } catch (PDOException $e) {
        // Log error in production
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if the currently authenticated user has permission
 * Uses JWT token to get current user ID
 *
 * @param string $module The module name
 * @param string $action The action to check
 * @return bool True if current user has permission, false otherwise
 */
function checkCurrentUserPermission($module, $action) {
    // Get current user from JWT payload (set by auth middleware)
    $authUser = $GLOBALS['auth_user'] ?? null;
    if (!$authUser || !isset($authUser['admin_id'])) {
        return false;
    }

    return checkUserPermission($authUser['admin_id'], $module, $action);
}
?>
