# Cron Jobs Kullanım Kılavuzu

Bu klasör sistemde çalışacak cron job'ları içerir.

## Manuel Test (Geliştirme İçin)

Cron job'ı manuel olarak test etmek için:

```bash
php cron/example-cron.php
```

## Otomatik Çalıştırma (Crontab ile)

### 1. Crontab'ı Düzenle

```bash
crontab -e
```

### 2. Cron Job Ekle

Her dakika çalışacak şekilde ekleyin:

```bash
* * * * * /usr/bin/php /Users/hamditug/Hamdis/StockCountWeb/cron/example-cron.php >> /Users/hamditug/Hamdis/StockCountWeb/cron/example-cron.log 2>&1
```

**Önemli:** Yolu (`/Users/hamditug/Hamdis/StockCountWeb`) kendi proje yolunuzla değiştirin!

### 3. Mevcut Crontab'ı Kontrol Et

```bash
crontab -l
```

### 4. Logları Kontrol Et

#### Database Logları

Loglar `cron_log` tablosuna kaydedilir. Database Explorer'dan veya SQL ile görüntüleyebilirsiniz:

```sql
SELECT * FROM cron_log ORDER BY started_at DESC LIMIT 10;
```

#### Dosya Logları

Dosya logları `cron/example-cron.log` dosyasına yazılır:

```bash
tail -f cron/example-cron.log
```

## Crontab Format

```
* * * * * komut
│ │ │ │ │
│ │ │ │ └─── Haftanın günü (0-7, 0 ve 7 = Pazar)
│ │ │ └───── Ay (1-12)
│ │ └─────── Ayın günü (1-31)
│ └───────── Saat (0-23)
└─────────── Dakika (0-59)
```

### Zamanlama Örnekleri

```bash
# Her dakika
* * * * * /usr/bin/php /path/to/cron/example-cron.php

# Her 5 dakikada bir
*/5 * * * * /usr/bin/php /path/to/cron/example-cron.php

# Her saat başı
0 * * * * /usr/bin/php /path/to/cron/example-cron.php

# Her gün saat 02:00'da
0 2 * * * /usr/bin/php /path/to/cron/example-cron.php

# Her pazartesi saat 09:00'da
0 9 * * 1 /usr/bin/php /path/to/cron/example-cron.php

# Her ayın 1'inde saat 00:00'da
0 0 1 * * /usr/bin/php /path/to/cron/example-cron.php
```

## Cron Helper Kullanımı

Yeni bir cron job oluştururken `cron/common/cron-helper.php` helper'ını kullanabilirsiniz:

```php
<?php
require_once __DIR__ . '/common/cron-helper.php';

$cron_name = 'my-cron-job';
$log_file = __DIR__ . '/my-cron.log';

// Başlangıç
cronLog($cron_name, 'started', 'İşlem başlatıldı');
cronLogToFile("Starting...", $log_file);

$start_time = microtime(true);

try {
    // İşlemleriniz burada
    // ...
    
    $end_time = microtime(true);
    $execution_time = round(($end_time - $start_time) * 1000, 2);
    
    // Başarılı bitiş
    cronLog($cron_name, 'success', 'İşlem tamamlandı', $execution_time);
    cronLogToFile("Completed in {$execution_time}ms", $log_file);
    
} catch (Exception $e) {
    $end_time = microtime(true);
    $execution_time = round(($end_time - $start_time) * 1000, 2);
    
    // Hata durumu
    cronLog($cron_name, 'failed', 'Hata oluştu', $execution_time, $e->getMessage());
    cronLogToFile("Error: " . $e->getMessage(), $log_file);
    
    exit(1);
}

exit(0);
?>
```

## Log Fonksiyonları

Helper içinde şu fonksiyonlar mevcuttur:

- `cronLog($cron_name, $status, $message, $execution_time_ms, $error_message)`: Veritabanına log kaydı
- `getCronLogs($cron_name, $status, $limit)`: Log kayıtlarını getir
- `getLatestCronLog($cron_name)`: En son log kaydını getir
- `cronLogToFile($message, $log_file)`: Dosyaya log yaz

## Güvenlik

- Cron job dosyaları `.htaccess` ile web erişiminden korunmuştur
- Log dosyalarını düzenli olarak temizleyin
- Cron job'ları sadece güvenilir kullanıcılarla çalıştırın

