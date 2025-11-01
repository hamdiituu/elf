<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$page_title = 'Siparişler - Vira Stok Sistemi';
$db = getDB();

// Handle operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $stmt = $db->prepare("INSERT INTO integ_siparis (siparis_no, iptal_mi, toplaniyor_mu, toplandi_mi) VALUES (?, ?, ?, ?)");
                    $values = [];
                    if (isset($_POST['siparis_no'])) {
                        $values[] = trim($_POST['siparis_no']);
                    } else {
                        $values[] = '';
                    }
                    if (isset($_POST['iptal_mi'])) {
                        $values[] = intval($_POST['iptal_mi']);
                    } else {
                        $values[] = '';
                    }
                    if (isset($_POST['toplaniyor_mu'])) {
                        $values[] = intval($_POST['toplaniyor_mu']);
                    } else {
                        $values[] = '';
                    }
                    if (isset($_POST['toplandi_mi'])) {
                        $values[] = intval($_POST['toplandi_mi']);
                    } else {
                        $values[] = '';
                    }
                    $stmt->execute($values);
                    $success_message = "Kayıt başarıyla eklendi!";
                } catch (PDOException $e) {
                    $error_message = "Kayıt eklenirken hata: " . $e->getMessage();
                }
                break;

            case 'update':
                $record_id = intval($_POST['record_id'] ?? 0);
                if ($record_id > 0) {
                    try {
                        $set_parts = [];
                        $values = [];
                        if (isset($_POST['siparis_no'])) {
                            $set_parts[] = "siparis_no = ?";
                            $values[] = trim($_POST['siparis_no']);
                        }
                        if (isset($_POST['iptal_mi'])) {
                            $set_parts[] = "iptal_mi = ?";
                            $values[] = intval($_POST['iptal_mi']);
                        }
                        if (isset($_POST['toplaniyor_mu'])) {
                            $set_parts[] = "toplaniyor_mu = ?";
                            $values[] = intval($_POST['toplaniyor_mu']);
                        }
                        if (isset($_POST['toplandi_mi'])) {
                            $set_parts[] = "toplandi_mi = ?";
                            $values[] = intval($_POST['toplandi_mi']);
                        }
                        $values[] = $record_id;
                        $stmt = $db->prepare("UPDATE integ_siparis SET " . implode(", ", $set_parts) . " WHERE id = ?");
                        $stmt->execute($values);
                        $success_message = "Kayıt başarıyla güncellendi!";
                    } catch (PDOException $e) {
                        $error_message = "Kayıt güncellenirken hata: " . $e->getMessage();
                    }
                }
                break;

            case 'delete':
                $record_id = intval($_POST['record_id'] ?? 0);
                if ($record_id > 0) {
                    try {
                        $stmt = $db->prepare("DELETE FROM integ_siparis WHERE id = ?");
                        $stmt->execute([$record_id]);
                        $success_message = "Kayıt başarıyla silindi!";
                    } catch (PDOException $e) {
                        $error_message = "Kayıt silinirken hata: " . $e->getMessage();
                    }
                }
                header('Location: integ_siparis.php');
                exit;
                break;

        }
    }
}

// Get filter parameters
$filters = [];
$where_conditions = [];
$where_values = [];

// Filter for siparis_no
if (!empty($_GET['filter_siparis_no'])) {
    $filters['siparis_no'] = trim($_GET['filter_siparis_no']);
    $where_conditions[] = "siparis_no LIKE ?";
    $where_values[] = '%' . $filters['siparis_no'] . '%';
}

// Filter for iptal_mi
if (isset($_GET['filter_iptal_mi']) && $_GET['filter_iptal_mi'] !== '') {
    $filters['iptal_mi'] = trim($_GET['filter_iptal_mi']);
    $where_conditions[] = "iptal_mi = ?";
    $where_values[] = $filters['iptal_mi'];
}
if (isset($_GET['filter_iptal_mi_min']) && $_GET['filter_iptal_mi_min'] !== '') {
    $where_conditions[] = "iptal_mi >= ?";
    $where_values[] = intval($_GET['filter_iptal_mi_min']);
}
if (isset($_GET['filter_iptal_mi_max']) && $_GET['filter_iptal_mi_max'] !== '') {
    $where_conditions[] = "iptal_mi <= ?";
    $where_values[] = intval($_GET['filter_iptal_mi_max']);
}

