<?php

/**
 * Order Action Tracker Utility
 * 
 * Tracks order actions (created, updated, cancelled, etc.) in the order_track table
 * for audit and history purposes.
 * 
 * Table: order_track
 * Fields: id, order_id, action, create_At
 */

/**
 * Track an order action
 * 
 * @param PDO $pdo Database connection
 * @param int $order_id The order ID
 * @param string $action The action performed (e.g., 'created', 'updated', 'cancelled')
 * @return bool True on success, false on failure
 */
function trackOrderAction($pdo, $order_id, $action) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO order_track (order_id, action, create_At)
            VALUES (?, ?, NOW())
        ");
        
        return $stmt->execute([$order_id, $action]);
    } catch (PDOException $e) {
        // Log the error but don't throw - tracking failure shouldn't break the main operation
        error_log("Order tracking failed for order_id: {$order_id}, action: {$action}. Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get order action history
 * 
 * @param PDO $pdo Database connection
 * @param int $order_id The order ID
 * @return array Array of order actions with id, order_id, action, create_At
 */
function getOrderActionHistory($pdo, $order_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, order_id, action, create_At
            FROM order_track
            WHERE order_id = ?
            ORDER BY create_At ASC, id ASC
        ");
        
        $stmt->execute([$order_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to retrieve order action history for order_id: {$order_id}. Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all order actions for multiple orders
 * 
 * @param PDO $pdo Database connection
 * @param array $order_ids Array of order IDs
 * @return array Associative array grouped by order_id
 */
function getOrderActionsForOrders($pdo, $order_ids) {
    if (empty($order_ids)) {
        return [];
    }
    
    try {
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT id, order_id, action, create_At
            FROM order_track
            WHERE order_id IN ({$placeholders})
            ORDER BY order_id ASC, create_At ASC, id ASC
        ");
        
        $stmt->execute($order_ids);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by order_id
        $grouped = [];
        foreach ($results as $row) {
            $grouped[$row['order_id']][] = $row;
        }
        
        return $grouped;
    } catch (PDOException $e) {
        error_log("Failed to retrieve order actions for multiple orders. Error: " . $e->getMessage());
        return [];
    }
}

?>
