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
 * Send Email to User Endpoint
 * POST /part_find_requests/send_email.php
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
 if (!checkUserPermission($userId, 'parts finder', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to uipdate part finds.']);
     exit;
 }

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['request_id']) || empty($input['request_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Request ID is required.']);
    exit;
}

if (!isset($input['message']) || empty($input['message'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message is required.']);
    exit;
}

$request_id = $input['request_id'];
$email_message = $input['message'];
$subject = $input['subject'] ?? 'Part Find Request Update';

try {
    // Get request and user details
    $stmt = $pdo->prepare("
        SELECT 
            pfr.id,
            pfr.user_id,
            pfr.message as request_message,
            pfr.status,
            u.name as user_name,
            u.surname as user_surname,
            u.email as user_email
        FROM part_find_requests pfr
        INNER JOIN users u ON pfr.user_id = u.id
        WHERE pfr.id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();

    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Part find request not found.']);
        exit;
    }

    if (empty($request['user_email'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User email not found.']);
        exit;
    }

    // Prepare email
    $to = $request['user_email'];
    $user_full_name = $request['user_name'] . ' ' . $request['user_surname'];
    
    // Email headers
    $headers = [
        'From: noreply@apetrape.com',
        'Reply-To: noreply@apetrape.com',
        'X-Mailer: PHP/' . phpversion(),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8'
    ];

    // Email body
    $email_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background-color: #f9f9f9; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Part Find Request Update</h2>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($user_full_name) . ",</p>
                <p>" . nl2br(htmlspecialchars($email_message)) . "</p>
                <hr>
                <p><strong>Your Original Request:</strong></p>
                <p>" . nl2br(htmlspecialchars($request['request_message'])) . "</p>
                <p><strong>Status:</strong> " . htmlspecialchars(ucfirst($request['status'])) . "</p>
            </div>
            <div class='footer'>
                <p>This is an automated message from Apetrape Management System.</p>
                <p>Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    // Send email
    $mail_sent = mail($to, $subject, $email_body, implode("\r\n", $headers));

    if ($mail_sent) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Email sent successfully.',
            'data' => [
                'request_id' => $request_id,
                'user_email' => $to,
                'user_name' => $user_full_name,
                'subject' => $subject
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send email. Please check your server email configuration.'
        ]);
    }

} catch (PDOException $e) {
    logException('part_find_send_email', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error sending email: ' . $e->getMessage()
    ]);
}
?>

