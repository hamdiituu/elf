<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'Kullanıcılar - Vira Stok Sistemi';
$db = getDB();

// Handle user operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_user':
                $username = trim($_POST['username'] ?? '');
                $password = trim($_POST['password'] ?? '');
                $user_type = trim($_POST['user_type'] ?? 'user');
                
                // Validate user_type
                if (!in_array($user_type, ['user', 'developer'])) {
                    $user_type = 'user';
                }
                
                if (!empty($username) && !empty($password)) {
                    try {
                        // Ensure user_type column exists
                        try {
                            $db->exec("ALTER TABLE users ADD COLUMN user_type TEXT DEFAULT 'user'");
                        } catch (PDOException $e) {
                            // Column might already exist, ignore
                        }
                        
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO users (username, password, user_type) VALUES (?, ?, ?)");
                        $stmt->execute([$username, $hashed_password, $user_type]);
                        $success_message = "Kullanıcı başarıyla eklendi!";
                    } catch (PDOException $e) {
                        $error_message = "Kullanıcı eklenirken hata oluştu: " . $e->getMessage();
                    }
                } else {
                    $error_message = "Lütfen kullanıcı adı ve şifre girin!";
                }
                break;
                
            case 'delete_user':
                $user_id = intval($_POST['user_id'] ?? 0);
                if ($user_id > 0 && $user_id != $_SESSION['user_id']) {
                    try {
                        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $success_message = "Kullanıcı başarıyla silindi!";
                    } catch (PDOException $e) {
                        $error_message = "Kullanıcı silinirken hata oluştu: " . $e->getMessage();
                    }
                } else {
                    $error_message = "Kendi hesabınızı silemezsiniz!";
                }
                header('Location: kullanicilar.php');
                exit;
        }
    }
}

// Ensure user_type column exists
try {
    $db->exec("ALTER TABLE users ADD COLUMN user_type TEXT DEFAULT 'user'");
} catch (PDOException $e) {
    // Column might already exist, ignore
}

// Get all users
$users = $db->query("SELECT id, username, user_type, created_at FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Kullanıcılar</h1>
                
                <?php if (isset($success_message)): ?>
                    <div class="mb-6 rounded-md bg-green-50 p-4 border border-green-200">
                        <div class="text-sm text-green-800">
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="mb-6 rounded-md bg-red-50 p-4 border border-red-200">
                        <div class="text-sm text-red-800">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Add New User -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Yeni Kullanıcı Ekle</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_user">
                            <div class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <label for="username" class="block text-sm font-medium text-foreground mb-1.5">
                                        Kullanıcı Adı
                                    </label>
                                    <input
                                        type="text"
                                        id="username"
                                        name="username"
                                        required
                                        autofocus
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="Kullanıcı adı"
                                    >
                                </div>
                                <div>
                                    <label for="password" class="block text-sm font-medium text-foreground mb-1.5">
                                        Şifre
                                    </label>
                                    <input
                                        type="password"
                                        id="password"
                                        name="password"
                                        required
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="Şifre"
                                    >
                                </div>
                                <div>
                                    <label for="user_type" class="block text-sm font-medium text-foreground mb-1.5">
                                        Kullanıcı Tipi
                                    </label>
                                    <select
                                        id="user_type"
                                        name="user_type"
                                        required
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                        <option value="user" selected>User</option>
                                        <option value="developer">Developer</option>
                                    </select>
                                </div>
                                <div class="flex items-end">
                                    <button
                                        type="submit"
                                        class="w-full rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                                    >
                                        Kullanıcı Ekle
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Users List -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Tüm Kullanıcılar</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <?php if (empty($users)): ?>
                            <div class="text-center py-8 text-muted-foreground">
                                Henüz kullanıcı eklenmemiş.
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">ID</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Kullanıcı Adı</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Tip</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Oluşturulma Tarihi</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user): ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/50">
                                                <td class="p-4 align-middle text-sm"><?php echo $user['id']; ?></td>
                                                <td class="p-4 align-middle font-medium">
                                                    <?php echo htmlspecialchars($user['username']); ?>
                                                    <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                                        <span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                                            Sen
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <?php
                                                    $user_type = $user['user_type'] ?? 'user';
                                                    if ($user_type === 'developer'):
                                                    ?>
                                                        <span class="inline-flex items-center rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-800">
                                                            Developer
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                                            User
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground"><?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></td>
                                                <td class="p-4 align-middle">
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                        <form method="POST" action="" class="inline">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                onclick="return confirm('Bu kullanıcıyı silmek istediğinizden emin misiniz?');"
                                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-red-100 text-red-800 hover:bg-red-200 h-9 px-3"
                                                            >
                                                                Sil
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-xs text-muted-foreground">-</span>
                                                    <?php endif; ?>
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
<?php include '../includes/footer.php'; ?>

