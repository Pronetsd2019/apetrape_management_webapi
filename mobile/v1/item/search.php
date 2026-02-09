<?php

// CORS headers for subdomain support and localhost
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';
$isLocalhostOrigin = isset($_SERVER['HTTP_ORIGIN']) && (
    strpos($_SERVER['HTTP_ORIGIN'], 'http://localhost') === 0 ||
    strpos($_SERVER['HTTP_ORIGIN'], 'http://127.0.0.1') === 0
);

if ((isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) || $isLocalhostOrigin) {
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
 * Mobile Item Search Endpoint
 * GET /mobile/v1/item/search.php?q=search_text&manufacturer_id=1&category_id=2&model_id=3&sort=relevance
 * Public endpoint - no authentication required
 * Searches items by text (name, manufacturer, model) with filtering and sorting
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

// Static cache for category tree (per-request cache)
static $cached_category_tree = null;

// Static cache for synonyms (per-request cache)
static $cached_synonyms = null;

/**
 * Log search attempts to database for analytics
 * @param array $search_params Search parameters
 * @param int $results_count Number of results found
 */
function logSearchAttempt($search_params, $results_count) {
    global $pdo;

    try {
        // Get client information
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

        // Prepare log data
        $log_data = [
            'search_query' => $search_params['q'] ?? null,
            'manufacturer_id' => $search_params['manufacturer_id'] ?? null,
            'category_id' => $search_params['category_id'] ?? null,
            'model_id' => $search_params['model_id'] ?? null,
            'sort_option' => $search_params['sort'] ?? null,
            'page' => $search_params['page'] ?? 1,
            'page_size' => $search_params['page_size'] ?? 10,
            'results_count' => $results_count,
            'user_agent' => substr($user_agent, 0, 500), // Limit length
            'ip_address' => $ip_address
        ];

        // Insert log entry
        $stmt = $pdo->prepare("
            INSERT INTO search_logs
            (search_query, manufacturer_id, category_id, model_id, sort_option, page, page_size, results_count, user_agent, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute(array_values($log_data));

    } catch (Exception $e) {
        // Log the logging failure but don't interrupt the search
        logException('mobile_item_search_logging', $e);
        // Continue execution - don't fail the search because logging failed
    }
}

/**
 * Get cached synonyms or load from database
 * @return array Synonym mappings
 */
function getSynonymsCache() {
    global $pdo, $cached_synonyms;

    if ($cached_synonyms !== null) {
        return $cached_synonyms;
    }

    $cached_synonyms = [];

    try {
        $stmt = $pdo->query("SELECT term, synonym, weight FROM search_synonyms ORDER BY term");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $term = $row['term'];
            $synonym = $row['synonym'];
            $weight = (float)$row['weight'];

            if (!isset($cached_synonyms[$term])) {
                $cached_synonyms[$term] = [];
            }
            if (!isset($cached_synonyms[$synonym])) {
                $cached_synonyms[$synonym] = [];
            }

            // Bidirectional mapping
            $cached_synonyms[$term][$synonym] = $weight;
            $cached_synonyms[$synonym][$term] = $weight;
        }
    } catch (Exception $e) {
        // If synonyms loading fails, continue without synonyms
        logException('mobile_item_search_synonyms', $e);
        $cached_synonyms = [];
    }

    return $cached_synonyms;
}

/**
 * Expand search terms with synonyms
 * @param array $search_terms Original search terms
 * @return array Expanded terms with synonyms
 */
function expandSynonyms($search_terms) {
    $synonyms = getSynonymsCache();
    $expanded = [];

    foreach ($search_terms as $term) {
        $expanded[] = $term; // Keep original term

        // Add synonyms
        if (isset($synonyms[$term])) {
            foreach ($synonyms[$term] as $synonym => $weight) {
                if ($weight >= 0.8) { // Only high-confidence synonyms
                    $expanded[] = $synonym;
                }
            }
        }
    }

    return array_unique($expanded);
}

/**
 * Find similar terms using Levenshtein distance and SOUNDEX
 * @param string $term Search term
 * @param int $max_distance Maximum edit distance
 * @return array Similar terms
 */
function findSimilarTerms($term, $max_distance = 2) {
    global $pdo;

    if (strlen($term) < 3) {
        return []; // Skip very short terms
    }

    $similar = [];

    try {
        // Get candidate terms from database (limit for performance)
        $stmt = $pdo->query("
            (SELECT DISTINCT LOWER(name) as term, 'item' as type FROM items WHERE LENGTH(name) >= 3 LIMIT 500)
            UNION
            (SELECT DISTINCT LOWER(name) as term, 'manufacturer' as type FROM manufacturers WHERE LENGTH(name) >= 3 LIMIT 200)
            UNION
            (SELECT DISTINCT LOWER(model_name) as term, 'model' as type FROM vehicle_models WHERE LENGTH(model_name) >= 3 LIMIT 300)
        ");

        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $term_lower = strtolower($term);
        $term_soundex = soundex($term);

        foreach ($candidates as $candidate) {
            $candidate_term = $candidate['term'];

            // Skip if identical
            if ($candidate_term === $term_lower) {
                continue;
            }

            $distance = levenshtein($term_lower, $candidate_term);
            $soundex_match = soundex($candidate_term) === $term_soundex;

            // Accept if close distance OR same soundex AND reasonable length difference
            if ($distance <= $max_distance ||
                ($soundex_match && abs(strlen($candidate_term) - strlen($term)) <= 2)) {

                $similar[] = [
                    'term' => $candidate_term,
                    'distance' => $distance,
                    'soundex_match' => $soundex_match,
                    'type' => $candidate['type']
                ];
            }
        }

        // Sort by relevance: distance first, then soundex match
        usort($similar, function($a, $b) {
            if ($a['distance'] !== $b['distance']) {
                return $a['distance'] <=> $b['distance'];
            }
            if ($a['soundex_match'] !== $b['soundex_match']) {
                return $b['soundex_match'] <=> $a['soundex_match']; // True first
            }
            return strlen($a['term']) <=> strlen($b['term']); // Shorter first
        });

        // Return top matches, preferring exact soundex matches
        $top_matches = array_slice($similar, 0, 8);
        $result = [];

        // Prioritize soundex matches, then closest distance
        foreach ($top_matches as $match) {
            if ($match['soundex_match'] || $match['distance'] <= $max_distance) {
                $result[] = $match['term'];
            }
        }

        return array_slice(array_unique($result), 0, 5); // Max 5 suggestions

    } catch (Exception $e) {
        logException('mobile_item_search_similar', $e);
        return [];
    }
}

/**
 * Enhance search terms with synonyms and similar terms
 * @param array $search_terms Original search terms
 * @return array Enhanced terms
 */
function enhanceSearchTerms($search_terms) {
    $enhanced = [];

    foreach ($search_terms as $term) {
        $enhanced[] = $term; // Original term

        // Add synonyms
        $synonyms = expandSynonyms([$term]);
        foreach ($synonyms as $synonym) {
            if (!in_array($synonym, $enhanced)) {
                $enhanced[] = $synonym;
            }
        }

        // Add similar terms (typo tolerance)
        $similar = findSimilarTerms($term, 2); // Max distance of 2
        foreach ($similar as $similar_term) {
            if (!in_array($similar_term, $enhanced)) {
                $enhanced[] = $similar_term;
            }
        }

        // Add partial matches for longer terms
        if (strlen($term) > 4) {
            // Remove last character and add wildcard
            $partial = substr($term, 0, -1);
            if (!in_array($partial, $enhanced)) {
                $enhanced[] = $partial;
            }
        }
    }

    return array_unique($enhanced);
}

/**
 * Check if FULLTEXT indexes exist on required tables
 * @return bool True if all required indexes exist
 */
function checkFulltextIndexes() {
    global $pdo;

    try {
        // Check for FULLTEXT indexes on items (name, description)
        $stmt = $pdo->query("
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE()
            AND table_name = 'items'
            AND column_name IN ('name', 'description')
            AND index_type = 'FULLTEXT'
            LIMIT 1
        ");
        $items_index = $stmt->fetch();

        // Check for FULLTEXT indexes on manufacturers.name
        $stmt = $pdo->query("
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE()
            AND table_name = 'manufacturers'
            AND column_name = 'name'
            AND index_type = 'FULLTEXT'
            LIMIT 1
        ");
        $manufacturers_index = $stmt->fetch();

        // Check for FULLTEXT indexes on vehicle_models (model_name, variant)
        $stmt = $pdo->query("
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE()
            AND table_name = 'vehicle_models'
            AND column_name IN ('model_name', 'variant')
            AND index_type = 'FULLTEXT'
            LIMIT 1
        ");
        $models_index = $stmt->fetch();

        return $items_index && $manufacturers_index && $models_index;

    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get cached category tree or build it if not exists
 * @return array Category tree mapping category_id => [descendant_ids]
 */
function getCategoryTree() {
    global $pdo, $cached_category_tree;

    if ($cached_category_tree !== null) {
        return $cached_category_tree;
    }

    $cached_category_tree = [];

    try {
        // Get all categories
        $stmt = $pdo->query("SELECT id, parent_id FROM categories ORDER BY id");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build tree structure
        $tree = [];
        foreach ($categories as $category) {
            $id = (int)$category['id'];
            $parent_id = $category['parent_id'] ? (int)$category['parent_id'] : null;

            if (!isset($tree[$id])) {
                $tree[$id] = [];
            }

            if ($parent_id !== null) {
                if (!isset($tree[$parent_id])) {
                    $tree[$parent_id] = [];
                }
                $tree[$parent_id][] = $id;
            }
        }

        // Function to get all descendants recursively
        $getDescendants = function($categoryId) use (&$tree, &$getDescendants) {
            $descendants = [$categoryId]; // Include self

            if (isset($tree[$categoryId])) {
                foreach ($tree[$categoryId] as $childId) {
                    $descendants = array_merge($descendants, $getDescendants($childId));
                }
            }

            return $descendants;
        };

        // Build cached tree for all categories
        foreach ($categories as $category) {
            $id = (int)$category['id'];
            $cached_category_tree[$id] = $getDescendants($id);
        }

    } catch (Exception $e) {
        // If category tree building fails, log and return empty array
        logException('mobile_item_search_category_tree', $e);
        $cached_category_tree = [];
    }

    return $cached_category_tree;
}

try {
    // Parse and validate query parameters
    $q = trim($_GET['q'] ?? '');
    $manufacturer_id = $_GET['manufacturer_id'] ?? null;
    $category_id = $_GET['category_id'] ?? null;
    $model_id = $_GET['model_id'] ?? null;
    $sort = $_GET['sort'] ?? 'relevance';

    // Pagination parameters
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;

    // Validate pagination parameters
    if ($page < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Page must be 1 or greater.']);
        exit;
    }
    if ($page_size < 1 || $page_size > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Page size must be between 1 and 100.']);
        exit;
    }

    $offset = ($page - 1) * $page_size;

    // Validate sort parameter
    $allowed_sort_options = ['relevance', 'price_asc', 'price_desc'];
    if (!in_array($sort, $allowed_sort_options)) {
        $sort = 'relevance';
    }

    // Validate numeric parameters
    if ($manufacturer_id !== null && (!is_numeric($manufacturer_id) || $manufacturer_id <= 0)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid manufacturer_id parameter.']);
        exit;
    }
    $manufacturer_id = $manufacturer_id ? (int)$manufacturer_id : null;

    if ($category_id !== null && (!is_numeric($category_id) || $category_id <= 0)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid category_id parameter.']);
        exit;
    }
    $category_id = $category_id ? (int)$category_id : null;

    if ($model_id !== null && (!is_numeric($model_id) || $model_id <= 0)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid model_id parameter.']);
        exit;
    }
    $model_id = $model_id ? (int)$model_id : null;

    // Check if FULLTEXT indexes exist
    $has_fulltext_indexes = checkFulltextIndexes();

    // Prepare search terms for smart matching
    $search_terms = [];
    $search_conditions = [];
    $search_params = [];

    if (!empty($q)) {
        if (!$has_fulltext_indexes) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Search unavailable',
                'message' => 'Text search is currently unavailable. Please try filtering by manufacturer, category, or model instead.',
                'error_details' => 'FULLTEXT indexes are not available. Please run the following SQL commands: ALTER TABLE items ADD FULLTEXT INDEX idx_fulltext_name_description (name, description); ALTER TABLE manufacturers ADD FULLTEXT INDEX idx_fulltext_name (name); ALTER TABLE vehicle_models ADD FULLTEXT INDEX idx_fulltext_models (model_name, variant);'
            ]);
            exit;
        }

        // Split search query into words and clean them
        $words = array_filter(array_map('trim', preg_split('/\s+/', $q)));
        $original_search_terms = array_unique($words);

        // Store original terms for both WHERE clause and relevance scoring
        // We use original terms for WHERE to avoid parameter mismatches
        // LIKE search is already flexible enough to handle variations
        $search_terms = $original_search_terms;

        // Build search conditions with LIKE (works for all term lengths)
        // MySQL FULLTEXT has minimum word length (typically 3-4 chars), so we use LIKE for all terms
        // FULLTEXT is only used in relevance scoring (ORDER BY), not in WHERE clause
        $like_conditions = [];
        
        foreach ($search_terms as $term) {
            $term_lower = strtolower($term);
            $like_pattern = '%' . $term_lower . '%';
            
            // LIKE conditions for all terms (handles short terms, partial matches, plurals)
            $like_conditions[] = "(
                LOWER(i.name) LIKE ? OR
                LOWER(i.description) LIKE ? OR
                LOWER(m.name) LIKE ? OR
                LOWER(vm.model_name) LIKE ? OR
                LOWER(vm.variant) LIKE ?
            )";
            // Add same pattern for all 5 LIKE conditions
            $search_params = array_merge($search_params, [$like_pattern, $like_pattern, $like_pattern, $like_pattern, $like_pattern]);
            
            // Note: FULLTEXT conditions are NOT added to WHERE clause parameters
            // FULLTEXT is only used in relevance scoring (ORDER BY), not in WHERE clause
            // FULLTEXT parameters will be added separately in the relevance scoring section
        }
        
        // Use LIKE for all terms (works for both short and long terms, handles plurals)
        // FULLTEXT is used only in relevance scoring, not in WHERE clause
        $search_conditions = $like_conditions;
    }

    // Build WHERE clause
    $where_conditions = [];
    // $search_params contains all WHERE clause search parameters
    // We'll add filter params to this, then add relevance params for ORDER BY if needed
    $where_params = $search_params;

    // Add search conditions
    if (!empty($search_conditions)) {
        $where_conditions[] = '(' . implode(' OR ', $search_conditions) . ')';
    }

    // Add filter conditions
    if ($manufacturer_id !== null) {
        $where_conditions[] = 'm.id = ?';
        $where_params[] = $manufacturer_id;
    }

    if ($category_id !== null) {
        // Get cached category tree and find all descendants
        $category_tree = getCategoryTree();
        $descendant_ids = $category_tree[$category_id] ?? [$category_id];

        if (!empty($descendant_ids)) {
            $placeholders = implode(',', array_fill(0, count($descendant_ids), '?'));
            $where_conditions[] = "ic.category_id IN ({$placeholders})";
            $where_params = array_merge($where_params, $descendant_ids);
        }
    }

    if ($model_id !== null) {
        $where_conditions[] = 'vm.id = ?';
        $where_params[] = $model_id;
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Initialize relevance parameter count
    $relevance_param_count = 0;

    // Build ORDER BY clause
    $order_clause = '';
    switch ($sort) {
        case 'price_asc':
            $order_clause = 'ORDER BY COALESCE(i.sale_price, i.price) ASC, i.name ASC';
            break;
        case 'price_desc':
            $order_clause = 'ORDER BY COALESCE(i.sale_price, i.price) DESC, i.name ASC';
            break;
        case 'relevance':
        default:
            // Calculate enhanced relevance score with synonym and fuzzy matching awareness
            if (!empty($search_terms)) {
                $relevance_conditions = [];
                $relevance_params = [];

                // Get synonyms cache for relevance scoring
                $synonyms = getSynonymsCache();
                $relevance_param_count = 0;

                foreach ($search_terms as $term) {
                    $term_lower = strtolower($term);
                    $like_pattern = '%' . $term_lower . '%';

                    // Base LIKE matching scores (works for all terms, handles plurals and partial matches)
                    $relevance_conditions[] = "CASE WHEN LOWER(i.name) LIKE ? THEN 10 ELSE 0 END"; // Item name exact/partial - highest
                    $relevance_conditions[] = "CASE WHEN LOWER(i.description) LIKE ? THEN 8 ELSE 0 END"; // Item description - high
                    $relevance_conditions[] = "CASE WHEN LOWER(m.name) LIKE ? THEN 6 ELSE 0 END"; // Manufacturer - medium
                    $relevance_conditions[] = "CASE WHEN LOWER(vm.model_name) LIKE ? OR LOWER(vm.variant) LIKE ? THEN 4 ELSE 0 END"; // Model - lower
                    
                    $relevance_params = array_merge($relevance_params, [$like_pattern, $like_pattern, $like_pattern, $like_pattern, $like_pattern]);
                    $relevance_param_count += 5;

                    // FULLTEXT matching only for terms >= 3 characters
                    if (strlen($term) >= 3) {
                        $search_term = $term . '*'; // Wildcard only at end (MySQL requirement)

                        // FULLTEXT matching scores (additional boost for longer terms)
                        $relevance_conditions[] = "CASE WHEN MATCH(i.name, i.description) AGAINST(? IN BOOLEAN MODE) THEN 5 ELSE 0 END"; // Additional boost
                        $relevance_conditions[] = "CASE WHEN MATCH(m.name) AGAINST(? IN BOOLEAN MODE) THEN 3 ELSE 0 END";
                        $relevance_conditions[] = "CASE WHEN MATCH(vm.model_name, vm.variant) AGAINST(? IN BOOLEAN MODE) THEN 2 ELSE 0 END";

                        $relevance_params = array_merge($relevance_params, [$search_term, $search_term, $search_term]);
                        $relevance_param_count += 3;

                        // Synonym matching (lower weight, only for longer terms)
                        $synonym_terms = isset($synonyms[$term]) ? array_keys($synonyms[$term]) : [];
                        if (!empty($synonym_terms)) {
                            foreach ($synonym_terms as $synonym) {
                                if (strlen($synonym) >= 3) {
                                    $synonym_term = $synonym . '*';
                                    $weight = isset($synonyms[$term][$synonym]) ? $synonyms[$term][$synonym] : 0.8;
                                    $synonym_score = (int)($weight * 3); // Scale synonym scores

                                    $relevance_conditions[] = "CASE WHEN MATCH(i.name, i.description) AGAINST(? IN BOOLEAN MODE) THEN {$synonym_score} ELSE 0 END";
                                    $relevance_conditions[] = "CASE WHEN MATCH(m.name) AGAINST(? IN BOOLEAN MODE) THEN " . (int)($weight * 2) . " ELSE 0 END";
                                    $relevance_conditions[] = "CASE WHEN MATCH(vm.model_name, vm.variant) AGAINST(? IN BOOLEAN MODE) THEN " . (int)($weight * 1) . " ELSE 0 END";

                                    $relevance_params = array_merge($relevance_params, [$synonym_term, $synonym_term, $synonym_term]);
                                    $relevance_param_count += 3;
                                }
                            }
                        }

                        // Fuzzy matching (lowest weight, only for longer terms)
                        $fuzzy_terms = findSimilarTerms($term, 1); // Only very close matches
                        foreach ($fuzzy_terms as $fuzzy_term) {
                            if (strlen($fuzzy_term) >= 3) {
                                $fuzzy_search_term = $fuzzy_term . '*';

                                $relevance_conditions[] = "CASE WHEN MATCH(i.name, i.description) AGAINST(? IN BOOLEAN MODE) THEN 2 ELSE 0 END";
                                $relevance_conditions[] = "CASE WHEN MATCH(m.name) AGAINST(? IN BOOLEAN MODE) THEN 1 ELSE 0 END";
                                $relevance_conditions[] = "CASE WHEN MATCH(vm.model_name, vm.variant) AGAINST(? IN BOOLEAN MODE) THEN 1 ELSE 0 END";

                                $relevance_params = array_merge($relevance_params, [$fuzzy_search_term, $fuzzy_search_term, $fuzzy_search_term]);
                                $relevance_param_count += 3;
                            }
                        }
                    }
                }

                // Create a subquery for relevance score calculation
                if (!empty($relevance_conditions)) {
                    $relevance_subquery = '(' . implode(' + ', $relevance_conditions) . ')';
                    $order_clause = 'ORDER BY ' . $relevance_subquery . ' DESC, i.name ASC';
                } else {
                    $order_clause = 'ORDER BY i.name ASC';
                }
            } else {
                $order_clause = 'ORDER BY i.name ASC';
            }
            break;
    }

    // Combine WHERE params and relevance params for main query
    // WHERE params first (WHERE clause comes before ORDER BY in SQL), then relevance params
    $params = array_merge($where_params, $relevance_params);

    // First, get total count for pagination
    $count_sql = "
        SELECT COUNT(DISTINCT i.id) as total
        FROM items i
        LEFT JOIN item_vehicle_models ivm ON i.id = ivm.item_id
        LEFT JOIN vehicle_models vm ON ivm.vehicle_model_id = vm.id
        LEFT JOIN manufacturers m ON vm.manufacturer_id = m.id
        LEFT JOIN item_category ic ON i.id = ic.item_id
        {$where_clause}
    ";

    // For count query, we only need WHERE parameters (relevance params are only for ORDER BY)
    $count_params = $where_params;

    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($count_params);
    $total_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_items = (int)$total_result['total'];
    $total_pages = ceil($total_items / $page_size);

    // Build main query with pagination
    $sql = "
        SELECT DISTINCT
            i.id,
            i.name,
            i.description,
            i.sku,
            i.is_universal,
            i.price,
            i.discount,
            i.sale_price,
            i.lead_time,
            i.created_at,
            i.updated_at,
            (
                SELECT src
                FROM item_images ii
                WHERE ii.item_id = i.id
                ORDER BY ii.id ASC
                LIMIT 1
            ) AS image_url
        FROM items i
        LEFT JOIN item_vehicle_models ivm ON i.id = ivm.item_id
        LEFT JOIN vehicle_models vm ON ivm.vehicle_model_id = vm.id
        LEFT JOIN manufacturers m ON vm.manufacturer_id = m.id
        LEFT JOIN item_category ic ON i.id = ic.item_id
        {$where_clause}
        GROUP BY i.id
        {$order_clause}
        LIMIT {$page_size} OFFSET {$offset}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        // Log no-results search for analytics
        $search_params = [
            'q' => $q,
            'manufacturer_id' => $manufacturer_id,
            'category_id' => $category_id,
            'model_id' => $model_id,
            'sort' => $sort,
            'page' => $page,
            'page_size' => $page_size
        ];
        logSearchAttempt($search_params, 0);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No items found matching your search criteria.',
            'data' => [],
            'count' => 0,
            'search_params' => $search_params
        ]);
        exit;
    }

    // Get item IDs for additional data fetching
    $itemIds = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    // Fetch supported models for each item
    $stmtModels = $pdo->prepare("
        SELECT
            ivm.item_id,
            vm.id AS vehicle_model_id,
            vm.model_name,
            vm.variant,
            vm.year_from,
            vm.year_to,
            m.id AS manufacturer_id,
            m.name AS manufacturer_name
        FROM item_vehicle_models ivm
        INNER JOIN vehicle_models vm ON ivm.vehicle_model_id = vm.id
        INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
        WHERE ivm.item_id IN ({$placeholders})
        ORDER BY m.name ASC, vm.model_name ASC
    ");
    $stmtModels->execute($itemIds);
    $links = $stmtModels->fetchAll(PDO::FETCH_ASSOC);

    $modelsByItem = [];
    foreach ($links as $link) {
        $itemId = $link['item_id'];
        unset($link['item_id']);
        $modelsByItem[$itemId][] = $link;
    }

    // Fetch categories for items
    $stmtCategories = $pdo->prepare("
        SELECT
            ic.item_id,
            c.id AS category_id,
            c.name AS category_name
        FROM item_category ic
        INNER JOIN categories c ON ic.category_id = c.id
        WHERE ic.item_id IN ({$placeholders})
        ORDER BY ic.item_id ASC, c.name ASC
    ");
    $stmtCategories->execute($itemIds);
    $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

    $categoriesByItem = [];
    foreach ($categories as $category) {
        $itemId = $category['item_id'];
        unset($category['item_id']);
        $categoriesByItem[$itemId][] = $category;
    }

    // Format items with related data
    $formatted_items = [];
    foreach ($items as $item) {
        $itemId = $item['id'];
        $formatted_items[] = [
            'id' => (int)$item['id'],
            'name' => $item['name'],
            'description' => $item['description'] ? $item['description'] : null,
            'sku' => $item['sku'] ? $item['sku'] : null,
            'is_universal' => (bool)$item['is_universal'],
            'price' => $item['price'] ? (float)$item['price'] : null,
            'discount' => $item['discount'] ? (float)$item['discount'] : null,
            'sale_price' => $item['sale_price'] ? (float)$item['sale_price'] : null,
            'lead_time' => $item['lead_time'] ?: null,
            'image_url' => $item['image_url'] ? $item['image_url'] : null,
            'supported_models' => $modelsByItem[$itemId] ?? [],
            'categories' => $categoriesByItem[$itemId] ?? [],
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at']
        ];
    }

    // Log successful search for analytics
    $search_params = [
        'q' => $q,
        'manufacturer_id' => $manufacturer_id,
        'category_id' => $category_id,
        'model_id' => $model_id,
        'sort' => $sort,
        'page' => $page,
        'page_size' => $page_size
    ];
    logSearchAttempt($search_params, count($formatted_items));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Items found successfully.',
        'data' => $formatted_items,
        'pagination' => [
            'current_page' => $page,
            'page_size' => $page_size,
            'total_items' => $total_items,
            'total_pages' => $total_pages
        ],
        'search_params' => $search_params
    ]);

} catch (PDOException $e) {
    logException('mobile_item_search', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while searching items. Please try again later.',
        'error_details' => 'Error searching items: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_item_search', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error searching items: ' . $e->getMessage()
    ]);
}
?>
