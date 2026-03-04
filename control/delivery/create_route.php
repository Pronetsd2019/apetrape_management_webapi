<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Create Delivery Route Endpoint
 * GET /delivery/create_route.php
 * Optional: ?status=assigned — filter by assignment status (forwarded to order_assignments query).
 *
 * For the authenticated driver (admin JWT), loads their assigned orders, resolves each stop's
 * coordinates from delivery_cost_map (spatial POINT columns) or pickup_points, then calls the
 * Google Routes API (computeRoutes) with optimizeWaypointOrder:true using the warehouse as
 * both origin and destination.
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';

requireJwtAuth();

header('Content-Type: application/json');

$authUser = $GLOBALS['auth_user'] ?? null;
$userId   = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

if (!checkUserPermission($userId, 'orders', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to read orders.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // -------------------------------------------------------------------------
    // 1. Load warehouse origin
    // -------------------------------------------------------------------------
    $whStmt = $pdo->query("
        SELECT id, place_id, formatted_address, latitude, longitude,
               street_number, street, sublocality, city, district,
               region, country, country_code, postal_code, created_at, updated_at
        FROM warehouse_address
        LIMIT 1
    ");
    $warehouse = $whStmt ? $whStmt->fetch(PDO::FETCH_ASSOC) : null;

    if (!$warehouse) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No warehouse address configured. Add a row to warehouse_address first.'
        ]);
        exit;
    }

    $warehouseLat = (float)$warehouse['latitude'];
    $warehouseLng = (float)$warehouse['longitude'];

    // -------------------------------------------------------------------------
    // 2. Load order IDs assigned to the authenticated driver
    // -------------------------------------------------------------------------
    $statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : null;

    if ($statusFilter !== null && $statusFilter !== '') {
        $assignStmt = $pdo->prepare("
            SELECT DISTINCT order_id
            FROM order_assignments
            WHERE assigned_to = ? AND status = ?
            ORDER BY order_id DESC
        ");
        $assignStmt->execute([$userId, $statusFilter]);
    } else {
        $assignStmt = $pdo->prepare("
            SELECT DISTINCT order_id
            FROM order_assignments
            WHERE assigned_to = ?
            ORDER BY order_id DESC
        ");
        $assignStmt->execute([$userId]);
    }
    $assignedOrderIds = array_column($assignStmt->fetchAll(PDO::FETCH_ASSOC), 'order_id');

    if (empty($assignedOrderIds)) {
        http_response_code(200);
        echo json_encode([
            'success'   => true,
            'message'   => 'No assigned orders found.',
            'warehouse' => $warehouse,
            'stops'     => [],
            'route'     => null
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. Fetch order metadata (delivery_method, delivery_address, pickup_point)
    // -------------------------------------------------------------------------
    $placeholders = implode(',', array_fill(0, count($assignedOrderIds), '?'));
    $ordersStmt   = $pdo->prepare("
        SELECT o.id, o.order_no, o.delivery_method, o.delivery_address, o.pickup_point
        FROM orders o
        WHERE o.id IN ($placeholders)
    ");
    $ordersStmt->execute($assignedOrderIds);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    $orderIds = array_column($orders, 'id');

    // -------------------------------------------------------------------------
    // 4. Resolve stop coordinates
    //    Delivery: delivery_fee → delivery_cost_map (spatial POINT columns)
    //    Pickup  : pickup_order_fees → pickup_points (plain decimal lat/lng)
    // -------------------------------------------------------------------------

    // Load delivery_cost_map data for all delivery orders in one query
    $deliveryPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
    $dcmStmt = $pdo->prepare("
        SELECT
            df.order_id,
            dcm.id        AS cost_map_id,
            dcm.km,
            dcm.amount,
            ST_X(dcm.pickup)    AS pickup_lat,
            ST_Y(dcm.pickup)    AS pickup_lng,
            ST_X(dcm.delivery)  AS delivery_lat,
            ST_Y(dcm.delivery)  AS delivery_lng
        FROM delivery_fee df
        JOIN delivery_cost_map dcm ON df.cost_map_id = dcm.id
        WHERE df.order_id IN ($deliveryPlaceholders)
    ");
    $dcmStmt->execute($orderIds);
    $dcmByOrder = [];
    foreach ($dcmStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dcmByOrder[(int)$row['order_id']] = $row;
    }

    // Load pickup_points data for all pickup/collection orders in one query
    $ppStmt = $pdo->prepare("
        SELECT
            pof.order_id,
            pof.id      AS pickup_fee_id,
            pof.fee     AS pickup_fee,
            pp.id       AS pickup_point_id,
            pp.name     AS pickup_point_name,
            pp.address  AS pickup_point_address,
            pp.latitude,
            pp.longitude
        FROM pickup_order_fees pof
        JOIN pickup_points pp ON pof.pickup_id = pp.id
        WHERE pof.order_id IN ($deliveryPlaceholders)
    ");
    $ppStmt->execute($orderIds);
    $ppByOrder = [];
    foreach ($ppStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ppByOrder[(int)$row['order_id']] = $row;
    }

    // -------------------------------------------------------------------------
    // 5. Build stops and intermediates
    // -------------------------------------------------------------------------
    $stops       = [];
    $intermediates = [];
    $skipped     = [];

    foreach ($orders as $order) {
        $orderId        = (int)$order['id'];
        $deliveryMethod = strtolower(trim((string)($order['delivery_method'] ?? '')));
        $isPickup       = ($deliveryMethod === 'pickup');

        if ($isPickup) {
            if (!isset($ppByOrder[$orderId])) {
                $skipped[] = [
                    'order_id' => $orderId,
                    'order_no' => $order['order_no'],
                    'reason'   => 'No pickup_order_fees / pickup_points row found for this order.'
                ];
                continue;
            }
            $pp      = $ppByOrder[$orderId];
            $stopLat = (float)$pp['latitude'];
            $stopLng = (float)$pp['longitude'];
            $stops[] = [
                'order_id'        => $orderId,
                'order_no'        => $order['order_no'],
                'delivery_method' => 'collection',
                'address'         => $pp['pickup_point_address'],
                'pickup_point'    => [
                    'id'      => (int)$pp['pickup_point_id'],
                    'name'    => $pp['pickup_point_name'],
                    'address' => $pp['pickup_point_address'],
                ],
                'latitude'  => $stopLat,
                'longitude' => $stopLng,
            ];
        } else {
            if (!isset($dcmByOrder[$orderId])) {
                $skipped[] = [
                    'order_id' => $orderId,
                    'order_no' => $order['order_no'],
                    'reason'   => 'No delivery_fee / delivery_cost_map row found for this order.'
                ];
                continue;
            }
            $dcm     = $dcmByOrder[$orderId];
            $stopLat = (float)$dcm['delivery_lat'];
            $stopLng = (float)$dcm['delivery_lng'];
            $stops[] = [
                'order_id'        => $orderId,
                'order_no'        => $order['order_no'],
                'delivery_method' => 'delivery',
                'address'         => $order['delivery_address'],
                'cost_map'        => [
                    'id'     => (int)$dcm['cost_map_id'],
                    'km'     => (float)$dcm['km'],
                    'amount' => (float)$dcm['amount'],
                ],
                'latitude'  => $stopLat,
                'longitude' => $stopLng,
            ];
        }

        $intermediates[] = [
            'location' => [
                'latLng' => [
                    'latitude'  => $stopLat,
                    'longitude' => $stopLng,
                ]
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // 6. Call Google Routes API
    // -------------------------------------------------------------------------
    $routeResult = null;
    $routeError  = null;

    if (!empty($intermediates)) {
        $apiKey = getenv('GOOGLE_MAPS_API_KEY') ?: ($_ENV['GOOGLE_MAPS_API_KEY'] ?? null);

        if (!$apiKey) {
            $routeError = 'Google Maps API key not configured (GOOGLE_MAPS_API_KEY env var missing).';
        } else {
            $routesUrl = 'https://routes.googleapis.com/directions/v2:computeRoutes';

            $requestPayload = [
                'origin' => [
                    'location' => [
                        'latLng' => [
                            'latitude'  => $warehouseLat,
                            'longitude' => $warehouseLng,
                        ]
                    ]
                ],
                'destination' => [
                    'location' => [
                        'latLng' => [
                            'latitude'  => $warehouseLat,
                            'longitude' => $warehouseLng,
                        ]
                    ]
                ],
                'intermediates'           => $intermediates,
                'travelMode'              => 'DRIVE',
                'optimizeWaypointOrder'   => true,
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $routesUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestPayload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $apiKey,
                'X-Goog-FieldMask: routes.optimizedIntermediateWaypointIndex,routes.legs,routes.distanceMeters,routes.duration,routes.polyline',
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $routeError = 'Google Routes API request failed: ' . $curlError;
                logError('delivery_create_route', $routeError, ['order_ids' => $orderIds]);
            } elseif ($httpCode !== 200) {
                $routeError = 'Google Routes API returned HTTP ' . $httpCode . ': ' . $response;
                logError('delivery_create_route', $routeError, ['order_ids' => $orderIds]);
            } else {
                $routeResult = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $routeError  = 'Failed to parse Google Routes API response.';
                    $routeResult = null;
                    logError('delivery_create_route', $routeError, ['order_ids' => $orderIds]);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // 7. Return response
    // -------------------------------------------------------------------------
    $responsePayload = [
        'success'         => true,
        'message'         => 'Route created successfully.',
        'warehouse'       => $warehouse,
        'stops'           => $stops,
        'stops_count'     => count($stops),
        'route'           => $routeResult,
        'skipped'         => $skipped,
    ];

    if ($routeError !== null) {
        $responsePayload['route_error'] = $routeError;
        $responsePayload['message']     = 'Stops resolved but Google Routes API call failed.';
    }

    if ($statusFilter !== null && $statusFilter !== '') {
        $responsePayload['status_filter_applied'] = $statusFilter;
    }

    http_response_code(200);
    echo json_encode($responsePayload);

} catch (PDOException $e) {
    logException('delivery_create_route', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('delivery_create_route', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating route: ' . $e->getMessage()
    ]);
}
