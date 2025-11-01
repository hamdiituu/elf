<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'Cloud Functions - Vira Stok Sistemi';

$db = getDB();
$error_message = null;
$success_message = null;

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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_function':
            $function_id = intval($_POST['function_id'] ?? 0);
            $function_name = trim($_POST['function_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $code = $_POST['code'] ?? '';
            $http_method = $_POST['http_method'] ?? 'POST';
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            
            if (empty($function_name) || empty($code)) {
                $error_message = "Fonksiyon adı ve kod gereklidir!";
            } else {
                try {
                    // Generate endpoint from name
                    $endpoint = strtolower(preg_replace('/[^a-z0-9]+/', '-', $function_name));
                    $endpoint = trim($endpoint, '-');
                    
                    if ($function_id > 0) {
                        // Update existing function
                        $stmt = $db->prepare("
                            UPDATE cloud_functions 
                            SET name = ?, description = ?, code = ?, http_method = ?, endpoint = ?, enabled = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$function_name, $description, $code, $http_method, $endpoint, $enabled, $function_id]);
                        $success_message = "Fonksiyon başarıyla güncellendi!";
                    } else {
                        // Create new function
                        $stmt = $db->prepare("
                            INSERT INTO cloud_functions (name, description, code, http_method, endpoint, enabled, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$function_name, $description, $code, $http_method, $endpoint, $enabled, $_SESSION['user_id']]);
                        $success_message = "Fonksiyon başarıyla oluşturuldu!";
                    }
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                        $error_message = "Bu fonksiyon adı veya endpoint zaten kullanılıyor!";
                    } else {
                        $error_message = "Hata: " . $e->getMessage();
                    }
                }
            }
            break;
            
        case 'delete_function':
            $function_id = intval($_POST['function_id'] ?? 0);
            if ($function_id > 0) {
                try {
                    $stmt = $db->prepare("DELETE FROM cloud_functions WHERE id = ?");
                    $stmt->execute([$function_id]);
                    $success_message = "Fonksiyon başarıyla silindi!";
                } catch (PDOException $e) {
                    $error_message = "Fonksiyon silinirken hata: " . $e->getMessage();
                }
            }
            break;
    }
    
    // Redirect to prevent form resubmission
    if ($error_message || $success_message) {
        header('Location: cloud-functions.php' . ($success_message ? '?success=' . urlencode($success_message) : '') . ($error_message ? '&error=' . urlencode($error_message) : ''));
        exit;
    }
}

// Get success/error messages from URL
if (isset($_GET['success'])) {
    $success_message = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = urldecode($_GET['error']);
}

// Get function to edit
$edit_function = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id) {
    $stmt = $db->prepare("SELECT * FROM cloud_functions WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_function = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all functions
$functions = $db->query("SELECT cf.*, u.username as created_by_name FROM cloud_functions cf LEFT JOIN users u ON cf.created_by = u.id ORDER BY cf.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/closebrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/selection/active-line.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/anyword-hint.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.css">

<style>
    .CodeMirror {
        border: 1px solid hsl(var(--input));
        border-radius: 0.375rem;
        height: 500px;
        font-size: 14px;
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace;
    }
    
    .CodeMirror-activeline-background {
        background: hsl(var(--muted) / 0.3);
    }
    
    /* Fullscreen styles */
    #code-editor-wrapper.fullscreen {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 9999;
        background: hsl(var(--background));
        padding: 20px;
    }
    
    #code-editor-wrapper.fullscreen .CodeMirror {
        height: calc(100vh - 100px);
        border-radius: 0.5rem;
    }
    
    #code-editor-wrapper.fullscreen #fullscreen-btn {
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 10000;
    }
</style>

