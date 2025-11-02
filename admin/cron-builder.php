<?php
require_once '../config/config.php';
requireDeveloper();

$page_title = 'Cron Builder';

$db = getDB();
$error_message = null;
$success_message = null;

// Load cron helper
require_once __DIR__ . '/../cron/common/cron-helper.php';

// Ensure cron_jobs table exists and has language column
try {
    $db->exec("CREATE TABLE IF NOT EXISTS cron_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT,
        code TEXT NOT NULL,
        schedule TEXT NOT NULL,
        language TEXT DEFAULT 'php',
        enabled INTEGER DEFAULT 1,
        last_run_at DATETIME NULL,
        next_run_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add language column if it doesn't exist
    $columns = $db->query("PRAGMA table_info(cron_jobs)")->fetchAll(PDO::FETCH_ASSOC);
    $hasLanguage = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'language') {
            $hasLanguage = true;
            break;
        }
    }
    if (!$hasLanguage) {
        $db->exec("ALTER TABLE cron_jobs ADD COLUMN language TEXT DEFAULT 'php'");
    }
    
    // Create indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_jobs_enabled ON cron_jobs(enabled)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_jobs_next_run_at ON cron_jobs(next_run_at)");
} catch (PDOException $e) {
    // Table might already exist
}

// Handle form submission
$edit_id = $_GET['edit'] ?? null;
$edit_cron = null;

