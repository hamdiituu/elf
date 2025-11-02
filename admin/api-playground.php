<?php
require_once '../config/config.php';
requireDeveloper();

$page_title = 'API Playground';

$db = getDB();

// Ensure cloud_functions table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS cloud_functions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT,
        code TEXT NOT NULL,
        http_method TEXT NOT NULL DEFAULT 'POST',
        endpoint TEXT NOT NULL UNIQUE,
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INTEGER,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");
} catch (PDOException $e) {
    // Table might already exist
}

// Get cloud functions from database (with grouping)
$cloud_functions = [];
try {
    // Check if function_group column exists
    $settings = getSettings();
    $dbType = $settings['db_type'] ?? 'sqlite';
    
    if ($dbType === 'sqlite') {
        $stmt = $db->query("PRAGMA table_info(cloud_functions)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $column_names = array_column($columns, 'name');
        $has_group = in_array('function_group', $column_names);
    } else {
        try {
            $db->query("SELECT function_group FROM cloud_functions LIMIT 1");
            $has_group = true;
        } catch (PDOException $e) {
            $has_group = false;
        }
    }
    
    if ($has_group) {
        $cloud_functions = $db->query("SELECT * FROM cloud_functions WHERE enabled = 1 ORDER BY function_group ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $cloud_functions = $db->query("SELECT * FROM cloud_functions WHERE enabled = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Fallback to basic query
    $cloud_functions = $db->query("SELECT * FROM cloud_functions WHERE enabled = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// Define available APIs
$apis = [];

// Group cloud functions by function_group
$grouped_functions = [];
$ungrouped_functions = [];

foreach ($cloud_functions as $cf) {
    $group = $cf['function_group'] ?? '';
    $group = trim($group);
    
    $api_item = [
        'id' => 'cloud-function-' . $cf['id'],
        'name' => $cf['name'],
        'full_name' => $cf['name'] . ' (Cloud Function)',
        'description' => $cf['description'] ?: 'Cloud Function',
        'methods' => [strtoupper($cf['http_method'])],
        'endpoint' => '../api/cloud-functions/execute.php?function=' . urlencode($cf['name']),
        'parameters' => [
            ['name' => 'function', 'type' => 'string', 'required' => true, 'description' => 'Function name (already set in endpoint)'],
            ['name' => '...', 'type' => 'any', 'required' => false, 'description' => 'Additional parameters as defined in the function code']
        ],
        'is_cloud_function' => true,
        'cloud_function_id' => $cf['id'],
        'cloud_function_name' => $cf['name'],
        'function_group' => $group
    ];
    
    if (!empty($group)) {
        if (!isset($grouped_functions[$group])) {
            $grouped_functions[$group] = [];
        }
        $grouped_functions[$group][] = $api_item;
    } else {
        $ungrouped_functions[] = $api_item;
    }
}

// Sort groups alphabetically
ksort($grouped_functions);

// Build APIs array: first grouped functions, then ungrouped
foreach ($grouped_functions as $group => $group_apis) {
    $apis[] = [
        'id' => 'group-header-' . md5($group),
        'name' => $group,
        'full_name' => $group,
        'description' => '',
        'methods' => [],
        'endpoint' => '',
        'parameters' => [],
        'is_group_header' => true,
        'function_group' => $group
    ];
    foreach ($group_apis as $api_item) {
        $apis[] = $api_item;
    }
}

// Add ungrouped functions
foreach ($ungrouped_functions as $api_item) {
    $apis[] = $api_item;
}

include '../includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>

<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto bg-muted/30">
        <div class="py-6">
            <div class="mx-auto max-w-[1600px] px-4 sm:px-6 md:px-8">
                <!-- Header -->
                <div class="mb-8">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold text-foreground">API Playground</h1>
                            <p class="mt-2 text-sm text-muted-foreground">Test and debug your APIs in real-time</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="inline-flex items-center rounded-full bg-green-100 px-3 py-1.5 text-xs font-medium text-green-800">
                                <span class="relative flex h-2 w-2 mr-2">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                                </span>
                                <?php echo count($apis); ?> API<?php echo count($apis) !== 1 ? 's' : ''; ?> Available
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- API List -->
                    <div class="lg:col-span-1">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm sticky top-6">
                            <div class="p-4 border-b border-border">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight">Available APIs</h3>
                                    <button onclick="copyAllEndpoints()" class="text-xs text-muted-foreground hover:text-foreground transition-colors" title="Copy all endpoints">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                        </svg>
                                    </button>
                                </div>
                                <input 
                                    type="text" 
                                    id="api-search"
                                    placeholder="Search APIs..."
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    oninput="filterAPIs(this.value)"
                                >
                            </div>
                            <div class="p-4">
                                <div class="space-y-2 max-h-[calc(100vh-300px)] overflow-y-auto" id="api-list">
                                    <?php if (empty($apis)): ?>
                                        <div class="text-center py-12 text-muted-foreground">
                                            <svg class="mx-auto h-12 w-12 mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                            <p class="text-sm">No APIs available</p>
                                            <p class="text-xs mt-1">Create a cloud function to get started</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($apis as $api): ?>
                                            <?php if (isset($api['is_group_header']) && $api['is_group_header']): ?>
                                                <!-- Group Header -->
                                                <div class="py-2 px-3 bg-muted/50 border-b border-border">
                                                    <h4 class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                                        <?php echo htmlspecialchars($api['name']); ?>
                                                    </h4>
                                                </div>
                                            <?php else: ?>
                                                <div 
                                                    class="p-3 rounded-lg border border-border hover:border-primary/50 cursor-pointer transition-all api-item group <?php echo (isset($api['is_cloud_function']) && $api['is_cloud_function']) ? 'bg-gradient-to-br from-purple-50/50 to-blue-50/50 border-purple-200' : 'bg-background hover:bg-muted/50'; ?>"
                                                    data-api-id="<?php echo htmlspecialchars($api['id']); ?>"
                                                    data-api-name="<?php echo htmlspecialchars(strtolower($api['name'])); ?>"
                                                    data-api-desc="<?php echo htmlspecialchars(strtolower($api['description'])); ?>"
                                                >
                                                    <div class="flex items-start justify-between gap-2">
                                                        <div class="flex-1 min-w-0">
                                                            <div class="flex items-center gap-2 mb-2 flex-wrap">
                                                                <?php foreach ($api['methods'] as $method): 
                                                                    $methodColors = [
                                                                        'GET' => 'bg-blue-500 text-white',
                                                                        'POST' => 'bg-green-500 text-white',
                                                                        'PUT' => 'bg-yellow-500 text-white',
                                                                        'DELETE' => 'bg-red-500 text-white',
                                                                        'PATCH' => 'bg-purple-500 text-white'
                                                                    ];
                                                                    $colorClass = $methodColors[$method] ?? 'bg-gray-500 text-white';
                                                                ?>
                                                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold <?php echo $colorClass; ?>">
                                                                        <?php echo $method; ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                                <?php if (isset($api['is_cloud_function']) && $api['is_cloud_function']): ?>
                                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-800">
                                                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                                            <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"></path>
                                                                            <path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"></path>
                                                                        </svg>
                                                                        Cloud
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <h4 class="font-semibold text-sm text-foreground truncate group-hover:text-primary transition-colors">
                                                                <?php echo htmlspecialchars($api['name']); ?>
                                                            </h4>
                                                            <p class="text-xs text-muted-foreground mt-1 line-clamp-2">
                                                                <?php echo htmlspecialchars($api['description']); ?>
                                                            </p>
                                                            <p class="text-xs text-muted-foreground mt-2 font-mono truncate opacity-75">
                                                                <?php echo htmlspecialchars($api['endpoint']); ?>
                                                            </p>
                                                        </div>
                                                        <svg class="w-5 h-5 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                        </svg>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Request & Response Panel -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Request Panel -->
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6 border-b border-border bg-muted/30">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight">Request</h3>
                                    <button onclick="clearRequest()" class="text-xs text-muted-foreground hover:text-foreground transition-colors">
                                        Clear
                                    </button>
                                </div>
                            </div>
                            <div class="p-6">
                                <div id="api-request-panel">
                                    <div class="text-center py-16 text-muted-foreground">
                                        <svg class="mx-auto h-16 w-16 mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <p class="text-base font-medium">Select an API to get started</p>
                                        <p class="text-xs mt-1">Choose an API from the left panel to configure and test</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Response Panel -->
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6 border-b border-border bg-muted/30">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight">Response</h3>
                                    <button onclick="copyResponse()" id="copy-response-btn" class="text-xs text-muted-foreground hover:text-foreground transition-colors opacity-0 hidden">
                                        Copy
                                    </button>
                                </div>
                            </div>
                            <div class="p-6">
                                <div id="api-response" class="min-h-[200px]">
                                    <div class="text-center py-16 text-muted-foreground">
                                        <svg class="mx-auto h-16 w-16 mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <p class="text-base font-medium">Response will appear here</p>
                                        <p class="text-xs mt-1">Send a request to see the response</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const apis = <?php echo json_encode($apis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?>;
let currentApiId = null;
let currentResponse = null;

// Store APIs data
const apiData = {};
apis.forEach(api => {
    apiData[api.id] = api;
});

// Handle API selection
document.querySelectorAll('.api-item').forEach(item => {
    item.addEventListener('click', function() {
        const apiId = this.dataset.apiId;
        selectAPI(apiId);
    });
});

function selectAPI(apiId) {
    const api = apiData[apiId];
    if (!api) return;
    
    // Skip group headers
    if (api.is_group_header) return;
    
    currentApiId = apiId;
    
    // Remove active class from all items
    document.querySelectorAll('.api-item').forEach(i => {
        i.classList.remove('border-primary', 'bg-primary/5', 'shadow-md');
    });
    
    // Add active class to selected item
    const selectedItem = document.querySelector(`[data-api-id="${apiId}"]`);
    if (selectedItem) {
        selectedItem.classList.add('border-primary', 'bg-primary/5', 'shadow-md');
        selectedItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    
    // Generate request panel
    generateRequestPanel(api);
}

// Auto-select API from URL parameter
const urlParams = new URLSearchParams(window.location.search);
const apiIdParam = urlParams.get('api_id');
if (apiIdParam) {
    setTimeout(() => selectAPI(apiIdParam), 100);
}

function filterAPIs(searchTerm) {
    const term = searchTerm.toLowerCase().trim();
    const items = document.querySelectorAll('.api-item');
    
    items.forEach(item => {
        const name = item.dataset.apiName || '';
        const desc = item.dataset.apiDesc || '';
        
        if (term === '' || name.includes(term) || desc.includes(term)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function copyAllEndpoints() {
    const endpoints = apis.map(api => api.endpoint).join('\n');
    navigator.clipboard.writeText(endpoints).then(() => {
        const btn = event.target.closest('button');
        const original = btn.innerHTML;
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
        setTimeout(() => btn.innerHTML = original, 2000);
    });
}

function clearRequest() {
    if (currentApiId) {
        generateRequestPanel(apiData[currentApiId]);
    } else {
        document.getElementById('api-request-panel').innerHTML = `
            <div class="text-center py-16 text-muted-foreground">
                <svg class="mx-auto h-16 w-16 mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <p class="text-base font-medium">Select an API to get started</p>
                <p class="text-xs mt-1">Choose an API from the left panel to configure and test</p>
            </div>
        `;
    }
    document.getElementById('api-response').innerHTML = `
        <div class="text-center py-16 text-muted-foreground">
            <svg class="mx-auto h-16 w-16 mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <p class="text-base font-medium">Response will appear here</p>
            <p class="text-xs mt-1">Send a request to see the response</p>
        </div>
    `;
    currentResponse = null;
    document.getElementById('copy-response-btn').classList.add('opacity-0', 'hidden');
}

function copyResponse() {
    if (currentResponse) {
        navigator.clipboard.writeText(currentResponse);
        const btn = document.getElementById('copy-response-btn');
        const original = btn.innerHTML;
        btn.innerHTML = 'Copied!';
        setTimeout(() => btn.innerHTML = original, 2000);
    }
}

function generateRequestPanel(api) {
    const panel = document.getElementById('api-request-panel');
    
    const methodColors = {
        'GET': 'bg-blue-500 text-white',
        'POST': 'bg-green-500 text-white',
        'PUT': 'bg-yellow-500 text-white',
        'DELETE': 'bg-red-500 text-white',
        'PATCH': 'bg-purple-500 text-white'
    };
    
    let methodsHtml = api.methods.map(method => {
        const colorClass = methodColors[method] || 'bg-gray-500 text-white';
        return `<option value="${method}">${method}</option>`;
    }).join('');
    
    let paramsHtml = '';
    if (api.parameters && api.parameters.length > 0) {
        paramsHtml = api.parameters.map(param => `
            <div class="mb-4">
                <label class="block text-sm font-medium text-foreground mb-2">
                    ${param.name === '...' ? 'Additional Parameters' : param.name}
                    ${param.required ? '<span class="text-red-500 ml-1">*</span>' : ''}
                    <span class="text-xs text-muted-foreground ml-2 font-normal">(${param.type})</span>
                </label>
                <input 
                    type="text" 
                    data-param="${param.name}"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                    placeholder="${param.description || param.name}"
                    ${param.required ? 'required' : ''}
                >
                ${param.description && param.name !== '...' ? `<p class="mt-1 text-xs text-muted-foreground">${param.description}</p>` : ''}
            </div>
        `).join('');
    }
    
    const isCloudFunction = api.is_cloud_function || false;
    
    panel.innerHTML = `
        <div class="space-y-6">
            ${isCloudFunction ? `
                <div class="rounded-lg bg-gradient-to-r from-purple-50 to-blue-50 border border-purple-200 p-4">
                    <div class="flex items-start gap-3">
                        <div class="rounded-full bg-purple-100 p-2">
                            <svg class="h-5 w-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-purple-900">Cloud Function</p>
                            <p class="text-xs text-purple-700 mt-1">This is a dynamically created cloud function stored in the database.</p>
                        </div>
                    </div>
                </div>
            ` : ''}
            
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-2 uppercase tracking-wider">API Endpoint</label>
                <div class="flex items-center gap-2">
                    <code class="flex-1 rounded-md bg-muted px-3 py-2 text-sm font-mono text-foreground break-all">${api.endpoint}</code>
                    <button onclick="copyEndpoint('${api.endpoint}')" class="px-3 py-2 rounded-md bg-muted hover:bg-muted/80 transition-colors" title="Copy endpoint">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </button>
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-2 uppercase tracking-wider">HTTP Method</label>
                <select 
                    id="http-method"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-semibold text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                    onchange="updateRequestBody()"
                >
                    ${methodsHtml}
                </select>
            </div>
            
            ${api.parameters && api.parameters.length > 0 ? `
                <div>
                    <label class="block text-xs font-medium text-muted-foreground mb-3 uppercase tracking-wider">Parameters</label>
                    ${paramsHtml}
                </div>
            ` : ''}
            
            <div class="mb-4" id="body-section" style="display: none;">
                <label class="block text-xs font-medium text-muted-foreground mb-2 uppercase tracking-wider">Request Body (JSON)</label>
                <textarea 
                    id="request-body"
                    rows="8"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                    placeholder='{\n  "key": "value"\n}'
                ></textarea>
                <p class="mt-2 text-xs text-muted-foreground">Enter JSON object for POST/PUT/DELETE/PATCH requests</p>
            </div>
            
            <div class="mb-4" id="headers-section">
                <details class="group">
                    <summary class="cursor-pointer text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">
                        Custom Headers (Optional)
                        <span class="ml-2 text-muted-foreground normal-case">Click to expand</span>
                    </summary>
                    <textarea 
                        id="custom-headers"
                        rows="4"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent mt-2"
                        placeholder='Authorization: Bearer token\nContent-Type: application/json\nX-API-Key: your-key'
                    ></textarea>
                    <p class="mt-2 text-xs text-muted-foreground">Enter headers as key: value (one per line)</p>
                </details>
            </div>
            
            <button 
                onclick="makeRequest('${api.id}')"
                id="send-request-btn"
                class="w-full rounded-md bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground hover:bg-primary/90 focus:visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all flex items-center justify-center gap-2"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                Send Request
            </button>
        </div>
    `;
    
    // Set default method
    if (api.methods.length > 0) {
        document.getElementById('http-method').value = api.methods[0];
        updateRequestBody();
    }
}

function copyEndpoint(endpoint) {
    navigator.clipboard.writeText(endpoint);
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.innerHTML = '<svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
    setTimeout(() => btn.innerHTML = original, 2000);
}

function updateRequestBody() {
    const method = document.getElementById('http-method')?.value;
    const bodySection = document.getElementById('body-section');
    
    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
        bodySection.style.display = 'block';
    } else {
        bodySection.style.display = 'none';
    }
}

async function makeRequest(apiId) {
    const api = apiData[apiId];
    const responseDiv = document.getElementById('api-response');
    const sendBtn = document.getElementById('send-request-btn');
    const method = document.getElementById('http-method')?.value || 'GET';
    
    // Disable button and show loading
    sendBtn.disabled = true;
    sendBtn.innerHTML = `
        <div class="inline-block animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent mr-2"></div>
        Sending...
    `;
    
    responseDiv.innerHTML = `
        <div class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-2 border-primary border-t-transparent mb-4"></div>
            <p class="text-sm font-medium text-foreground">Sending ${method} request...</p>
            <p class="text-xs text-muted-foreground mt-1">Please wait</p>
        </div>
    `;
    
    try {
        // Collect query parameters
        const queryParams = {};
        const paramInputs = document.querySelectorAll('[data-param]');
        paramInputs.forEach(input => {
            const name = input.dataset.param;
            const value = input.value.trim();
            if (value && name !== '...') {
                queryParams[name] = value;
            }
        });
        
        // Build URL
        let url = api.endpoint;
        if (method === 'GET' && Object.keys(queryParams).length > 0) {
            const queryString = new URLSearchParams(queryParams).toString();
            url += (url.includes('?') ? '&' : '?') + queryString;
        }
        
        // Prepare headers
        const headers = {
            'Content-Type': 'application/json'
        };
        
        // Parse custom headers
        const customHeadersText = document.getElementById('custom-headers')?.value || '';
        if (customHeadersText.trim()) {
            customHeadersText.split('\n').forEach(line => {
                const trimmed = line.trim();
                if (trimmed) {
                    const [key, ...valueParts] = trimmed.split(':');
                    if (key && valueParts.length > 0) {
                        headers[key.trim()] = valueParts.join(':').trim();
                    }
                }
            });
        }
        
        // Prepare request options
        const options = {
            method: method,
            headers: headers
        };
        
        // Add body for POST/PUT/DELETE/PATCH requests
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
            const bodyText = document.getElementById('request-body')?.value || '';
            if (bodyText.trim()) {
                try {
                    const bodyObj = JSON.parse(bodyText);
                    Object.assign(bodyObj, queryParams);
                    options.body = JSON.stringify(bodyObj);
                } catch (e) {
                    if (Object.keys(queryParams).length > 0) {
                        options.body = JSON.stringify(queryParams);
                    } else {
                        options.body = bodyText;
                    }
                }
            } else if (Object.keys(queryParams).length > 0) {
                options.body = JSON.stringify(queryParams);
            }
        }
        
        // Make request
        const startTime = Date.now();
        const response = await fetch(url, options);
        const endTime = Date.now();
        const duration = endTime - startTime;
        
        const data = await response.text();
        
        // Try to parse as JSON
        let formattedData;
        let isJson = false;
        try {
            formattedData = JSON.parse(data);
            isJson = true;
        } catch (e) {
            formattedData = data;
        }
        
        // Store response for copying
        currentResponse = isJson ? JSON.stringify(formattedData, null, 2) : data;
        
        // Get response headers
        const responseHeaders = {};
        response.headers.forEach((value, key) => {
            responseHeaders[key] = value;
        });
        
        // Display response
        const statusColor = response.ok ? 'green' : 'red';
        const statusBg = response.ok ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200';
        const statusText = response.ok ? 'text-green-800' : 'text-red-800';
        
        responseDiv.innerHTML = `
            <div class="space-y-4">
                <div class="rounded-lg ${statusBg} border ${statusColor === 'green' ? 'border-green-200' : 'border-red-200'} p-4">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold ${statusColor === 'green' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                                ${response.status} ${response.statusText}
                            </span>
                            <span class="text-xs ${statusText} font-medium">${duration}ms</span>
                            <span class="text-xs text-muted-foreground">${new Date().toLocaleTimeString()}</span>
                        </div>
                    </div>
                    <details class="mt-3">
                        <summary class="cursor-pointer text-xs font-medium ${statusText} hover:opacity-80">View Response Headers</summary>
                        <pre class="mt-2 rounded-md bg-white/50 p-3 overflow-x-auto font-mono text-xs">${JSON.stringify(responseHeaders, null, 2)}</pre>
                    </details>
                </div>
                <div class="rounded-lg bg-muted border border-border p-4 overflow-x-auto">
                    <pre class="text-sm font-mono text-foreground whitespace-pre-wrap"><code class="language-json">${isJson ? JSON.stringify(formattedData, null, 2) : formattedData}</code></pre>
                </div>
            </div>
        `;
        
        // Show copy button
        const copyBtn = document.getElementById('copy-response-btn');
        copyBtn.classList.remove('opacity-0', 'hidden');
        
        // Re-highlight JSON
        if (isJson) {
            Prism.highlightElement(responseDiv.querySelector('code'));
        }
        
    } catch (error) {
        currentResponse = error.message;
        responseDiv.innerHTML = `
            <div class="rounded-lg bg-red-50 border border-red-200 p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div class="flex-1">
                        <div class="text-sm font-semibold text-red-800 mb-1">Request Failed</div>
                        <div class="text-sm text-red-700">${error.message}</div>
                        <details class="mt-3">
                            <summary class="cursor-pointer text-xs text-red-600 hover:text-red-800">Stack Trace</summary>
                            <pre class="mt-2 text-xs font-mono bg-white/50 p-2 rounded">${error.stack || 'No stack trace available'}</pre>
                        </details>
                    </div>
                </div>
            </div>
        `;
        document.getElementById('copy-response-btn').classList.remove('opacity-0', 'hidden');
    } finally {
        // Re-enable button
        sendBtn.disabled = false;
        sendBtn.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            Send Request
        `;
    }
}
</script>

<?php include '../includes/footer.php'; ?>