<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <div class="flex items-center justify-between mb-6">
                    <h1 class="text-3xl font-bold text-foreground">Cloud Functions</h1>
                    <button
                        onclick="showCreateForm()"
                        class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2 transition-colors"
                    >
                        <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Yeni Fonksiyon
                    </button>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-green-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Functions List -->
                    <div class="lg:col-span-1">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Fonksiyonlar</h3>
                                <div class="space-y-2 max-h-[600px] overflow-y-auto">
                                    <?php if (empty($functions)): ?>
                                        <div class="text-center py-8 text-muted-foreground text-sm">
                                            Henüz fonksiyon yok.
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($functions as $func): ?>
                                            <div class="rounded-md border border-border p-3 bg-muted/30 hover:bg-muted/50 transition-colors">
                                                <div class="flex items-center justify-between mb-2">
                                                    <div class="flex-1">
                                                        <h4 class="font-medium text-sm text-foreground">
                                                            <?php echo htmlspecialchars($func['name']); ?>
                                                            <?php if (!$func['enabled']): ?>
                                                                <span class="ml-2 text-xs text-muted-foreground">(Pasif)</span>
                                                            <?php endif; ?>
                                                        </h4>
                                                        <?php if ($func['description']): ?>
                                                            <p class="text-xs text-muted-foreground mt-1"><?php echo htmlspecialchars($func['description']); ?></p>
                                                        <?php endif; ?>
                                                        <p class="text-xs font-mono text-muted-foreground mt-1 break-all">
                                                            <?php echo htmlspecialchars($func['http_method']); ?> <?php echo htmlspecialchars($func['endpoint']); ?>
                                                        </p>
                                                        <p class="text-xs text-muted-foreground mt-1">
                                                            API: /api/cloud-functions/execute.php?function=<?php echo htmlspecialchars($func['name']); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2 mt-3">
                                                    <a
                                                        href="?edit=<?php echo $func['id']; ?>"
                                                        class="inline-flex items-center justify-center rounded-md text-xs font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-2 py-1 transition-colors"
                                                    >
                                                        Düzenle
                                                    </a>
                                                    <button
                                                        onclick="deleteFunction(<?php echo $func['id']; ?>, '<?php echo htmlspecialchars(addslashes($func['name'])); ?>')"
                                                        class="inline-flex items-center justify-center rounded-md text-xs font-medium bg-red-100 text-red-800 hover:bg-red-200 px-2 py-1 transition-colors"
                                                    >
                                                        Sil
                                                    </button>
                                                    <a
                                                        href="api-playground.php?api_id=cloud-function-<?php echo $func['id']; ?>"
                                                        class="inline-flex items-center justify-center rounded-md text-xs font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-2 py-1 transition-colors"
                                                    >
                                                        Test
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Function Editor -->
                    <div class="lg:col-span-2">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">
                                    <?php echo $edit_function ? 'Fonksiyon Düzenle' : 'Yeni Fonksiyon'; ?>
                                </h3>
                                
                                <form method="POST" id="function-form">
                                    <input type="hidden" name="action" value="save_function">
                                    <input type="hidden" name="function_id" value="<?php echo $edit_function ? $edit_function['id'] : '0'; ?>">
                                    
                                    <div class="space-y-4">
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-foreground mb-1.5">
                                                    Fonksiyon Adı *
                                                </label>
                                                <input
                                                    type="text"
                                                    name="function_name"
                                                    id="function_name"
                                                    value="<?php echo htmlspecialchars($edit_function['name'] ?? ''); ?>"
                                                    required
                                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                    placeholder="my-function"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-foreground mb-1.5">
                                                    HTTP Method *
                                                </label>
                                                <select
                                                    name="http_method"
                                                    id="http_method"
                                                    required
                                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                >
                                                    <option value="POST" <?php echo (!$edit_function || $edit_function['http_method'] === 'POST') ? 'selected' : ''; ?>>POST</option>
                                                    <option value="GET" <?php echo ($edit_function && $edit_function['http_method'] === 'GET') ? 'selected' : ''; ?>>GET</option>
                                                    <option value="PUT" <?php echo ($edit_function && $edit_function['http_method'] === 'PUT') ? 'selected' : ''; ?>>PUT</option>
                                                    <option value="DELETE" <?php echo ($edit_function && $edit_function['http_method'] === 'DELETE') ? 'selected' : ''; ?>>DELETE</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-foreground mb-1.5">
                                                Açıklama
                                            </label>
                                            <input
                                                type="text"
                                                name="description"
                                                id="description"
                                                value="<?php echo htmlspecialchars($edit_function['description'] ?? ''); ?>"
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                placeholder="Fonksiyon açıklaması"
                                            />
                                        </div>
                                        
                                        <div>
                                            <div class="flex items-center justify-between mb-1.5">
                                                <label class="block text-sm font-medium text-foreground">
                                                    Kod *
                                                </label>
                                                <button
                                                    type="button"
                                                    onclick="toggleFullscreen()"
                                                    class="inline-flex items-center justify-center rounded-md text-xs font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-2 py-1 transition-colors"
                                                    id="fullscreen-btn"
                                                >
                                                    <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                                                    </svg>
                                                    Tam Ekran
                                                </button>
                                            </div>
                                            <div id="code-editor-wrapper" class="relative">
                                                <textarea
                                                    name="code"
                                                    id="code"
                                                    required
                                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                    rows="20"
                                                ><?php echo htmlspecialchars($edit_function['code'] ?? "// Cloud Function Code\n// Available variables:\n// \$dbContext - Database connection (PDO object)\n// \$request - Request body data (array)\n// \$method - HTTP method (string: GET, POST, PUT, DELETE)\n// \$headers - Request headers (array)\n// \$response - Response array (must set this)\n\n// Example: Get users\n\$stmt = \$dbContext->query(\"SELECT * FROM users LIMIT 10\");\n\$users = \$stmt->fetchAll(PDO::FETCH_ASSOC);\n\n// Set response\n\$response['success'] = true;\n\$response['data'] = \$users;\n\$response['message'] = 'Users retrieved successfully';\n\n// Example: With parameters from request\n// \$limit = isset(\$request['limit']) ? intval(\$request['limit']) : 10;\n// \$stmt = \$dbContext->prepare(\"SELECT * FROM users LIMIT ?\");\n// \$stmt->execute([\$limit]);\n// \$users = \$stmt->fetchAll(PDO::FETCH_ASSOC);\n// \$response['success'] = true;\n// \$response['data'] = \$users;\n"); ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                name="enabled"
                                                id="enabled"
                                                <?php echo (!$edit_function || $edit_function['enabled']) ? 'checked' : ''; ?>
                                                class="rounded border-input"
                                            />
                                            <label for="enabled" class="text-sm font-medium text-foreground">
                                                Aktif
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center gap-2">
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2 transition-colors"
                                            >
                                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                                <?php echo $edit_function ? 'Güncelle' : 'Oluştur'; ?>
                                            </button>
                                            <?php if ($edit_function): ?>
                                                <a
                                                    href="cloud-functions.php"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-4 py-2 transition-colors"
                                                >
                                                    İptal
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // Initialize CodeMirror
    const codeEditor = CodeMirror.fromTextArea(document.getElementById('code'), {
        mode: {
            name: 'php',
            startOpen: true
        },
        theme: 'monokai',
        lineNumbers: true,
        autoCloseBrackets: true,
        matchBrackets: true,
        styleActiveLine: true,
        indentUnit: 4,
        indentWithTabs: false,
        lineWrapping: true,
        extraKeys: {
            "Ctrl-Space": function(cm) {
                CodeMirror.commands.autocomplete(cm, CodeMirror.hint.anyword);
            },
            "Ctrl-F": "findPersistent"
        },
        hintOptions: {
            hint: function(editor) {
                const cursor = editor.getCursor();
                const token = editor.getTokenAt(cursor);
                const word = token.string;
                
                // Custom hints for dbContext and common functions
                const hints = [
                    'dbContext', 'request', 'method', 'headers', 'response',
                    'query', 'prepare', 'execute', 'fetchAll', 'fetch', 'fetchColumn',
                    'PDO::FETCH_ASSOC', 'PDO::FETCH_OBJ', 'PDO::FETCH_NUM',
                    'success', 'data', 'message', 'error',
                    'array', 'count', 'isset', 'empty', 'trim', 'htmlspecialchars',
                    'json_encode', 'json_decode', 'date', 'time'
                ];
                
                const filtered = hints.filter(h => h.toLowerCase().startsWith(word.toLowerCase()));
                
                return {
                    list: filtered,
                    from: CodeMirror.Pos(cursor.line, token.start),
                    to: CodeMirror.Pos(cursor.line, token.end)
                };
            },
            completeSingle: false
        }
    });
    
    // Auto-trigger autocomplete
    codeEditor.on('inputRead', function(cm, change) {
        if (change.text[0].length > 0 && change.text[0][0].match(/[a-zA-Z]/)) {
            setTimeout(function() {
                CodeMirror.commands.autocomplete(cm);
            }, 100);
        }
    });
    
    let isFullscreen = false;
    
    function toggleFullscreen() {
        const wrapper = document.getElementById('code-editor-wrapper');
        const btn = document.getElementById('fullscreen-btn');
        
        if (!isFullscreen) {
            wrapper.classList.add('fullscreen');
            btn.innerHTML = `
                <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                Çık
            `;
            isFullscreen = true;
            codeEditor.refresh();
            codeEditor.focus();
        } else {
            wrapper.classList.remove('fullscreen');
            btn.innerHTML = `
                <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                </svg>
                Tam Ekran
            `;
            isFullscreen = false;
            codeEditor.refresh();
        }
    }
    
    // ESC key to exit fullscreen
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isFullscreen) {
            toggleFullscreen();
        }
    });
    
    function showCreateForm() {
        window.location.href = 'cloud-functions.php';
    }
    
    function deleteFunction(id, name) {
        if (confirm('Fonksiyon "' + name + '" silinecek. Emin misiniz?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="delete_function"><input type="hidden" name="function_id" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php include '../includes/footer.php'; ?>