// Filter for toplaniyor_mu
if (isset($_GET['filter_toplaniyor_mu']) && $_GET['filter_toplaniyor_mu'] !== '') {
    $filters['toplaniyor_mu'] = trim($_GET['filter_toplaniyor_mu']);
    $where_conditions[] = "toplaniyor_mu = ?";
    $where_values[] = $filters['toplaniyor_mu'];
}
if (isset($_GET['filter_toplaniyor_mu_min']) && $_GET['filter_toplaniyor_mu_min'] !== '') {
    $where_conditions[] = "toplaniyor_mu >= ?";
    $where_values[] = intval($_GET['filter_toplaniyor_mu_min']);
}
if (isset($_GET['filter_toplaniyor_mu_max']) && $_GET['filter_toplaniyor_mu_max'] !== '') {
    $where_conditions[] = "toplaniyor_mu <= ?";
    $where_values[] = intval($_GET['filter_toplaniyor_mu_max']);
}

// Filter for toplandi_mi
if (isset($_GET['filter_toplandi_mi']) && $_GET['filter_toplandi_mi'] !== '') {
    $filters['toplandi_mi'] = trim($_GET['filter_toplandi_mi']);
    $where_conditions[] = "toplandi_mi = ?";
    $where_values[] = $filters['toplandi_mi'];
}
if (isset($_GET['filter_toplandi_mi_min']) && $_GET['filter_toplandi_mi_min'] !== '') {
    $where_conditions[] = "toplandi_mi >= ?";
    $where_values[] = intval($_GET['filter_toplandi_mi_min']);
}
if (isset($_GET['filter_toplandi_mi_max']) && $_GET['filter_toplandi_mi_max'] !== '') {
    $where_conditions[] = "toplandi_mi <= ?";
    $where_values[] = intval($_GET['filter_toplandi_mi_max']);
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get total count
$count_stmt = $db->prepare("SELECT COUNT(*) FROM integ_siparis $where_sql");
if (!empty($where_values)) {
    $count_stmt->execute($where_values);
} else {
    $count_stmt->execute();
}
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Sorting
$sort_column = $_GET['sort'] ?? 'id';
$sort_order = strtoupper($_GET['order'] ?? 'DESC');
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'DESC';
}
$valid_columns = ['id', 'siparis_no', 'iptal_mi', 'toplaniyor_mu', 'toplandi_mi'];
if (!in_array($sort_column, $valid_columns)) {
    $sort_column = 'id';
}

