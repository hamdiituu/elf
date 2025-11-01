<?php
require_once 'config/config.php';

// If already logged in, redirect to admin panel
if (isLoggedIn()) {
    header('Location: admin/admin.php');
    exit;
}

$page_title = 'Giriş Yap - ' . getAppName();
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                
                // Get user_type from database
                $stmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
                $stmt->execute([$user['id']]);
                $user_type = $stmt->fetchColumn();
                $_SESSION['user_type'] = $user_type ?: 'user';
                
                header('Location: admin/admin.php');
                exit;
            } else {
                $error = 'Kullanıcı adı veya şifre hatalı!';
            }
        } catch (PDOException $e) {
            $error = 'Giriş hatası: ' . $e->getMessage();
        }
    } else {
        $error = 'Lütfen tüm alanları doldurun!';
    }
}

include 'includes/header.php';
?>
<div class="flex min-h-screen items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
    <div class="w-full max-w-sm space-y-8">
        <div>
            <?php
            $logo = getLogo();
            if (!empty($logo) && file_exists(__DIR__ . '/../' . $logo)):
            ?>
                <div class="flex justify-center mb-4">
                    <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars(getAppName()); ?>" class="h-20 object-contain">
                </div>
            <?php endif; ?>
            <h2 class="mt-6 text-center text-3xl font-bold tracking-tight text-foreground">
                <?php echo htmlspecialchars(getAppName()); ?>
            </h2>
            <p class="mt-2 text-center text-sm text-muted-foreground">
                Hesabınıza giriş yapın
            </p>
        </div>
        
        <?php if ($error): ?>
            <div class="rounded-md bg-red-50 p-4 border border-red-200">
                <div class="text-sm text-red-800">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            </div>
        <?php endif; ?>
        
        <form class="mt-8 space-y-6" method="POST" action="">
            <div class="space-y-4 rounded-md shadow-sm">
                <div>
                    <label for="username" class="block text-sm font-medium text-foreground mb-1.5">
                        Kullanıcı Adı
                    </label>
                    <input
                        id="username"
                        name="username"
                        type="text"
                        required
                        autofocus
                        class="relative block w-full rounded-md border border-input bg-background px-3 py-2 text-foreground placeholder:text-muted-foreground focus:z-10 focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring sm:text-sm"
                        placeholder="Kullanıcı adınızı girin"
                    >
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-foreground mb-1.5">
                        Şifre
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        class="relative block w-full rounded-md border border-input bg-background px-3 py-2 text-foreground placeholder:text-muted-foreground focus:z-10 focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring sm:text-sm"
                        placeholder="Şifrenizi girin"
                    >
                </div>
            </div>

            <div>
                <button
                    type="submit"
                    class="group relative flex w-full justify-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                >
                    Giriş Yap
                </button>
            </div>
        </form>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
