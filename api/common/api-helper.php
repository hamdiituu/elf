<?php
/**
 * Common API Helper Functions
 * Provides reusable functions for API endpoints
 */

// Prevent multiple includes
if (defined('API_HELPER_LOADED')) {
    return;
}
define('API_HELPER_LOADED', true);

/**
 * HTTP Method Constants
 */
class HttpMethod {
    const GET = 'GET';
    const POST = 'POST';
    const PUT = 'PUT';
    const DELETE = 'DELETE';
    const PATCH = 'PATCH';
    const OPTIONS = 'OPTIONS';
    const HEAD = 'HEAD';
}

/**
 * Set common API headers
 * @param array $allowedMethods Allowed HTTP methods (e.g., [HttpMethod::POST, HttpMethod::GET])
 */
function setApiHeaders($allowedMethods = [HttpMethod::GET, HttpMethod::POST]) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // Handle OPTIONS request for CORS preflight
    if ($_SERVER['REQUEST_METHOD'] === HttpMethod::OPTIONS) {
        http_response_code(200);
        exit;
    }
}

/**
 * Validate HTTP method
 * @param array $allowedMethods Allowed HTTP methods
 * @throws Exception if method not allowed
 */
function validateMethod($allowedMethods) {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if (!in_array($method, $allowedMethods)) {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed. Allowed methods: ' . implode(', ', $allowedMethods)
        ]);
        exit;
    }
}

/**
 * Get request body as JSON
 * @return array Parsed JSON body or empty array
 */
function getJsonBody() {
    $body_raw = file_get_contents('php://input');
    
    if (empty($body_raw)) {
        return [];
    }
    
    // Try to parse as JSON
    $json = json_decode($body_raw, true);
    
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        return $json;
    }
    
    // If not JSON, try form-encoded
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/x-www-form-urlencoded') !== false) {
        parse_str($body_raw, $result);
        return $result;
    }
    
    // Return empty array if parsing fails
    return [];
}

/**
 * Send JSON response
 * @param mixed $data Response data
 * @param int $statusCode HTTP status code (default: 200)
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 * @param string $message Error message
 * @param int $statusCode HTTP status code (default: 400)
 */
function sendErrorResponse($message, $statusCode = 400) {
    sendJsonResponse([
        'success' => false,
        'message' => $message
    ], $statusCode);
}

/**
 * Send success response
 * @param mixed $data Response data
 * @param string $message Success message
 * @param int $statusCode HTTP status code (default: 200)
 */
function sendSuccessResponse($data = [], $message = 'Success', $statusCode = 200) {
    $response = [
        'success' => true,
        'message' => $message
    ];
    
    if (!empty($data)) {
        $response['data'] = $data;
    }
    
    sendJsonResponse($response, $statusCode);
}

/**
 * Get required parameter from body or query
 * @param string $key Parameter key
 * @param bool $fromBody Get from body (true) or query (false), default: true
 * @return mixed Parameter value
 * @throws Exception if parameter is missing
 */
function getRequiredParam($key, $fromBody = true) {
    if ($fromBody) {
        $body = getJsonBody();
        $value = $body[$key] ?? $_POST[$key] ?? null;
    } else {
        $value = $_GET[$key] ?? null;
    }
    
    if ($value === null || (is_string($value) && trim($value) === '')) {
        sendErrorResponse("Required parameter '{$key}' is missing", 400);
    }
    
    return is_string($value) ? trim($value) : $value;
}

/**
 * Get optional parameter from body or query
 * @param string $key Parameter key
 * @param mixed $default Default value if not found
 * @param bool $fromBody Get from body (true) or query (false), default: true
 * @return mixed Parameter value or default
 */
function getOptionalParam($key, $default = null, $fromBody = true) {
    if ($fromBody) {
        $body = getJsonBody();
        $value = $body[$key] ?? $_POST[$key] ?? $default;
    } else {
        $value = $_GET[$key] ?? $default;
    }
    
    if ($value === null) {
        return $default;
    }
    
    return is_string($value) ? trim($value) : $value;
}

/**
 * Get current HTTP method
 * @return string Current HTTP method
 */
function getCurrentMethod() {
    return $_SERVER['REQUEST_METHOD'];
}

/**
 * Check if current method matches
 * @param string $method Method to check
 * @return bool True if matches
 */
function isMethod($method) {
    return $_SERVER['REQUEST_METHOD'] === strtoupper($method);
}

/**
 * Handle multiple HTTP methods in one API
 * Usage:
 * handleMethod([
 *     'GET' => function() { ... },
 *     'POST' => function() { ... },
 *     'PUT' => function() { ... }
 * ]);
 * 
 * @param array $handlers Array of method => callback function
 */
function handleMethod($handlers) {
    $method = getCurrentMethod();
    $allowedMethods = array_keys($handlers);
    
    // Set headers with allowed methods
    setApiHeaders($allowedMethods);
    
    // Validate method
    if (!isset($handlers[$method])) {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed. Allowed methods: ' . implode(', ', $allowedMethods)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Execute handler for current method
    try {
        call_user_func($handlers[$method]);
    } catch (Exception $e) {
        sendErrorResponse($e->getMessage(), 500);
    }
}

/**
 * Get request body as array (JSON or form data)
 * @return array Request body data
 */
if (!function_exists('getRequestBody')) {
    function getRequestBody() {
        return getJsonBody();
    }
}

/**
 * Get all request headers
 * @return array Headers as key-value pairs
 */
if (!function_exists('getAllHeaders')) {
    function getAllHeaders() {
        $headers = [];
        
        // Use built-in function if available (Apache)
        if (function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            // Convert to lowercase keys for consistency
            foreach ($allHeaders as $key => $value) {
                $headers[$key] = $value;
            }
        } else {
            // Fallback for Nginx or other servers
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                    $headers[$header] = $value;
                }
            }
        }
        
        return $headers;
    }
}

/**
 * Ensure CORS is enabled
 */
if (!function_exists('ensureCors')) {
    function ensureCors() {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        
        if ($_SERVER['REQUEST_METHOD'] === HttpMethod::OPTIONS) {
            http_response_code(200);
            exit;
        }
    }
}
?>

