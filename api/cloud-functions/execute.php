<?php
/**
 * Cloud Functions Execution Endpoint
 * Executes cloud functions via REST API
 */

require_once __DIR__ . '/../common/api-helper.php';
require_once __DIR__ . '/../../config/config.php';

ensureCors();

// Get function name from endpoint or request body
$function_name = $_GET['function'] ?? $_POST['function'] ?? null;

if (!$function_name) {
    // Try to get from URL path: /api/cloud-functions/execute.php?function=my-function
    $path_parts = explode('/', trim($_SERVER['REQUEST_URI'] ?? '', '/'));
    foreach ($path_parts as $part) {
        if ($part !== 'api' && $part !== 'cloud-functions' && $part !== 'execute.php') {
            $function_name = $part;
            break;
        }
    }
}

// Also check POST body for function name
if (!$function_name) {
    $body = getRequestBody();
    if (isset($body['function'])) {
        $function_name = $body['function'];
    }
}

if (!$function_name) {
    sendErrorResponse('Function name is required. Provide ?function=function-name or in request body.', 400);
}

// Get function from database
$db = getDB();
$stmt = $db->prepare("SELECT * FROM cloud_functions WHERE name = ? AND enabled = 1 LIMIT 1");
$stmt->execute([$function_name]);
$function = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$function) {
    sendErrorResponse("Function '{$function_name}' not found or disabled.", 404);
}

// Check HTTP method
$current_method = getCurrentMethod();
if (strtoupper($function['http_method']) !== $current_method) {
    sendErrorResponse("Method not allowed. This function requires {$function['http_method']}, got {$current_method}.", 405);
}

// Set appropriate headers based on function's HTTP method
setApiHeaders([$function['http_method']]);

// Prepare execution environment
$dbContext = $db; // Make database available as $dbContext
$request = getRequestBody(); // Request data (empty array for GET)
$method = $current_method; // HTTP method
$headers = getAllHeaders(); // Request headers
$response = ['success' => false, 'data' => null, 'message' => '', 'error' => null];

// Execute function code in isolated scope
try {
    // Create a sandbox function
    $executeFunction = function($code, $dbContext, $request, $method, $headers, &$response) {
        // These variables will be available in the function code
        // $dbContext is the database connection (PDO)
        // $db is also available as alias for $dbContext
        $db = $dbContext;
        
        // Execute the code
        eval($code);
        
        return $response;
    };
    
    // Execute with timeout
    set_time_limit(30); // Max 30 seconds execution time
    $result = $executeFunction($function['code'], $dbContext, $request, $method, $headers, $response);
    
    // Always return success response (200), never 500
    // If function sets success=false, we still return 200 with success=false in response body
    if (isset($result['success']) && $result['success']) {
        sendSuccessResponse($result['data'] ?? null, $result['message'] ?? 'Function executed successfully');
    } else {
        // Return 200 with success=false instead of 500
        sendJsonResponse([
            'success' => false,
            'message' => $result['error'] ?? $result['message'] ?? 'Function execution failed',
            'data' => $result['data'] ?? null
        ], 200);
    }
    
} catch (ParseError $e) {
    // Return 200 with error message instead of 500
    sendJsonResponse([
        'success' => false,
        'message' => 'Syntax error in function code: ' . $e->getMessage(),
        'error_type' => 'ParseError'
    ], 200);
} catch (Throwable $e) {
    // Return 200 with error message instead of 500
    sendJsonResponse([
        'success' => false,
        'message' => 'Error executing function: ' . $e->getMessage(),
        'error_type' => get_class($e)
    ], 200);
}
?>