if ($edit_id) {
    $stmt = $db->prepare("SELECT * FROM cron_jobs WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_cron = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $code = $_POST['code'] ?? '';
    $schedule = trim($_POST['schedule'] ?? '');
    $language = $_POST['language'] ?? 'php';
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    
    if (empty($name)) {
        $error_message = "Cron job name is required!";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        $error_message = "Cron job name can only contain letters, numbers and underscore!";
    } elseif (empty($code)) {
        $error_message = "Cron job code is required!";
    } elseif (empty($schedule)) {
        $error_message = "Schedule is required!";
    } else {
        try {
            // Validate schedule format (basic cron expression: * * * * *)
            $schedule_parts = explode(' ', $schedule);
            if (count($schedule_parts) !== 5) {
                throw new Exception("Invalid schedule format! Example: * * * * * (minute hour day month weekday)");
            }
            
            if ($action === 'create') {
                // Check if name already exists
                $check = $db->prepare("SELECT id FROM cron_jobs WHERE name = ?");
                $check->execute([$name]);
                if ($check->fetch()) {
                    throw new Exception("A cron job with this name already exists!");
                }
                
                // Calculate next_run_at based on schedule
                $next_run_at = calculateNextRunTime($schedule);
                
                $stmt = $db->prepare("INSERT INTO cron_jobs (name, description, code, schedule, language, enabled, next_run_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $description, $code, $schedule, $language, $enabled, $next_run_at]);
                $success_message = "Cron job created successfully!";
                header('Location: cron-manager.php?success=' . urlencode($success_message));
                exit;
            } elseif ($action === 'update') {
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception("Invalid cron job ID!");
                }
                
                // Check if name already exists (excluding current)
                $check = $db->prepare("SELECT id FROM cron_jobs WHERE name = ? AND id != ?");
                $check->execute([$name, $id]);
                if ($check->fetch()) {
                    throw new Exception("A cron job with this name already exists!");
                }
                
                // Calculate next_run_at based on schedule
                $next_run_at = calculateNextRunTime($schedule);
                
                $stmt = $db->prepare("UPDATE cron_jobs SET name = ?, description = ?, code = ?, schedule = ?, language = ?, enabled = ?, next_run_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$name, $description, $code, $schedule, $language, $enabled, $next_run_at, $id]);
                $success_message = "Cron job updated successfully!";
                header('Location: cron-manager.php?success=' . urlencode($success_message));
                exit;
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

// Helper function to calculate next run time from cron expression
function calculateNextRunTime($schedule) {
    $parts = explode(' ', $schedule);
    if (count($parts) !== 5) {
        return date('Y-m-d H:i:s', strtotime('+1 minute'));
    }
    
    // Simple calculation: if all are *, run in 1 minute
    // Otherwise, try to parse (simplified version)
    if ($schedule === '* * * * *') {
        return date('Y-m-d H:i:s', strtotime('+1 minute'));
    }
    
    // For now, set to run in 1 minute as default
    // In production, use a proper cron expression parser
    return date('Y-m-d H:i:s', strtotime('+1 minute'));
}

include '../includes/header.php';
?>

<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>
    
    <main class="flex-1 overflow-y-auto bg-background">
        <div class="container mx-auto px-4 py-8">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-foreground mb-2">
                    <?php echo $edit_cron ? 'Edit Cron Job' : 'Create New Cron Job'; ?>
                </h1>
                <p class="text-muted-foreground">
                    <?php echo $edit_cron ? 'Update existing cron job information.' : 'Create a new scheduled task (cron job).'; ?>
                </p>
            </div>
            
            <?php if ($error_message): ?>
                <div class="mb-4 p-4 bg-destructive/10 text-destructive rounded-md border border-destructive/20">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="mb-4 p-4 bg-green-500/10 text-green-600 rounded-md border border-green-500/20">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="bg-card rounded-lg border border-border p-6">
                <form method="POST" action="" class="space-y-6">
                    <input type="hidden" name="action" value="<?php echo $edit_cron ? 'update' : 'create'; ?>">
                    <?php if ($edit_cron): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_cron['id']); ?>">
                    <?php endif; ?>
                    
                    <div class="space-y-4">
                        <div>
                            <label for="name" class="block text-sm font-medium text-foreground mb-2">
                                Cron Job Name <span class="text-destructive">*</span>
                            </label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="<?php echo htmlspecialchars($edit_cron['name'] ?? ''); ?>"
                                required
                                pattern="[a-zA-Z0-9_]+"
                                <?php echo $edit_cron ? 'readonly' : ''; ?>
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                placeholder="example_cron_job"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                Only letters, numbers and underscore allowed. <?php echo $edit_cron ? '(Cannot be edited)' : ''; ?>
                            </p>
                        </div>
                        
                        <div>
                            <label for="description" class="block text-sm font-medium text-foreground mb-2">
                                Description
                            </label>
                            <input
                                type="text"
                                id="description"
                                name="description"
                                value="<?php echo htmlspecialchars($edit_cron['description'] ?? ''); ?>"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                placeholder="What does this cron job do?"
                            />
                        </div>
                        
                        <div>
                            <label for="language" class="block text-sm font-medium text-foreground mb-2">
                                Language <span class="text-destructive">*</span>
                            </label>
                            <select
                                id="language"
                                name="language"
                                required
                                onchange="updateCodeEditorMode()"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            >
                                <option value="php" selected>PHP</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="schedule" class="block text-sm font-medium text-foreground mb-2">
                                Schedule (Cron Expression) <span class="text-destructive">*</span>
                            </label>
                            <input
                                type="text"
                                id="schedule"
                                name="schedule"
                                value="<?php echo htmlspecialchars($edit_cron['schedule'] ?? '* * * * *'); ?>"
                                required
                                pattern="[0-9\*\/\-\,\s]+"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                placeholder="* * * * *"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                Format: <code class="bg-muted px-1 rounded">minute hour day month weekday</code>
                                <br>Examples: <code class="bg-muted px-1 rounded">*/5 * * * *</code> (every 5 minutes), <code class="bg-muted px-1 rounded">0 0 * * *</code> (once a day at 00:00)
                            </p>
                        </div>
                        
                        <div>
                            <label for="code" class="block text-sm font-medium text-foreground mb-2">
                                Code <span class="text-destructive">*</span>
                            </label>
                            <textarea
                                id="code"
                                name="code"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                rows="20"
                            ><?php 
                            $defaultPhpCode = "// Cron Job Code (PHP)\n// Available variables:\n// \$db - Database connection (PDO object)\n\n// Example: Simple database query\n// \$stmt = \$db->query(\"SELECT COUNT(*) as total FROM users\");\n// \$result = \$stmt->fetch(PDO::FETCH_ASSOC);\n// echo \"Total users: \" . \$result['total'] . \"\\n\";\n\n// Example: Send email\n// mail('admin@example.com', 'Cron Job', 'Cron job executed successfully');\n\n// Example: Clean up old records\n// \$db->exec(\"DELETE FROM logs WHERE created_at < datetime('now', '-30 days')\");\n\n// Your code here\necho \"Cron job executed at \" . date('Y-m-d H:i:s') . \"\\n\";\n";
                            
                            $selectedLanguage = $edit_cron['language'] ?? 'php';
                            $codeToShow = $defaultPhpCode;
                            echo htmlspecialchars($edit_cron['code'] ?? $codeToShow); 
                            ?></textarea>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                name="enabled"
                                id="enabled"
                                <?php echo (!$edit_cron || $edit_cron['enabled']) ? 'checked' : ''; ?>
                                class="rounded border-input"
                            />
                            <label for="enabled" class="text-sm font-medium text-foreground">
                                Active
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
                                <?php echo $edit_cron ? 'Update' : 'Create'; ?>
                            </button>
                            <a
                                href="cron-manager.php"
                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-4 py-2 transition-colors"
                            >
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<!-- CodeMirror -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/closebrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/selection/active-line.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.css">

<script>
    // Initialize CodeMirror
    const codeTextarea = document.getElementById('code');
    if (!codeTextarea) {
        console.error('Code textarea not found');
    }
    
    // Determine initial mode based on language
    const languageSelect = document.getElementById('language');
    const initialLanguage = languageSelect ? languageSelect.value : 'php';
    
    const codeEditor = CodeMirror.fromTextArea(codeTextarea, {
        mode: 'clike',
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
                
                // Custom hints for cron job context
                const hints = [
                    'getDB', 'db', '$db',
                    'query', 'prepare', 'execute', 'fetchAll', 'fetch', 'fetchColumn',
                    'PDO::FETCH_ASSOC', 'PDO::FETCH_OBJ', 'PDO::FETCH_NUM',
                    'array', 'count', 'isset', 'empty', 'trim', 'htmlspecialchars',
                    'json_encode', 'json_decode', 'date', 'time', 'strtotime',
                    'echo', 'print', 'var_dump', 'error_log',
                    'foreach', 'for', 'while', 'if', 'else', 'switch', 'case',
                    'try', 'catch', 'Exception', 'PDOException'
                ];
                
                const filtered = hints.filter(h => h.toLowerCase().startsWith(word.toLowerCase()));
                return {
                    list: filtered.length > 0 ? filtered : hints,
                    from: CodeMirror.Pos(cursor.line, token.start),
                    to: CodeMirror.Pos(cursor.line, token.end)
                };
            }
        }
    });
    
    // Update editor mode when language changes
    function updateCodeEditorMode() {
        const selectedLanguage = document.getElementById('language').value;
        codeEditor.setOption('mode', 'clike');
        
        // Update example code if editor is empty or contains default code
        const currentValue = codeEditor.getValue().trim();
        if (!currentValue || currentValue.startsWith('// Cron Job Code') || currentValue === '') {
            const exampleCode = `// Cron Job Code (PHP)
// Available variables:
// \$db - Database connection (PDO object)

// Example: Simple database query
// \$stmt = \$db->query("SELECT COUNT(*) as total FROM users");
// \$result = \$stmt->fetch(PDO::FETCH_ASSOC);
// echo "Total users: " . \$result['total'] . "\\n";

// Example: Send email
// mail('admin@example.com', 'Cron Job', 'Cron job executed successfully');

// Example: Clean up old records
// \$db->exec("DELETE FROM logs WHERE created_at < datetime('now', '-30 days')");

// Your code here
echo "Cron job executed at " . date('Y-m-d H:i:s') . "\\n";`;
            codeEditor.setValue(exampleCode);
        }
    }
    
    // Save editor content to textarea before form submit
    document.querySelector('form').addEventListener('submit', function() {
        codeEditor.save();
    });
</script>

<?php include '../includes/footer.php'; ?>

