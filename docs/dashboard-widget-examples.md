# Dashboard Widget Örnekleri

## SQL COUNT Sorguları (sql_count)

### 1. Toplam Sayım Sayısı
```
SELECT COUNT(*) FROM sayimlar
```

### 2. Aktif Sayım Sayısı
```
SELECT COUNT(*) FROM sayimlar WHERE aktif = 1
```

### 3. Toplam Ürün Sayısı
```
SELECT COUNT(*) FROM sayim_icerikleri WHERE deleted_at IS NULL
```

### 4. Bugün Eklenen Ürün Sayısı
```
SELECT COUNT(*) FROM sayim_icerikleri WHERE DATE(okutulma_zamani) = DATE('now') AND deleted_at IS NULL
```

### 5. Toplam Kullanıcı Sayısı
```
SELECT COUNT(*) FROM users
```

### 6. Aktif Cloud Function Sayısı
```
SELECT COUNT(*) FROM cloud_functions WHERE enabled = 1
```

### 7. Aktif Cron Job Sayısı
```
SELECT COUNT(*) FROM cron_jobs WHERE enabled = 1
```

### 8. Bugün Çalışan Cron Sayısı
```
SELECT COUNT(*) FROM cron_log WHERE DATE(created_at) = DATE('now') AND status = 'success'
```

### 9. Toplam Sipariş Sayısı
```
SELECT COUNT(*) FROM integ_siparis
```

### 10. Bekleyen Sipariş Sayısı
```
SELECT COUNT(*) FROM integ_siparis WHERE toplandi_mi = 0 AND iptal_mi = 0
```

### 11. Toplam Ürün Tanımı Sayısı
```
SELECT COUNT(*) FROM urun_tanimi WHERE deleted_at IS NULL
```

### 12. Bugün İptal Edilen Sipariş Sayısı
```
SELECT COUNT(*) FROM integ_siparis WHERE iptal_mi = 1 AND DATE(updated_at) = DATE('now')
```

## SQL Liste Sorguları (sql_query)

### 13. Son 5 Sayım
```
SELECT sayim_no, aktif, created_at FROM sayimlar ORDER BY created_at DESC LIMIT 5
```

### 14. Son Eklenen Ürünler
```
SELECT si.barkod, si.urun_adi, s.sayim_no, si.okutulma_zamani 
FROM sayim_icerikleri si 
JOIN sayimlar s ON si.sayim_id = s.id 
WHERE si.deleted_at IS NULL 
ORDER BY si.okutulma_zamani DESC 
LIMIT 10
```

### 15. Son Kullanıcılar
```
SELECT username, created_at FROM users ORDER BY created_at DESC LIMIT 5
```

### 16. Aktif Cloud Functions
```
SELECT name, description, created_at FROM cloud_functions WHERE enabled = 1 ORDER BY created_at DESC LIMIT 5
```

### 17. Son Çalışan Cron'lar
```
SELECT cl.cron_name, cl.status, cl.message, cl.created_at 
FROM cron_log cl 
ORDER BY cl.created_at DESC 
LIMIT 10
```

### 18. Bekleyen Siparişler
```
SELECT siparis_no, created_at FROM integ_siparis 
WHERE toplandi_mi = 0 AND iptal_mi = 0 
ORDER BY created_at DESC 
LIMIT 5
```

### 19. Bugün Eklenen Ürün Tanımları
```
SELECT barkod, urun_aciklamasi, created_at 
FROM urun_tanimi 
WHERE DATE(created_at) = DATE('now') AND deleted_at IS NULL 
ORDER BY created_at DESC
```

### 20. Son 24 Saat İçinde Sayılan Ürünler
```
SELECT COUNT(*) as toplam, s.sayim_no 
FROM sayim_icerikleri si 
JOIN sayimlar s ON si.sayim_id = s.id 
WHERE si.okutulma_zamani >= datetime('now', '-24 hours') 
AND si.deleted_at IS NULL 
GROUP BY s.sayim_no 
ORDER BY toplam DESC 
LIMIT 5
```

## SQL Tek Değer Sorguları (sql_single)

### 21. En Son Eklenen Sayım Numarası
```
SELECT sayim_no FROM sayimlar ORDER BY created_at DESC LIMIT 1
```

### 22. Son Çalışan Cron Durumu
```
SELECT status FROM cron_log ORDER BY created_at DESC LIMIT 1
```

### 23. Toplam Dinamik Sayfa Sayısı
```
SELECT COUNT(*) FROM dynamic_pages
```

### 24. En Aktif Sayım (En Çok Ürün İçeren)
```
SELECT s.sayim_no FROM sayimlar s 
JOIN sayim_icerikleri si ON s.id = si.sayim_id 
WHERE si.deleted_at IS NULL 
GROUP BY s.id 
ORDER BY COUNT(si.id) DESC 
LIMIT 1
```

### 25. Son Kayıtlı Kullanıcı
```
SELECT username FROM users ORDER BY created_at DESC LIMIT 1
```

## İstatistiksel Widget'lar

### 26. Bugün Eklenen Toplam Ürün (COUNT)
```
SELECT COUNT(*) FROM sayim_icerikleri 
WHERE DATE(okutulma_zamani) = DATE('now') AND deleted_at IS NULL
```

### 27. Bu Ay Eklenen Ürünler (COUNT)
```
SELECT COUNT(*) FROM sayim_icerikleri 
WHERE strftime('%Y-%m', okutulma_zamani) = strftime('%Y-%m', 'now') 
AND deleted_at IS NULL
```

### 28. En Son İptal Edilen Sipariş (sql_single)
```
SELECT siparis_no FROM integ_siparis 
WHERE iptal_mi = 1 
ORDER BY updated_at DESC 
LIMIT 1
```

### 29. Toplanan Sipariş Sayısı (COUNT)
```
SELECT COUNT(*) FROM integ_siparis WHERE toplandi_mi = 1
```

### 30. Toplam Widget Sayısı (COUNT)
```
SELECT COUNT(*) FROM dashboard_widgets WHERE enabled = 1
```