// Get records with pagination
$sql = "SELECT * FROM integ_siparis $where_sql ORDER BY $sort_column $sort_order LIMIT $per_page OFFSET $offset";
$stmt = $db->prepare($sql);
if (!empty($where_values)) {
    $stmt->execute($where_values);
} else {
    $stmt->execute();
}
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing
$edit_record = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id && 1) {
    $stmt = $db->prepare("SELECT * FROM integ_siparis WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Siparişler</h1>

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

                <!-- Add/Edit Form -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">
                            <?php echo $edit_record ? 'Kayıt Düzenle' : 'Yeni Kayıt Ekle'; ?>
                        </h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="<?php echo $edit_record ? 'update' : 'add'; ?>">
                            <?php if ($edit_record): ?>
                                <input type="hidden" name="record_id" value="<?php echo $edit_record['id']; ?>">
                            <?php endif; ?>

                            <div class="mb-4">
                                <label for="siparis_no" class="block text-sm font-medium text-foreground mb-1.5">
                                    Siparis no
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="siparis_no"
                                    name="siparis_no"
                                    required
                                    value="<?php echo $edit_record ? htmlspecialchars($edit_record['siparis_no']) : ''; ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Siparis no giriniz"
                                >
                            </div>

                            <div class="mb-4">
                                <label for="iptal_mi" class="block text-sm font-medium text-foreground mb-1.5">
                                    Iptal mi
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="number"
                                    id="iptal_mi"
                                    name="iptal_mi"
                                    required
                                    value="<?php echo $edit_record ? htmlspecialchars($edit_record['iptal_mi']) : ''; ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Iptal mi giriniz"
                                >
                            </div>

                            <div class="mb-4">
                                <label for="toplaniyor_mu" class="block text-sm font-medium text-foreground mb-1.5">
                                    Toplaniyor mu
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="number"
                                    id="toplaniyor_mu"
                                    name="toplaniyor_mu"
                                    required
                                    value="<?php echo $edit_record ? htmlspecialchars($edit_record['toplaniyor_mu']) : ''; ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Toplaniyor mu giriniz"
                                >
                            </div>

                            <div class="mb-4">
                                <label for="toplandi_mi" class="block text-sm font-medium text-foreground mb-1.5">
                                    Toplandi mi
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="number"
                                    id="toplandi_mi"
                                    name="toplandi_mi"
                                    required
                                    value="<?php echo $edit_record ? htmlspecialchars($edit_record['toplandi_mi']) : ''; ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Toplandi mi giriniz"
                                >
                            </div>

                            <div class="flex gap-2">
                                <button
                                    type="submit"
                                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                                >
                                    <?php echo $edit_record ? 'Güncelle' : 'Ekle'; ?>
                                </button>
                                <?php if ($edit_record): ?>
                                    <a
                                        href="integ_siparis.php"
                                        class="rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-500 transition-all"
                                    >
                                        İptal
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Filtreleme</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="GET" action="" class="space-y-4">
                            <?php if (isset($_GET['edit'])): ?>
                                <input type="hidden" name="edit" value="<?php echo htmlspecialchars($_GET['edit']); ?>">
                            <?php endif; ?>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div>
                                    <label for="filter_siparis_no" class="block text-sm font-medium text-foreground mb-1.5">Siparis no</label>
                                    <input
                                        type="text"
                                        id="filter_siparis_no"
                                        name="filter_siparis_no"
                                        value="<?php echo htmlspecialchars($_GET['filter_siparis_no'] ?? ''); ?>"
                                        placeholder="Siparis no ara..."
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_iptal_mi" class="block text-sm font-medium text-foreground mb-1.5">Iptal mi (Eşit)</label>
                                    <input
                                        type="number"
                                        id="filter_iptal_mi"
                                        name="filter_iptal_mi"
                                        value="<?php echo htmlspecialchars($_GET['filter_iptal_mi'] ?? ''); ?>"
                                        placeholder="Iptal mi"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_iptal_mi_min" class="block text-sm font-medium text-foreground mb-1.5">Iptal mi (Min)</label>
                                    <input
                                        type="number"
                                        id="filter_iptal_mi_min"
                                        name="filter_iptal_mi_min"
                                        value="<?php echo htmlspecialchars($_GET['filter_iptal_mi_min'] ?? ''); ?>"
                                        placeholder="Min"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_iptal_mi_max" class="block text-sm font-medium text-foreground mb-1.5">Iptal mi (Max)</label>
                                    <input
                                        type="number"
                                        id="filter_iptal_mi_max"
                                        name="filter_iptal_mi_max"
                                        value="<?php echo htmlspecialchars($_GET['filter_iptal_mi_max'] ?? ''); ?>"
                                        placeholder="Max"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_toplaniyor_mu" class="block text-sm font-medium text-foreground mb-1.5">Toplaniyor mu (Eşit)</label>
                                    <input
                                        type="number"
                                        id="filter_toplaniyor_mu"
                                        name="filter_toplaniyor_mu"
                                        value="<?php echo htmlspecialchars($_GET['filter_toplaniyor_mu'] ?? ''); ?>"
                                        placeholder="Toplaniyor mu"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_toplaniyor_mu_min" class="block text-sm font-medium text-foreground mb-1.5">Toplaniyor mu (Min)</label>
                                    <input
                                        type="number"
                                        id="filter_toplaniyor_mu_min"
                                        name="filter_toplaniyor_mu_min"
                                        value="<?php echo htmlspecialchars($_GET['filter_toplaniyor_mu_min'] ?? ''); ?>"
                                        placeholder="Min"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_toplaniyor_mu_max" class="block text-sm font-medium text-foreground mb-1.5">Toplaniyor mu (Max)</label>
                                    <input
                                        type="number"
                                        id="filter_toplaniyor_mu_max"
                                        name="filter_toplaniyor_mu_max"
                                        value="<?php echo htmlspecialchars($_GET['filter_toplaniyor_mu_max'] ?? ''); ?>"
                                        placeholder="Max"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_toplandi_mi" class="block text-sm font-medium text-foreground mb-1.5">Toplandi mi (Eşit)</label>
                                    <input
                                        type="number"
                                        id="filter_toplandi_mi"
                                        name="filter_toplandi_mi"
                                        value="<?php echo htmlspecialchars($_GET['filter_toplandi_mi'] ?? ''); ?>"
                                        placeholder="Toplandi mi"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_toplandi_mi_min" class="block text-sm font-medium text-foreground mb-1.5">Toplandi mi (Min)</label>
                                    <input
                                        type="number"
                                        id="filter_toplandi_mi_min"
                                        name="filter_toplandi_mi_min"
                                        value="<?php echo htmlspecialchars($_GET['filter_toplandi_mi_min'] ?? ''); ?>"
                                        placeholder="Min"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_toplandi_mi_max" class="block text-sm font-medium text-foreground mb-1.5">Toplandi mi (Max)</label>
                                    <input
                                        type="number"
                                        id="filter_toplandi_mi_max"
                                        name="filter_toplandi_mi_max"
                                        value="<?php echo htmlspecialchars($_GET['filter_toplandi_mi_max'] ?? ''); ?>"
                                        placeholder="Max"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="per_page" class="block text-sm font-medium text-foreground mb-1.5">Sayfa Başına Kayıt</label>
                                    <select
                                        id="per_page"
                                        name="per_page"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        onchange="this.form.submit()"
                                    >
                                        <option value="10" <?php echo ($per_page == 10) ? 'selected' : ''; ?>>10</option>
                                        <option value="20" <?php echo ($per_page == 20) ? 'selected' : ''; ?>>20</option>
                                        <option value="50" <?php echo ($per_page == 50) ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo ($per_page == 100) ? 'selected' : ''; ?>>100</option>
                                    </select>
                                </div>
                            </div>

                            <div class="flex gap-2 pt-2">
                                <button
                                    type="submit"
                                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                                >
                                    Filtrele
                                </button>
                                <a
                                    href="integ_siparis.php"
                                    class="rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-500 transition-all"
                                >
                                    Temizle
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Records List -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold leading-none tracking-tight">Tüm Kayıtlar</h3>
                            <div class="text-sm text-muted-foreground">
                                Toplam: <span class="font-medium text-foreground"><?php echo $total_records; ?></span> kayıt
                            </div>
                        </div>
                    </div>
                    <div class="p-6 pt-0">
                        <?php if (empty($records)): ?>
                            <div class="text-center py-8 text-muted-foreground">
                                Henüz kayıt eklenmemiş veya filtre sonucu bulunamadı.
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                                                <a href="?<?php
                                                    $params = $_GET;
                                                    $params['sort'] = 'id';
                                                    if (isset($params['sort']) && $params['sort'] == 'id' && isset($params['order']) && $params['order'] == 'ASC') {
                                                        $params['order'] = 'DESC';
                                                    } else {
                                                        $params['order'] = 'ASC';
                                                    }
                                                    echo http_build_query($params);
                                                ?>
" class="flex items-center gap-1 hover:text-foreground">
                                                    <span>Id</span>
                                                    <?php if ($sort_column == 'id'): ?>
                                                        <span class="text-xs"><?php echo $sort_order == 'ASC' ? '↑' : '↓'; ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                                                <a href="?<?php
                                                    $params = $_GET;
                                                    $params['sort'] = 'siparis_no';
                                                    if (isset($params['sort']) && $params['sort'] == 'siparis_no' && isset($params['order']) && $params['order'] == 'ASC') {
                                                        $params['order'] = 'DESC';
                                                    } else {
                                                        $params['order'] = 'ASC';
                                                    }
                                                    echo http_build_query($params);
                                                ?>
" class="flex items-center gap-1 hover:text-foreground">
                                                    <span>Siparis no</span>
                                                    <?php if ($sort_column == 'siparis_no'): ?>
                                                        <span class="text-xs"><?php echo $sort_order == 'ASC' ? '↑' : '↓'; ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                                                <a href="?<?php
                                                    $params = $_GET;
                                                    $params['sort'] = 'iptal_mi';
                                                    if (isset($params['sort']) && $params['sort'] == 'iptal_mi' && isset($params['order']) && $params['order'] == 'ASC') {
                                                        $params['order'] = 'DESC';
                                                    } else {
                                                        $params['order'] = 'ASC';
                                                    }
                                                    echo http_build_query($params);
                                                ?>
" class="flex items-center gap-1 hover:text-foreground">
                                                    <span>Iptal mi</span>
                                                    <?php if ($sort_column == 'iptal_mi'): ?>
                                                        <span class="text-xs"><?php echo $sort_order == 'ASC' ? '↑' : '↓'; ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                                                <a href="?<?php
                                                    $params = $_GET;
                                                    $params['sort'] = 'toplaniyor_mu';
                                                    if (isset($params['sort']) && $params['sort'] == 'toplaniyor_mu' && isset($params['order']) && $params['order'] == 'ASC') {
                                                        $params['order'] = 'DESC';
                                                    } else {
                                                        $params['order'] = 'ASC';
                                                    }
                                                    echo http_build_query($params);
                                                ?>
" class="flex items-center gap-1 hover:text-foreground">
                                                    <span>Toplaniyor mu</span>
                                                    <?php if ($sort_column == 'toplaniyor_mu'): ?>
                                                        <span class="text-xs"><?php echo $sort_order == 'ASC' ? '↑' : '↓'; ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                                                <a href="?<?php
                                                    $params = $_GET;
                                                    $params['sort'] = 'toplandi_mi';
                                                    if (isset($params['sort']) && $params['sort'] == 'toplandi_mi' && isset($params['order']) && $params['order'] == 'ASC') {
                                                        $params['order'] = 'DESC';
                                                    } else {
                                                        $params['order'] = 'ASC';
                                                    }
                                                    echo http_build_query($params);
                                                ?>
" class="flex items-center gap-1 hover:text-foreground">
                                                    <span>Toplandi mi</span>
                                                    <?php if ($sort_column == 'toplandi_mi'): ?>
                                                        <span class="text-xs"><?php echo $sort_order == 'ASC' ? '↑' : '↓'; ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($records as $record): ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/50">
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['id'] ?? ''); ?></td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['siparis_no'] ?? ''); ?></td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['iptal_mi'] ?? ''); ?></td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['toplaniyor_mu'] ?? ''); ?></td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($record['toplandi_mi'] ?? ''); ?></td>
                                                <td class="p-4 align-middle">
                                                    <div class="flex gap-2">
                                                        <a
                                                            href="?edit=<?php echo $record['id']; ?>"
                                                            class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-blue-100 text-blue-800 hover:bg-blue-200 h-9 px-3"
                                                        >
                                                            Düzenle
                                                        </a>
                                                        <form method="POST" action="" class="inline" onsubmit="return confirm('Bu kaydı silmek istediğinizden emin misiniz?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-red-100 text-red-800 hover:bg-red-200 h-9 px-3"
                                                            >
                                                                Sil
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <div class="mt-6 flex items-center justify-between border-t border-border pt-4">
                                    <div class="text-sm text-muted-foreground">
                                        Sayfa <span class="font-medium text-foreground"><?php echo $page; ?></span> / <span class="font-medium text-foreground"><?php echo $total_pages; ?></span>
                                        (Gösterilen: <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $per_page, $total_records); ?> / <?php echo $total_records; ?>)
                                    </div>
                                    <div class="flex gap-2">
                                        <?php if ($page > 1): ?>
                                            <a
                                                href="?<?php
                                                    $params = $_GET;
                                                    $params['page'] = $page - 1;
                                                    echo http_build_query($params);
                                                ?>
