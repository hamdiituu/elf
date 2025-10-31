<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'API Playground - Stok Sayım Sistemi';

// Define available APIs
$apis = [
    [
        'id' => 'hello',
        'name' => 'Hello API',
        'description' => 'Test API endpoint - returns hello message',
        'methods' => ['GET', 'POST'],
        'endpoint' => '../api/hello.php',
        'parameters' => [
            ['name' => 'name', 'type' => 'string', 'required' => false, 'description' => 'Name parameter']
        ]
    ],
    [
        'id' => 'check-counting',
        'name' => 'Check Counting',
        'description' => 'Check if an active counting (sayım) exists with given sayım number',
        'methods' => ['GET', 'POST'],
        'endpoint' => '../api/counting/check-counting.php',
        'parameters' => [
            ['name' => 'sayim_no', 'type' => 'string', 'required' => true, 'description' => 'Sayım numarası (GET: query, POST: body)']
        ]
    ],
    [
        'id' => 'product-count',
        'name' => 'Product Count',
        'description' => 'Add or remove product from counting (sayım)',
        'methods' => ['POST', 'DELETE'],
        'endpoint' => '../api/counting/product-count.php',
        'parameters' => [
            ['name' => 'sayim_no', 'type' => 'string', 'required' => true, 'description' => 'Sayım numarası'],
            ['name' => 'barkod', 'type' => 'string', 'required' => true, 'description' => 'Ürün barkodu']
        ]
    ]
];

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">API Playground</h1>
                
                <div class="grid gap-6 md:grid-cols-2">
                    <!-- API List -->
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                        <div class="p-6 pb-0">
                            <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Available APIs</h3>
                        </div>
                        <div class="p-6 pt-0">
                            <div class="space-y-2 max-h-[600px] overflow-y-auto">
                                <?php foreach ($apis as $api): ?>
                                    <div 
                                        class="p-4 rounded-md border border-border hover:bg-muted/50 cursor-pointer transition-colors api-item"
                                        data-api-id="<?php echo htmlspecialchars($api['id']); ?>"
                                    >
                                        <div class="flex items-start justify-between">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-2 mb-1 flex-wrap">
                                                    <?php foreach ($api['methods'] as $method): 
                                                        $methodColors = [
                                                            'GET' => 'bg-blue-100 text-blue-800',
                                                            'POST' => 'bg-green-100 text-green-800',
                                                            'PUT' => 'bg-yellow-100 text-yellow-800',
                                                            'DELETE' => 'bg-red-100 text-red-800',
                                                            'PATCH' => 'bg-purple-100 text-purple-800'
                                                        ];
                                                        $colorClass = $methodColors[$method] ?? 'bg-gray-100 text-gray-800';
                                                    ?>
                                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium <?php echo $colorClass; ?>">
                                                            <?php echo $method; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <span class="font-medium text-sm"><?php echo htmlspecialchars($api['name']); ?></span>
                                                </div>
                                                <p class="text-xs text-muted-foreground"><?php echo htmlspecialchars($api['description']); ?></p>
                                                <p class="text-xs text-muted-foreground mt-1 font-mono"><?php echo htmlspecialchars($api['endpoint']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- API Request/Response Panel -->
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                        <div class="p-6 pb-0">
                            <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">API Request</h3>
                        </div>
                        <div class="p-6 pt-0">
                            <div id="api-request-panel">
                                <div class="text-center py-8 text-muted-foreground">
                                    <svg class="mx-auto h-12 w-12 text-muted-foreground mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    <p>Select an API from the list to start</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Response Panel -->
                <div class="mt-6 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Response</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <div id="api-response" class="min-h-[200px]">
                            <div class="text-center py-8 text-muted-foreground">
                                <p>Response will appear here after making a request</p>
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

// Store APIs data
const apiData = {};
apis.forEach(api => {
    apiData[api.id] = api;
});

// Handle API selection
document.querySelectorAll('.api-item').forEach(item => {
    item.addEventListener('click', function() {
        const apiId = this.dataset.apiId;
        const api = apiData[apiId];
        
        // Remove active class from all items
        document.querySelectorAll('.api-item').forEach(i => i.classList.remove('bg-accent'));
        // Add active class to selected item
        this.classList.add('bg-accent');
        
        // Generate request panel
        generateRequestPanel(api);
    });
});

function generateRequestPanel(api) {
    const panel = document.getElementById('api-request-panel');
    
    const methodColors = {
        'GET': 'bg-blue-100 text-blue-800',
        'POST': 'bg-green-100 text-green-800',
        'PUT': 'bg-yellow-100 text-yellow-800',
        'DELETE': 'bg-red-100 text-red-800',
        'PATCH': 'bg-purple-100 text-purple-800'
    };
    
    let methodsHtml = api.methods.map(method => {
        const colorClass = methodColors[method] || 'bg-gray-100 text-gray-800';
        return `
            <option value="${method}">${method}</option>
        `;
    }).join('');
    
    let paramsHtml = '';
    if (api.parameters && api.parameters.length > 0) {
        paramsHtml = api.parameters.map(param => `
            <div class="mb-3">
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    ${param.name} ${param.required ? '<span class="text-red-500">*</span>' : ''}
                    <span class="text-xs text-muted-foreground ml-2">(${param.type})</span>
                </label>
                <input 
                    type="text" 
                    data-param="${param.name}"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                    placeholder="${param.description || param.name}"
                    ${param.required ? 'required' : ''}
                >
            </div>
        `).join('');
    }
    
    panel.innerHTML = `
        <div>
            <div class="mb-4">
                <div class="flex items-center gap-2 mb-2">
                    <span class="font-medium text-sm">${api.name}</span>
                </div>
                <p class="text-xs text-muted-foreground mb-2">${api.description}</p>
                <p class="text-xs font-mono bg-muted p-2 rounded mb-3">${api.endpoint}</p>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    HTTP Method
                </label>
                <select 
                    id="http-method"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                    onchange="updateRequestBody()"
                >
                    ${methodsHtml}
                </select>
            </div>
            
            ${api.parameters && api.parameters.length > 0 ? `
                <div class="mb-4">
                    <h4 class="text-sm font-medium text-foreground mb-2">Query Parameters / Body</h4>
                    ${paramsHtml}
                </div>
            ` : ''}
            
            <div class="mb-4" id="body-section" style="display: none;">
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    Request Body (JSON)
                </label>
                <textarea 
                    id="request-body"
                    rows="6"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                    placeholder='{"key": "value"}'
                ></textarea>
                <p class="text-xs text-muted-foreground mt-1">Enter JSON object for POST/PUT/DELETE requests</p>
            </div>
            
            <div class="mb-4" id="headers-section">
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    Custom Headers (Optional)
                </label>
                <textarea 
                    id="custom-headers"
                    rows="3"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                    placeholder='Authorization: Bearer token&#10;Content-Type: application/json'
                ></textarea>
                <p class="text-xs text-muted-foreground mt-1">Enter headers as key: value (one per line)</p>
            </div>
            
            <button 
                onclick="makeRequest('${api.id}')"
                class="w-full rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
            >
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
    const method = document.getElementById('http-method')?.value || 'GET';
    
    // Show loading
    responseDiv.innerHTML = `
        <div class="text-center py-8">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
            <p class="mt-2 text-sm text-muted-foreground">Sending ${method} request...</p>
        </div>
    `;
    
    try {
        // Collect query parameters
        const queryParams = {};
        const paramInputs = document.querySelectorAll('[data-param]');
        paramInputs.forEach(input => {
            const name = input.dataset.param;
            const value = input.value.trim();
            if (value) {
                queryParams[name] = value;
            }
        });
        
        // Build URL
        let url = api.endpoint;
        if (method === 'GET' && Object.keys(queryParams).length > 0) {
            const queryString = new URLSearchParams(queryParams).toString();
            url += '?' + queryString;
        }
        
        // Prepare headers
        const headers = {
            'Content-Type': 'application/json'
        };
        
        // Parse custom headers
        const customHeadersText = document.getElementById('custom-headers')?.value || '';
        if (customHeadersText.trim()) {
            customHeadersText.split('\n').forEach(line => {
                const [key, ...valueParts] = line.split(':');
                if (key && valueParts.length > 0) {
                    headers[key.trim()] = valueParts.join(':').trim();
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
                    // Try to parse as JSON
                    const bodyObj = JSON.parse(bodyText);
                    // Merge with query params if needed
                    Object.assign(bodyObj, queryParams);
                    options.body = JSON.stringify(bodyObj);
                } catch (e) {
                    // If not valid JSON, use as is or merge with query params
                    if (Object.keys(queryParams).length > 0) {
                        options.body = JSON.stringify(queryParams);
                    } else {
                        options.body = bodyText;
                    }
                }
            } else if (Object.keys(queryParams).length > 0) {
                // If no body but have query params, use them as body
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
        
        // Get response headers
        const responseHeaders = {};
        response.headers.forEach((value, key) => {
            responseHeaders[key] = value;
        });
        
        // Display response
        const statusColor = response.ok ? 'green' : 'red';
        responseDiv.innerHTML = `
            <div class="mb-4">
                <div class="flex items-center gap-2 mb-2 flex-wrap">
                    <span class="inline-flex items-center rounded-full bg-${statusColor}-100 px-2.5 py-0.5 text-xs font-medium text-${statusColor}-800">
                        ${response.status} ${response.statusText}
                    </span>
                    <span class="text-xs text-muted-foreground">${duration}ms</span>
                    <span class="text-xs text-muted-foreground">${new Date().toLocaleTimeString()}</span>
                </div>
                <div class="mt-2">
                    <details class="text-xs">
                        <summary class="cursor-pointer text-muted-foreground hover:text-foreground">Response Headers</summary>
                        <pre class="mt-2 rounded-md bg-muted p-2 overflow-x-auto font-mono text-xs">${JSON.stringify(responseHeaders, null, 2)}</pre>
                    </details>
                </div>
            </div>
            <div class="rounded-md bg-muted p-4 overflow-x-auto">
                <pre class="text-xs font-mono text-foreground whitespace-pre-wrap">${isJson ? JSON.stringify(formattedData, null, 2) : formattedData}</pre>
            </div>
        `;
    } catch (error) {
        responseDiv.innerHTML = `
            <div class="rounded-md bg-red-50 p-4 border border-red-200">
                <div class="text-sm text-red-800">
                    <strong>Error:</strong> ${error.message}
                </div>
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs text-red-600">Stack Trace</summary>
                    <pre class="mt-2 text-xs font-mono">${error.stack || 'No stack trace available'}</pre>
                </details>
            </div>
        `;
    }
}
</script>
<?php include '../includes/footer.php'; ?>
