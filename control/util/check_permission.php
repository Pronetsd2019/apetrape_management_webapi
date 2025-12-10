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
    // Log file path
    // check_permission.php is in control/util/, so dirname(__DIR__) = control/
    $logFile = dirname(__DIR__) . '/logs/permission_checks.log';
    $logDir = dirname($logFile);
    
    // Create logs directory if it doesn't exist
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            error_log("Failed to create logs directory: " . $logDir);
        }
    }
    
    // Helper function to log data
    $logData = function($data) use ($logFile, $logDir) {
        // Ensure directory exists
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                error_log("Failed to create logs directory: " . $logDir);
                return;
            }
        }
        
        // Ensure file is writable or create it
        if (!file_exists($logFile)) {
            // Try to create the file
            $handle = @fopen($logFile, 'w');
            if ($handle) {
                fclose($handle);
            } else {
                error_log("Failed to create log file: " . $logFile . " - Check directory permissions: " . $logDir);
                return;
            }
        }
        
        // Check if file is writable
        if (!is_writable($logFile)) {
            error_log("Log file is not writable: " . $logFile);
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] " . json_encode($data, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 80) . "\n";
        $result = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            error_log("Failed to write to log file: " . $logFile . " - Last error: " . error_get_last()['message']);
        }
    };
    
    // Validate inputs
    if (!$userId || !is_numeric($userId)) {
        $logData([
            'admin_id' => $userId,
            'module' => $module,
            'action' => $action,
            'result' => 'FAILED - Invalid user ID',
            'reason' => 'User ID is not numeric or empty'
        ]);
        return false;
    }

    if (!$module || !$action) {
        $logData([
            'admin_id' => $userId,
            'module' => $module,
            'action' => $action,
            'result' => 'FAILED - Invalid parameters',
            'reason' => 'Module or action is empty'
        ]);
        return false;
    }

    // Validate action
    $validActions = ['read', 'create', 'update', 'delete'];
    if (!in_array(strtolower($action), $validActions)) {
        $logData([
            'admin_id' => $userId,
            'module' => $module,
            'action' => $action,
            'result' => 'FAILED - Invalid action',
            'reason' => 'Action not in valid list: ' . implode(', ', $validActions)
        ]);
        return false;
    }

    try {
        global $pdo; // Access the global PDO connection

        // Get user's role
        $roleSql = "
            SELECT r.id as role_id
            FROM admins a
            INNER JOIN roles r ON a.role_id = r.id
            WHERE a.id = ?
        ";
        $stmt = $pdo->prepare($roleSql);
        $stmt->execute([$userId]);
        $userRole = $stmt->fetch(PDO::FETCH_ASSOC);

        $logData([
            'admin_id' => $userId,
            'module' => $module,
            'action' => $action,
            'step' => '1. Get user role',
            'sql' => $roleSql,
            'sql_params' => [$userId],
            'sql_result' => $userRole
        ]);

        if (!$userRole) {
            $logData([
                'admin_id' => $userId,
                'module' => $module,
                'action' => $action,
                'result' => 'FAILED - No role found',
                'reason' => 'User has no role assigned'
            ]);
            return false; // User has no role
        }

        // Check permission for the specific module and action
        // Trim module name to handle any whitespace issues
        $module = trim($module);
        $permissionSql = "
            SELECT rmp.can_read, rmp.can_create, rmp.can_update, rmp.can_delete
            FROM role_module_permissions rmp
            INNER JOIN modules m ON rmp.module_id = m.id
            WHERE rmp.role_id = ? AND LOWER(TRIM(m.module_name)) = LOWER(?)
        ";
        $stmt = $pdo->prepare($permissionSql);
        $stmt->execute([$userRole['role_id'], $module]);
        $permission = $stmt->fetch(PDO::FETCH_ASSOC);

        $logData([
            'admin_id' => $userId,
            'module' => $module,
            'action' => $action,
            'step' => '2. Check permissions',
            'role_id' => $userRole['role_id'],
            'sql' => $permissionSql,
            'sql_params' => [$userRole['role_id'], $module],
            'sql_result' => $permission
        ]);

        if (!$permission) {
            $logData([
                'admin_id' => $userId,
                'module' => $module,
                'action' => $action,
                'result' => 'FAILED - No permissions found',
                'reason' => 'No permissions defined for this module and role'
            ]);
            return false; // No permissions defined for this module
        }

        // Check if user has the requested permission
        $actionColumn = 'can_' . strtolower($action);
        $hasPermission = (bool)$permission[$actionColumn];
        
        $logData([
            'admin_id' => $userId,
            'module' => $module,
            'action' => $action,
            'step' => '3. Final check',
            'action_column' => $actionColumn,
            'permission_value' => $permission[$actionColumn],
            'result' => $hasPermission ? 'ALLOWED' : 'DENIED'
        ]);

        return $hasPermission;

    } catch (PDOException $e) {
        // Log error
        $logData([
            'admin_id' => $userId,
            'module' => $module,
            'action' => $action,
            'result' => 'FAILED - Database error',
            'error' => $e->getMessage(),
            'error_code' => $e->getCode()
        ]);
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