"
                                                class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-accent hover:text-accent-foreground transition-colors"
                                            >
                                                Önceki
                                            </a>
                                        <?php else: ?>
                                            <span class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-muted-foreground opacity-50 cursor-not-allowed">
                                                Önceki
                                            </span>
                                        <?php endif; ?>

                                        <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            for ($i = $start_page; $i <= $end_page; $i++):
                                        ?>
                                            <a
                                                href="?<?php
                                                    $params = $_GET;
                                                    $params['page'] = $i;
                                                    echo http_build_query($params);
                                                ?>
"
                                                class="inline-flex items-center justify-center rounded-md border <?php echo $i == $page ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background text-foreground hover:bg-accent'; ?> px-3 py-2 text-sm font-medium transition-colors"
                                            >
                                                <?php echo $i; ?>
                                            </a>
                                        <?php endfor; ?>

                                        <?php if ($page < $total_pages): ?>
                                            <a
                                                href="?<?php
                                                    $params = $_GET;
                                                    $params['page'] = $page + 1;
                                                    echo http_build_query($params);
                                                ?>
"
                                                class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-accent hover:text-accent-foreground transition-colors"
                                            >
                                                Sonraki
                                            </a>
                                        <?php else: ?>
                                            <span class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-muted-foreground opacity-50 cursor-not-allowed">
                                                Sonraki
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php include '../includes/footer.php'; ?>
