# Stok Sayım Sistemi

PHP ve SQLite kullanılarak geliştirilmiş stok sayım yönetim sistemi.

## Gereksinimler

- PHP 7.4 veya üzeri
- PHP PDO SQLite eklentisi
- Web sunucusu (Apache/Nginx) veya PHP yerleşik sunucusu

## Kurulum

### 1. Veritabanını Başlatma

Proje dizininde terminal'de şu komutu çalıştırın:

```bash
php scripts/init_db.php
```

Bu komut:
- Veritabanı tablolarını oluşturur
- Varsayılan admin kullanıcısını oluşturur (username: `admin`, password: `admin123`)

### 2. Web Sunucusu ile Çalıştırma

#### Seçenek A: PHP Yerleşik Sunucusu (Geliştirme için)

```bash
php -S localhost:8000
```

Tarayıcıda şu adrese gidin: `http://localhost:8000`

#### Seçenek B: Apache/Nginx (Üretim için)

Projeyi web sunucunuzun document root dizinine kopyalayın veya VirtualHost ayarlarını yapın.

## Giriş Bilgileri

Varsayılan admin kullanıcı bilgileri:
- **Kullanıcı Adı:** `admin`
- **Şifre:** `admin123`

**Önemli:** İlk girişten sonra şifrenizi değiştirmeniz önerilir.

## Proje Yapısı

```
StockCountWeb/
├── admin/              # Admin panel sayfaları
├── config/             # Yapılandırma dosyaları
├── database/           # SQLite veritabanı dosyası
├── includes/           # Ortak dosyalar
├── scripts/            # Yardımcı scriptler
├── index.php           # Giriş sayfası
└── logout.php          # Çıkış işlemi
```

## Özellikler

- Kullanıcı giriş sistemi
- Sayım oluşturma ve yönetimi
- Barkod bazlı ürün ekleme
- Sayım içeriklerini listeleme
- Aktif/Pasif sayım durumu yönetimi

## Güvenlik

- Şifreler hash'lenmiş olarak saklanır
- SQL injection koruması (PDO prepared statements)
- XSS koruması (htmlspecialchars)
- Güvenlik başlıkları (.htaccess)

