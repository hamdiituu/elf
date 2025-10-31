<?php
require_once 'config/config.php';

// If already logged in, redirect to admin panel
if (isLoggedIn()) {
    header('Location: admin/admin.php');
    exit;
}

$page_title = 'Giriş Yap - Vira Stok Sistemi';
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
            <h2 class="mt-6 text-center text-3xl font-bold tracking-tight text-foreground">
                Vira Stok Sistemi
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
