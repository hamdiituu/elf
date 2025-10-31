<?php
require_once '../config/config.php';
requireLogin();

$db = getDB();

// Handle sayim (count) operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_sayim':
                $sayim_no = trim($_POST['sayim_no'] ?? '');
                if (!empty($sayim_no)) {
                    try {
                        $stmt = $db->prepare("INSERT INTO sayimlar (sayim_no, aktif) VALUES (?, 1)");
                        $stmt->execute([$sayim_no]);
                        $success_message = "Sayım başarıyla eklendi!";
                    } catch (PDOException $e) {
                        $error_message = "Sayım eklenirken hata oluştu: " . $e->getMessage();
                    }
                }
                break;
                
            case 'toggle_active':
                $sayim_id = intval($_POST['sayim_id'] ?? 0);
                if ($sayim_id > 0) {
                    $stmt = $db->prepare("UPDATE sayimlar SET aktif = NOT aktif, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$sayim_id]);
                }
                header('Location: admin.php');
                exit;
                
            case 'add_icerik':
                $sayim_id = intval($_POST['sayim_id'] ?? 0);
                $barkod = trim($_POST['barkod'] ?? '');
                $urun_adi = trim($_POST['urun_adi'] ?? '');
                
                if ($sayim_id > 0 && !empty($barkod)) {
                    try {
                        $stmt = $db->prepare("INSERT INTO sayim_icerikleri (sayim_id, barkod, urun_adi) VALUES (?, ?, ?)");
                        $stmt->execute([$sayim_id, $barkod, $urun_adi]);
                        $success_message = "Ürün başarıyla eklendi!";
                    } catch (PDOException $e) {
                        $error_message = "Ürün eklenirken hata oluştu: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Get all sayimlar
$sayimlar = $db->query("SELECT * FROM sayimlar ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get all sayim_icerikleri with sayim info
$icerikler = $db->query("
    SELECT si.*, s.sayim_no, s.aktif as sayim_aktif
    FROM sayim_icerikleri si
    JOIN sayimlar s ON si.sayim_id = s.id
    ORDER BY si.okutulma_zamani DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get active sayimlar for dropdown
$aktif_sayimlar = $db->query("SELECT * FROM sayimlar WHERE aktif = 1 ORDER BY sayim_no")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Stok Sayım Sistemi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background: #343a40;
            padding: 20px 0;
        }
        .sidebar a {
            color: #fff;
            text-decoration: none;
            padding: 10px 20px;
            display: block;
        }
        .sidebar a:hover {
            background: #495057;
        }
        .content-area {
            padding: 20px;
        }
        .card {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-2 sidebar">
                <h4 class="text-white text-center mb-4">Admin Panel</h4>
                <a href="admin.php"><i class="bi bi-house"></i> Ana Sayfa</a>
                <a href="../logout.php"><i class="bi bi-box-arrow-right"></i> Çıkış Yap</a>
                <div class="mt-4 text-white px-3">
                    <small>Kullanıcı: <?php echo htmlspecialchars($_SESSION['username']); ?></small>
                </div>
            </nav>
            
            <main class="col-md-10 content-area">
                <h2 class="mb-4">Stok Sayım Yönetimi</h2>
                
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Add New Sayım -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Yeni Sayım Ekle</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_sayim">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="sayim_no" class="form-label">Sayım Numarası</label>
                                        <input type="text" class="form-control" id="sayim_no" name="sayim_no" required>
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary">Sayım Ekle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sayımlar List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Sayımlar</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Sayım No</th>
                                        <th>Durum</th>
                                        <th>Oluşturulma</th>
                                        <th>Güncellenme</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($sayimlar)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">Henüz sayım eklenmemiş.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($sayimlar as $sayim): ?>
                                            <tr>
                                                <td><?php echo $sayim['id']; ?></td>
                                                <td><strong><?php echo htmlspecialchars($sayim['sayim_no']); ?></strong></td>
                                                <td>
                                                    <?php if ($sayim['aktif']): ?>
                                                        <span class="badge bg-success">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Pasif</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($sayim['created_at'])); ?></td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($sayim['updated_at'])); ?></td>
                                                <td>
                                                    <form method="POST" action="" style="display: inline;">
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="sayim_id" value="<?php echo $sayim['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-warning">
                                                            <?php echo $sayim['aktif'] ? 'Pasif Yap' : 'Aktif Yap'; ?>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Add Sayım İçeriği -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-barcode"></i> Sayıma Ürün Ekle</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_icerik">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="sayim_id" class="form-label">Sayım Seçin</label>
                                        <select class="form-select" id="sayim_id" name="sayim_id" required>
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($aktif_sayimlar as $sayim): ?>
                                                <option value="<?php echo $sayim['id']; ?>">
                                                    <?php echo htmlspecialchars($sayim['sayim_no']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="barkod" class="form-label">Barkod</label>
                                        <input type="text" class="form-control" id="barkod" name="barkod" required autofocus>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="urun_adi" class="form-label">Ürün Adı</label>
                                        <input type="text" class="form-control" id="urun_adi" name="urun_adi">
                                    </div>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-success w-100">Ekle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sayım İçerikleri List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-table"></i> Sayım İçerikleri</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Sayım No</th>
                                        <th>Barkod</th>
                                        <th>Ürün Adı</th>
                                        <th>Okutulma Zamanı</th>
                                        <th>Sayım Durumu</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($icerikler)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">Henüz ürün eklenmemiş.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($icerikler as $icerik): ?>
                                            <tr>
                                                <td><?php echo $icerik['id']; ?></td>
                                                <td><strong><?php echo htmlspecialchars($icerik['sayim_no']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($icerik['barkod']); ?></td>
                                                <td><?php echo htmlspecialchars($icerik['urun_adi'] ?? '-'); ?></td>
                                                <td><?php echo date('d.m.Y H:i:s', strtotime($icerik['okutulma_zamani'])); ?></td>
                                                <td>
                                                    <?php if ($icerik['sayim_aktif']): ?>
                                                        <span class="badge bg-success">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Pasif</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-focus on barcode input after page load
        document.getElementById('barkod')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (document.getElementById('sayim_id').value && this.value) {
                    document.querySelector('form[action=""]:last-of-type').submit();
                }
            }
        });
    </script>
</body>
</html>

