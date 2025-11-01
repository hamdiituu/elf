# StockCount Web Application

A modern stock/inventory management system built with PHP, Node.js, and supporting both SQLite and MySQL databases. The application is fully containerized with Docker Compose for easy deployment and development.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Installation](#installation)
- [Configuration](#configuration)
- [Database Setup](#database-setup)
- [Docker Deployment](#docker-deployment)
- [Development](#development)
- [Project Structure](#project-structure)
- [Usage Guide](#usage-guide)
- [API Documentation](#api-documentation)
- [Troubleshooting](#troubleshooting)

## Features

- **Dual Database Support**: SQLite (default, for development) and MySQL (for production)
- **User Authentication**: Secure login system with password hashing
- **Inventory Management**: Create and manage stock counts
- **Barcode Scanning**: Barcode-based product entry
- **Cloud Functions**: JavaScript-based cloud functions execution
- **Dynamic Pages**: Build custom pages and forms
- **Developer Tools**: Database explorer, API playground, cron manager
- **Docker Support**: Fully containerized with Docker Compose
- **Node.js Integration**: Backend services for cloud functions execution

## Requirements

### For Docker Deployment (Recommended)

- Docker 20.10+
- Docker Compose 2.0+
- 2GB+ RAM
- 5GB+ disk space

### For Manual Installation

- PHP 8.0+ with extensions: pdo_mysql, pdo_sqlite, mysqli, mbstring, gd
- Node.js 18+
- MySQL 8.0+ (optional, if using MySQL)
- SQLite 3 (included with PHP)
- Web server (Apache/Nginx) or PHP built-in server
- Composer (optional, for dependency management)

## Quick Start

### Using Docker Compose (Recommended)

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd StockCountWeb
   ```

2. **Copy environment file**
   ```bash
   cp .env.example .env
   ```

3. **Edit environment variables** (optional, defaults work for development)
   ```bash
   nano .env
   ```

4. **Start the application**
   ```bash
   docker-compose up -d
   ```

5. **Initialize the database**
   ```bash
   docker-compose exec php php scripts/init_db.php
   ```

6. **Access the application**
   - Web Interface: http://localhost:8080
   - Default credentials:
     - Username: `admin`
     - Password: `admin123`

## Installation

### Step 1: Environment Configuration

Create a `.env` file in the project root:

```bash
cp .env.example .env
```

Edit `.env` file with your settings:

```env
# Application Configuration
APP_PORT=8080
APP_NAME=Vira Stock

# Database Configuration
DB_TYPE=sqlite  # or 'mysql' for MySQL

# MySQL Configuration (only required if DB_TYPE=mysql)
MYSQL_HOST=mysql
MYSQL_PORT=3306
MYSQL_DATABASE=stockcount
MYSQL_USER=stockcount
MYSQL_PASSWORD=stockcount123
MYSQL_ROOT_PASSWORD=rootpassword

# Node.js Configuration
NODE_ENV=production
NODE_PORT=3001
```

### Step 2: Start Docker Services

```bash
docker-compose up -d
```

This will start:
- **PHP-FPM**: PHP application server
- **Nginx**: Web server (port 8080)
- **Node.js**: Cloud functions server (port 3001)
- **MySQL**: Database server (port 3306, only if using MySQL)

### Step 3: Database Initialization

**For SQLite (default):**
```bash
docker-compose exec php php scripts/init_db.php
```

**For MySQL:**
1. First, configure MySQL in `config/settings.json` via admin panel or directly
2. Then run:
   ```bash
   docker-compose exec php php scripts/init_db.php
   ```

## Configuration

### Database Configuration

The application supports two database types:

#### SQLite (Default - Development)

SQLite is the default database, perfect for development and small deployments. No additional setup required.

Configuration in `config/settings.json`:
```json
{
  "db_type": "sqlite",
  "db_config": {
    "sqlite": {
      "path": "database/stockcount.db"
    }
  }
}
```

#### MySQL (Production)

For production environments or when you need a full database server:

1. **Configure MySQL in `.env` file:**
   ```env
   DB_TYPE=mysql
   MYSQL_HOST=mysql
   MYSQL_PORT=3306
   MYSQL_DATABASE=stockcount
   MYSQL_USER=stockcount
   MYSQL_PASSWORD=your_secure_password
   MYSQL_ROOT_PASSWORD=your_root_password
   ```

2. **Configure in Admin Panel:**
   - Login to admin panel
   - Go to Settings (`/admin/settings.php`)
   - Select "MySQL" as database type
   - Enter MySQL connection details:
     - Host: `mysql` (Docker) or `localhost` (external)
     - Port: `3306`
     - Database: `stockcount`
     - Username: `stockcount`
     - Password: (your password)
   - Click "Save Settings"

3. **Initialize Database:**
   ```bash
   docker-compose exec php php scripts/init_db.php
   ```

### Application Settings

Configure application settings via Admin Panel → Settings:

- **App Name**: Your application name
- **Logo**: Upload logo image (supports PNG, JPG)
- **Favicon**: Upload favicon (supports ICO, PNG)
- **Database Type**: SQLite or MySQL
- **Database Configuration**: Connection details

Settings are saved in `config/settings.json`.

## Database Setup

### Initialization Script

The `scripts/init_db.php` script:

1. Creates all required tables
2. Creates indexes for performance
3. Creates default admin user:
   - Username: `admin`
   - Password: `admin123`
   - User Type: `developer`

**Run initialization:**
```bash
# Using Docker
docker-compose exec php php scripts/init_db.php

# Manual installation
php scripts/init_db.php
```

### Database Tables

The application creates the following tables:

#### `users`
- `id`: Primary key
- `username`: Unique username
- `password`: Hashed password
- `user_type`: User type (`user` or `developer`)
- `created_at`: Creation timestamp

#### `sayimlar` (Inventories)
- `id`: Primary key
- `sayim_no`: Unique inventory number
- `aktif`: Active status (1 = active, 0 = inactive)
- `created_at`: Creation timestamp
- `updated_at`: Last update timestamp

#### `sayim_icerikleri` (Inventory Contents)
- `id`: Primary key
- `sayim_id`: Foreign key to `sayimlar`
- `barkod`: Product barcode
- `urun_adi`: Product name
- `okutulma_zamani`: Scan timestamp

### Migrations

Run migrations to update database schema:
```bash
docker-compose exec php php scripts/migrate_all.php
```

## Docker Deployment

### Docker Compose Services

The `docker-compose.yml` defines 4 services:

#### 1. PHP Service (`php`)
- **Image**: Custom PHP-FPM 8.2
- **Extensions**: PDO MySQL, PDO SQLite, GD, etc.
- **Volume**: Application code, database, uploads
- **Depends on**: MySQL

#### 2. Nginx Service (`nginx`)
- **Image**: Nginx Alpine
- **Port**: 8080 (configurable via `APP_PORT`)
- **Volume**: Application code
- **Depends on**: PHP

#### 3. Node.js Service (`nodejs`)
- **Image**: Node.js 20 Alpine
- **Port**: 3001 (internal)
- **Purpose**: Cloud functions execution
- **Depends on**: PHP, MySQL

#### 4. MySQL Service (`mysql`)
- **Image**: MySQL 8.0
- **Port**: 3306 (configurable via `MYSQL_PORT`)
- **Volume**: Persistent data storage
- **Environment**: Database credentials

### Docker Commands

**Start services:**
```bash
docker-compose up -d
```

**Stop services:**
```bash
docker-compose down
```

**View logs:**
```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f php
docker-compose logs -f nginx
docker-compose logs -f nodejs
docker-compose logs -f mysql
```

**Restart services:**
```bash
docker-compose restart
```

**Rebuild containers:**
```bash
docker-compose build --no-cache
docker-compose up -d
```

**Execute commands:**
```bash
# PHP commands
docker-compose exec php php scripts/init_db.php
docker-compose exec php php scripts/migrate_all.php

# Shell access
docker-compose exec php bash
docker-compose exec nodejs sh
```

**Stop and remove volumes (⚠️ destroys data):**
```bash
docker-compose down -v
```

### Volume Management

Docker Compose creates persistent volumes:

- **Application code**: Mounted from current directory
- **MySQL data**: `mysql-data` volume (persistent)
- **Database files**: `./database/` directory
- **Uploads**: `./uploads/` directory

### Network

All services are on `stockcount-network` bridge network, allowing them to communicate using service names (e.g., `mysql`, `php`).

## Development

### Local Development with Docker

1. **Start services:**
   ```bash
   docker-compose up -d
   ```

2. **Access application:**
   - http://localhost:8080

3. **View logs:**
   ```bash
   docker-compose logs -f
   ```

4. **Make changes:**
   - Edit files locally
   - Changes are reflected immediately (volumes mounted)

### Manual Installation (Without Docker)

1. **Install dependencies:**
   ```bash
   # PHP dependencies (if using Composer)
   composer install

   # Node.js dependencies
   npm install
   ```

2. **Configure database:**
   - Edit `config/settings.json` or use admin panel

3. **Initialize database:**
   ```bash
   php scripts/init_db.php
   ```

4. **Start PHP server:**
   ```bash
   php -S localhost:8000
   ```

5. **Start Node.js server:**
   ```bash
   node api/nodejs-server.js
   ```

### Development Tools

The application includes several developer tools (requires `developer` user type):

- **Database Explorer**: Browse and query database tables
- **API Playground**: Test API endpoints
- **Cloud Functions**: Create and manage JavaScript cloud functions
- **Cloud Middlewares**: Create request/response middlewares
- **Cron Manager**: Schedule and manage cron jobs
- **Dynamic Pages Builder**: Create custom pages and forms

## Project Structure

```
StockCountWeb/
├── admin/                  # Admin panel pages
│   ├── admin.php          # Main admin dashboard
│   ├── settings.php       # Application settings
│   ├── users.php          # User management
│   ├── database-explorer.php  # Database browser
│   └── ...
├── api/                    # API endpoints
│   ├── hello.php          # Test endpoint
│   ├── nodejs-server.js   # Node.js cloud functions server
│   └── cloud-functions/   # Cloud function handlers
├── config/                # Configuration files
│   ├── config.php         # Core configuration
│   └── settings.json      # Application settings (auto-generated)
├── cron/                  # Cron job system
│   ├── cron-daemon.php    # Cron daemon
│   └── common/            # Cron helpers
├── database/               # Database files
│   ├── stockcount.db      # SQLite database (if using SQLite)
│   └── init.sql           # MySQL initialization
├── docs/                  # Documentation
├── includes/              # Common includes
│   ├── header.php         # Page header
│   ├── footer.php         # Page footer
│   └── admin-sidebar.php  # Admin navigation
├── scripts/               # Utility scripts
│   ├── init_db.php        # Database initialization
│   └── migrate_all.php    # Database migrations
├── uploads/               # Uploaded files (logos, etc.)
├── nginx/                 # Nginx configuration
│   └── default.conf       # Nginx server config
├── docker-compose.yml     # Docker Compose configuration
├── Dockerfile.php         # PHP container image
├── Dockerfile.nginx       # Nginx container image
├── Dockerfile.nodejs      # Node.js container image
├── .env.example           # Environment variables template
├── .dockerignore          # Docker ignore patterns
└── README.md              # This file
```

## Usage Guide

### First Time Setup

1. **Start Docker services:**
   ```bash
   docker-compose up -d
   ```

2. **Initialize database:**
   ```bash
   docker-compose exec php php scripts/init_db.php
   ```

3. **Access login page:**
   - Open http://localhost:8080
   - Login with:
     - Username: `admin`
     - Password: `admin123`

4. **Configure application:**
   - Go to Settings
   - Upload logo and favicon
   - Configure database (if using MySQL)
   - Save settings

### User Management

1. **Login as admin/developer**
2. **Navigate to Users** (`/admin/users.php`)
3. **Create new user:**
   - Click "Add New User"
   - Enter username and password
   - Select user type: `user` or `developer`
   - Click "Create User"

### Creating Inventory Counts

1. **Navigate to Admin Dashboard**
2. **Go to Inventory Management**
3. **Create new count:**
   - Click "New Inventory"
   - Enter inventory number
   - Set active status
   - Save

4. **Add products:**
   - Select inventory
   - Scan or enter barcode
   - Product details will be added automatically

### Cloud Functions

Cloud functions allow you to execute custom JavaScript code:

1. **Go to Cloud Functions** (`/admin/cloud-functions.php`)
2. **Create new function:**
   - Enter function name
   - Write JavaScript code
   - Save

3. **Call function via API:**
   ```javascript
   POST /api/cloud-functions/execute.php
   {
     "function_name": "my_function",
     "data": {...}
   }
   ```

### Dynamic Pages

Create custom pages and forms:

1. **Go to Pages Builder** (`/admin/pages-builder.php`)
2. **Create new page:**
   - Enter page name and title
   - Define table structure
   - Configure permissions
   - Save

3. **Access page:**
   - Go to `/admin/dynamic-page.php?page=page_name`

## API Documentation

### Authentication

Most API endpoints require authentication via session cookies (login through web interface).

### Endpoints

#### Health Check
```
GET /api/hello.php
```

#### Cloud Functions Execution
```
POST /api/cloud-functions/execute.php
Content-Type: application/json

{
  "function_name": "function_name",
  "data": {...}
}
```

### Node.js Server API

The Node.js server (port 3001) handles cloud function execution:

- **Security**: Only accepts requests from localhost
- **Authentication**: Requires secret token
- **Database**: Automatically connects to configured database

## Troubleshooting

### Common Issues

#### 1. Database Connection Error

**SQLite:**
- Check file permissions: `chmod 755 database/`
- Check file path in `config/settings.json`
- Ensure SQLite extension is installed: `php -m | grep sqlite`

**MySQL:**
- Verify MySQL container is running: `docker-compose ps mysql`
- Check credentials in `config/settings.json`
- Test connection: `docker-compose exec mysql mysql -u stockcount -p`
- Check MySQL logs: `docker-compose logs mysql`

#### 2. Port Already in Use

Change port in `.env`:
```env
APP_PORT=8081
MYSQL_PORT=3307
```

#### 3. Permission Denied

Fix file permissions:
```bash
chmod -R 755 database/
chmod -R 755 uploads/
chmod -R 755 config/
```

#### 4. Node.js Server Not Starting

- Check Node.js logs: `docker-compose logs nodejs`
- Verify Node.js dependencies: `docker-compose exec nodejs npm list`
- Reinstall dependencies: `docker-compose exec nodejs npm install`

#### 5. Docker Build Fails

- Clear Docker cache: `docker-compose build --no-cache`
- Check Dockerfile syntax
- Verify base images are available

#### 6. PHP Extensions Missing

Verify extensions in PHP container:
```bash
docker-compose exec php php -m
```

Install missing extensions in `Dockerfile.php`.

### Debug Mode

Enable PHP error reporting:

1. Edit `config/config.php`
2. Add:
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

### Logs Location

- **Docker logs**: `docker-compose logs`
- **PHP errors**: Check container logs
- **Nginx access**: `/var/log/nginx/access.log` (in container)
- **Node.js logs**: Console output in container

### Database Backup

**SQLite:**
```bash
# Backup
docker-compose exec php cp database/stockcount.db database/stockcount.db.backup

# Restore
docker-compose exec php cp database/stockcount.db.backup database/stockcount.db
```

**MySQL:**
```bash
# Backup
docker-compose exec mysql mysqldump -u stockcount -p stockcount > backup.sql

# Restore
docker-compose exec -T mysql mysql -u stockcount -p stockcount < backup.sql
```

## Security Considerations

1. **Change default password**: Immediately change admin password after first login
2. **Use strong MySQL passwords**: Generate secure passwords for production
3. **Restrict file permissions**: Set appropriate permissions on sensitive directories
4. **Use HTTPS in production**: Configure SSL/TLS certificates
5. **Regular backups**: Schedule automated database backups
6. **Keep updated**: Regularly update Docker images and dependencies
7. **Network security**: In production, restrict Docker network access

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## License

[Specify your license here]

## Support

For issues and questions:
- Check [Troubleshooting](#troubleshooting) section
- Review Docker logs
- Check GitHub issues (if applicable)

## Changelog

### Version 1.0.0
- Initial release
- Docker Compose support
- SQLite and MySQL database support
- Cloud functions system
- Dynamic pages builder
- Developer tools
