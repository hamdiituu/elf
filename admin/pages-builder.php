<?php
require_once __DIR__ . '/../config/config.php';
requireDeveloper();

$page_title = 'Pages Builder';
$db = getDB();

// Ensure dynamic_pages table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS dynamic_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_name TEXT NOT NULL UNIQUE,
        page_title TEXT NOT NULL,
        table_name TEXT NOT NULL,
        group_name TEXT,
        enable_list INTEGER DEFAULT 1,
        enable_create INTEGER DEFAULT 1,
        enable_update INTEGER DEFAULT 1,
        enable_delete INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add group_name column if it doesn't exist (migration)
    try {
        $db->exec("ALTER TABLE dynamic_pages ADD COLUMN group_name TEXT");
    } catch (PDOException $e) {
        // Column might already exist
    }
    
    // Add rule columns if they don't exist (migration)
    $rule_columns = ['create_rule', 'update_rule', 'delete_rule'];
    foreach ($rule_columns as $col) {
        try {
            $db->exec("ALTER TABLE dynamic_pages ADD COLUMN $col TEXT");
        } catch (PDOException $e) {
            // Column might already exist
        }
    }
} catch (PDOException $e) {
    // Table might already exist
}

$error_message = null;
$success_message = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_page':
                $page_name = trim($_POST['page_name'] ?? '');
                $page_title = trim($_POST['page_title'] ?? '');
                $table_name = trim($_POST['table_name'] ?? '');
                $group_name = trim($_POST['group_name'] ?? '');
                $enable_list = isset($_POST['enable_list']) ? 1 : 0;
                $enable_create = isset($_POST['enable_create']) ? 1 : 0;
                $enable_update = isset($_POST['enable_update']) ? 1 : 0;
                $enable_delete = isset($_POST['enable_delete']) ? 1 : 0;
                $create_rule = trim($_POST['create_rule'] ?? '');
                $update_rule = trim($_POST['update_rule'] ?? '');
                $delete_rule = trim($_POST['delete_rule'] ?? '');
                
                if (empty($page_name) || empty($page_title) || empty($table_name)) {
                    $error_message = "Sayfa adı, başlık ve tablo adı gereklidir!";
                } else {
                    // Validate page_name (for filename)
                    if (!preg_match('/^[a-z0-9_-]+$/', strtolower($page_name))) {
                        $error_message = "Sayfa adı sadece küçük harf, rakam, alt çizgi ve tire içerebilir!";
                    } else {
                        try {
                            // Check if table exists
                            $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table_name'");
                            if (!$stmt->fetch()) {
                                $error_message = "Seçilen tablo bulunamadı!";
                            } else {
                                // Check if page_name already exists
                                $stmt = $db->prepare("SELECT id FROM dynamic_pages WHERE page_name = ?");
                                $stmt->execute([$page_name]);
                                if ($stmt->fetch()) {
                                    $error_message = "Bu sayfa adı zaten kullanılıyor!";
                                } else {
                                    // Insert into dynamic_pages
                                    $stmt = $db->prepare("INSERT INTO dynamic_pages (page_name, page_title, table_name, group_name, enable_list, enable_create, enable_update, enable_delete, create_rule, update_rule, delete_rule) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->execute([$page_name, $page_title, $table_name, $group_name ?: null, $enable_list, $enable_create, $enable_update, $enable_delete, $create_rule ?: null, $update_rule ?: null, $delete_rule ?: null]);
                                    $success_message = "Sayfa başarıyla oluşturuldu: $page_name";
                                }
                            }
                        } catch (PDOException $e) {
                            $error_message = "Sayfa oluşturulurken hata: " . $e->getMessage();
                        }
                    }
                }
                break;
                
            case 'delete_page':
                $page_id = intval($_POST['page_id'] ?? 0);
                if ($page_id > 0) {
                    try {
                        $stmt = $db->prepare("SELECT page_name FROM dynamic_pages WHERE id = ?");
                        $stmt->execute([$page_id]);
                        $page = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($page) {
                            // Delete from database
                            $stmt = $db->prepare("DELETE FROM dynamic_pages WHERE id = ?");
                            $stmt->execute([$page_id]);
                            
                            $success_message = "Sayfa başarıyla silindi!";
                        }
                    } catch (PDOException $e) {
                        $error_message = "Sayfa silinirken hata: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Function removed - pages are now stored in database and rendered via dynamic-page.php
// Pages are no longer generated as physical files - they are rendered from database configuration

// Get all tables
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Get all dynamic pages
$dynamic_pages = $db->query("SELECT * FROM dynamic_pages ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

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
        height: 300px !important;
        font-size: 14px;
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace;
    }
    
    .CodeMirror-activeline-background {
        background: hsl(var(--muted) / 0.3);
    }
</style>

<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Pages Builder</h1>
                
                <?php if ($error_message): ?>
                    <div class="mb-6 rounded-md bg-red-50 p-4 border border-red-200">
                        <div class="text-sm text-red-800">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="mb-6 rounded-md bg-green-50 p-4 border border-green-200">
                        <div class="text-sm text-green-800">
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Create New Page Form -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Yeni Sayfa Oluştur</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_page">
                            
                            <div class="mb-4">
                                <label for="page_name" class="block text-sm font-medium text-foreground mb-1.5">
                                    Sayfa Adı (URL) <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="page_name"
                                    name="page_name"
                                    required
                                    pattern="[a-z0-9_-]+"
                                    title="Sadece küçük harf, rakam, alt çizgi ve tire kullanılabilir"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="ornek-sayfa"
                                >
                                <p class="mt-1 text-xs text-muted-foreground">Sadece küçük harf, rakam, alt çizgi (_) ve tire (-) kullanılabilir</p>
                            </div>
                            
                            <div class="mb-4">
                                <label for="page_title" class="block text-sm font-medium text-foreground mb-1.5">
                                    Sayfa Başlığı <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="page_title"
                                    name="page_title"
                                    required
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Örnek Sayfa"
                                >
                            </div>
                            
                            <div class="mb-4">
                                <label for="table_name" class="block text-sm font-medium text-foreground mb-1.5">
                                    Tablo Seçimi <span class="text-red-500">*</span>
                                </label>
                                <select
                                    id="table_name"
                                    name="table_name"
                                    required
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                >
                                    <option value="">-- Tablo Seçin --</option>
                                    <?php foreach ($tables as $table): ?>
                                        <option value="<?php echo htmlspecialchars($table); ?>">
                                            <?php echo htmlspecialchars($table); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-4">
                                <label for="group_name" class="block text-sm font-medium text-foreground mb-1.5">
                                    Grup Adı
                                </label>
                                <input
                                    type="text"
                                    id="group_name"
                                    name="group_name"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Örn: Sipariş Yönetimi"
                                >
                                <p class="mt-1 text-xs text-muted-foreground">Sayfa bu grup altında sidebar'da listelenecek. Boş bırakılırsa herhangi bir grup altında gösterilmez.</p>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-foreground mb-2">
                                    Aktif İşlemler
                                </label>
                                <div class="space-y-2">
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            name="enable_list"
                                            value="1"
                                            checked
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <span class="ml-2 text-sm text-foreground">Listeleme (List)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            name="enable_create"
                                            value="1"
                                            checked
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <span class="ml-2 text-sm text-foreground">Oluşturma (Create)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            name="enable_update"
                                            value="1"
                                            checked
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <span class="ml-2 text-sm text-foreground">Güncelleme (Update)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            name="enable_delete"
                                            value="1"
                                            checked
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <span class="ml-2 text-sm text-foreground">Silme (Delete)</span>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-foreground mb-2">
                                    Kurallar (Rules)
                                </label>
                                <p class="mb-3 text-xs text-muted-foreground">
                                    Her işlem için açıklayıcı kurallar yazabilirsiniz. Bu kurallar ilgili butonların altında gösterilecektir. 
                                    <strong>PHP kodu</strong> da yazabilirsiniz. Kullanılabilir değişkenler:
                                    <br>
                                    • <code>$record</code> - Mevcut kayıt verisi (array)
                                    <br>
                                    • <code>$columns</code> - Kolon bilgileri (array)
                                    <br>
                                    • <code>$is_edit</code> - Düzenleme modunda mı? (boolean, sadece create/update için)
                                    <br>
                                    • <code>$db</code> - Veritabanı bağlantısı (PDO)
                                    <br>
                                    • <code>$dbContext</code> - Veritabanı bağlantısı (PDO, cloud-functions ile uyumlu)
                                </p>
                                
                                <div class="space-y-4">
                                    <div>
                                        <label for="create_rule" class="block text-sm font-medium text-foreground mb-1.5">
                                            Oluşturma (Create) Kuralı
                                        </label>
                                        <textarea
                                            id="create_rule"
                                            name="create_rule"
                                            rows="4"
                                            class="hidden"
                                        ></textarea>
                                        <div class="mb-2">
                                            <details class="text-xs">
                                                <summary class="cursor-pointer text-muted-foreground hover:text-foreground mb-2">Örnek kodlar göster</summary>
                                                <div class="bg-muted p-3 rounded-md font-mono text-xs overflow-x-auto">
                                                    <div class="mb-2"><strong>Örnek 1 - Basit kontrol:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
if (empty($record[\'name\'])) {
    throw new Exception(\'İsim alanı boş olamaz!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Örnek 2 - Veritabanı sorgusu:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Email kontrolü
$stmt = $dbContext->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
$stmt->execute([$record[\'email\'] ?? \'\']);
$exists = $stmt->fetchColumn();

if ($exists > 0) {
    throw new Exception(\'Bu email adresi zaten kullanılıyor!\');
}

// İlişkili kayıt kontrolü
$stmt = $dbContext->prepare("SELECT * FROM categories WHERE id = ? AND status = \'active\'");
$stmt->execute([$record[\'category_id\'] ?? 0]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    throw new Exception(\'Geçersiz kategori seçildi!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Örnek 3 - Birden fazla kontrol:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Birden fazla alan kontrolü
$errors = [];

if (empty($record[\'name\'])) {
    $errors[] = \'İsim gereklidir\';
}

if (empty($record[\'email\'])) {
    $errors[] = \'Email gereklidir\';
} elseif (!filter_var($record[\'email\'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = \'Geçersiz email formatı\';
}

// Veritabanından kontrol
$stmt = $dbContext->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
$stmt->execute([$record[\'username\'] ?? \'\']);
if ($stmt->fetchColumn() > 0) {
    $errors[] = \'Bu kullanıcı adı zaten kullanılıyor\';
}

if (!empty($errors)) {
    throw new Exception(implode(\', \', $errors));
}
?>'); ?></pre>
                                                </div>
                                            </details>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">PHP kodu yazabilirsiniz. Exception fırlatarak işlemi durdurabilirsiniz.</p>
                                    </div>
                                    
                                    <div>
                                        <label for="update_rule" class="block text-sm font-medium text-foreground mb-1.5">
                                            Güncelleme (Update) Kuralı
                                        </label>
                                        <textarea
                                            id="update_rule"
                                            name="update_rule"
                                            rows="4"
                                            class="hidden"
                                        ></textarea>
                                        <div class="mb-2">
                                            <details class="text-xs">
                                                <summary class="cursor-pointer text-muted-foreground hover:text-foreground mb-2">Örnek kodlar göster</summary>
                                                <div class="bg-muted p-3 rounded-md font-mono text-xs overflow-x-auto">
                                                    <div class="mb-2"><strong>Örnek 1 - Mevcut kayıt kontrolü:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Mevcut kaydın durumunu kontrol et
$stmt = $dbContext->prepare("SELECT status FROM " . $table_name . " WHERE id = ?");
$stmt->execute([$record[\'id\']]);
$current_status = $stmt->fetchColumn();

if ($current_status === \'locked\') {
    throw new Exception(\'Kilitli kayıt güncellenemez!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Örnek 2 - Değişiklik kontrolü:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Mevcut kaydı al
$stmt = $dbContext->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$record[\'id\']]);
$current_record = $stmt->fetch(PDO::FETCH_ASSOC);

// Kritik alanlar değiştirilmişse kontrol et
if ($current_record[\'email\'] !== $record[\'email\']) {
    // Yeni email başka bir kullanıcıda var mı?
    $stmt = $dbContext->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$record[\'email\'], $record[\'id\']]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception(\'Bu email adresi başka bir kullanıcı tarafından kullanılıyor!\');
    }
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Örnek 3 - Yetki kontrolü:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Kullanıcının bu kaydı güncelleme yetkisi var mı?
$stmt = $dbContext->prepare("SELECT created_by FROM posts WHERE id = ?");
$stmt->execute([$record[\'id\']]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if ($post && $post[\'created_by\'] != $_SESSION[\'user_id\']) {
    throw new Exception(\'Bu kaydı güncelleme yetkiniz yok!\');
}
?>'); ?></pre>
                                                </div>
                                            </details>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">PHP kodu yazabilirsiniz. <code>$record</code> güncellenecek kayıt verisini, <code>$current_record</code> mevcut kaydı içerir.</p>
                                    </div>
                                    
                                    <div>
                                        <label for="delete_rule" class="block text-sm font-medium text-foreground mb-1.5">
                                            Silme (Delete) Kuralı
                                        </label>
                                        <textarea
                                            id="delete_rule"
                                            name="delete_rule"
                                            rows="4"
                                            class="hidden"
                                        ></textarea>
                                        <div class="mb-2">
                                            <details class="text-xs">
                                                <summary class="cursor-pointer text-muted-foreground hover:text-foreground mb-2">Örnek kodlar göster</summary>
                                                <div class="bg-muted p-3 rounded-md font-mono text-xs overflow-x-auto">
                                                    <div class="mb-2"><strong>Örnek 1 - İlişkili kayıt kontrolü:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Silinecek kayıt başka tablolarda kullanılıyor mu?
$stmt = $dbContext->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
$stmt->execute([$record[\'id\']]);
$related_count = $stmt->fetchColumn();

if ($related_count > 0) {
    throw new Exception(\'Bu ürün \' . $related_count . \' siparişte kullanıldığı için silinemez!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Örnek 2 - Durum kontrolü:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Sadece belirli durumdaki kayıtlar silinebilir
if ($record[\'status\'] === \'active\') {
    throw new Exception(\'Aktif kayıtlar silinemez! Önce pasif hale getirin.\');
}

if ($record[\'status\'] === \'locked\') {
    throw new Exception(\'Kilitli kayıtlar silinemez!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Örnek 3 - Kaskad silme kontrolü:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Alt kayıtları kontrol et
$stmt = $dbContext->prepare("SELECT COUNT(*) FROM child_table WHERE parent_id = ?");
$stmt->execute([$record[\'id\']]);
$children_count = $stmt->fetchColumn();

if ($children_count > 0) {
    // Önce alt kayıtları sil
    $stmt = $dbContext->prepare("DELETE FROM child_table WHERE parent_id = ?");
    $stmt->execute([$record[\'id\']]);
    
    // Veya hata ver
    // throw new Exception(\'Önce alt kayıtları silmelisiniz!\');
}
?>'); ?></pre>
                                                </div>
                                            </details>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">PHP kodu yazabilirsiniz. <code>$record</code> silinecek kayıt verisini içerir.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <button
                                type="submit"
                                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                            >
                                Sayfa Oluştur
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Existing Dynamic Pages -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Oluşturulmuş Sayfalar</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <?php if (empty($dynamic_pages)): ?>
                            <div class="text-center py-8 text-muted-foreground">
                                Henüz sayfa oluşturulmamış.
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Sayfa Adı</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Sayfa Başlığı</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Grup</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Tablo</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">İşlemler</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Oluşturulma</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Aksiyon</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dynamic_pages as $page): ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/50">
                                                <td class="p-4 align-middle text-sm font-medium">
                                                    <a href="dynamic-page.php?page=<?php echo urlencode($page['page_name']); ?>" class="text-primary hover:underline">
                                                        <?php echo htmlspecialchars($page['page_name']); ?>
                                                    </a>
                                                </td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($page['page_title']); ?></td>
                                                <td class="p-4 align-middle text-sm">
                                                    <?php if (!empty($page['group_name'])): ?>
                                                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                                            <?php echo htmlspecialchars($page['group_name']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted-foreground">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground"><?php echo htmlspecialchars($page['table_name']); ?></td>
                                                <td class="p-4 align-middle">
                                                    <div class="flex flex-wrap gap-1">
                                                        <?php if ($page['enable_list']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">List</span>
                                                        <?php endif; ?>
                                                        <?php if ($page['enable_create']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Create</span>
                                                        <?php endif; ?>
                                                        <?php if ($page['enable_update']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">Update</span>
                                                        <?php endif; ?>
                                                        <?php if ($page['enable_delete']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">Delete</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground">
                                                    <?php echo date('d.m.Y H:i', strtotime($page['created_at'])); ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <form method="POST" action="" class="inline" onsubmit="return confirm('Bu sayfayı silmek istediğinizden emin misiniz?');">
                                                        <input type="hidden" name="action" value="delete_page">
                                                        <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                                        <button
                                                            type="submit"
                                                            class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-red-100 text-red-800 hover:bg-red-200 h-9 px-3"
                                                        >
                                                            Sil
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// Initialize CodeMirror editors for rule fields
document.addEventListener('DOMContentLoaded', function() {
    // CodeMirror configuration
    const editorConfig = {
        lineNumbers: true,
        mode: 'application/x-httpd-php',
        theme: 'monokai',
        indentUnit: 4,
        indentWithTabs: false,
        autoCloseBrackets: true,
        matchBrackets: true,
        styleActiveLine: true,
        lineWrapping: true,
        extraKeys: {
            "Ctrl-Space": "autocomplete",
            "Ctrl-Enter": function(cm) {
                // Allow form submission with Ctrl+Enter
                return true;
            }
        },
        hintOptions: {
            completeSingle: false
        }
    };
    
    // Initialize editors
    const createRuleEditor = CodeMirror.fromTextArea(document.getElementById('create_rule'), editorConfig);
    const updateRuleEditor = CodeMirror.fromTextArea(document.getElementById('update_rule'), editorConfig);
    const deleteRuleEditor = CodeMirror.fromTextArea(document.getElementById('delete_rule'), editorConfig);
    
    // Sync editor content with textarea before form submission
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            createRuleEditor.save();
            updateRuleEditor.save();
            deleteRuleEditor.save();
        });
    }
    
    // Store editors for potential future use
    window.ruleEditors = {
        create: createRuleEditor,
        update: updateRuleEditor,
        delete: deleteRuleEditor
    };
});
</script>

<?php include '../includes/footer.php'; ?>
