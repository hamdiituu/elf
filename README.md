# ELF - Enterprise Content Management Framework

**ELF** is a powerful, extensible, and developer-friendly Content Management System (CMS) designed for building custom web applications and content-driven platforms. Built with modern web technologies including PHP and Node.js, ELF offers flexible database support (SQLite and MySQL), cloud-based functions, dynamic page builder, and a fully containerized architecture for seamless deployment and scalability.

ELF empowers developers and content managers to create custom applications, build dynamic pages and forms, manage content efficiently, automate workflows through cloud functions, and customize the system to fit any business requirement. With its comprehensive developer tools and robust architecture, ELF adapts to projects of all sizes—from simple content sites to complex enterprise applications.

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
- **User Management**: Role-based authentication system (user and developer roles) with secure password hashing
- **Dynamic Page Builder**: Create custom pages and forms with database-backed content management
- **Cloud Functions**: Execute custom JavaScript code server-side for automation and custom logic
- **Cloud Middlewares**: Intercept and modify requests/responses with custom middleware functions
- **Database Explorer**: Visual database browser with query builder and table management
- **API Playground**: Test and debug API endpoints interactively
- **Cron Manager**: Schedule and manage background tasks with built-in cron system
- **Dashboard Widgets**: Create custom dashboard widgets with SQL queries
- **Content Management**: Flexible content structure with custom tables and dynamic forms
- **Developer Tools**: Comprehensive set of tools for developers to extend and customize
- **Docker Support**: Fully containerized with Docker Compose for easy deployment
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
APP_NAME=ELF

# Database Configuration
DB_TYPE=sqlite  # or 'mysql' for MySQL

# MySQL Configuration (only required if DB_TYPE=mysql)
MYSQL_HOST=mysql
MYSQL_PORT=3306
MYSQL_DATABASE=elf
MYSQL_USER=elf
MYSQL_PASSWORD=elf123
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
      "path": "database/elf.db"
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
   MYSQL_DATABASE=elf
   MYSQL_USER=elf
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
     - Database: `elf`
     - Username: `elf`
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

#### `dynamic_pages`
- `id`: Primary key
- `page_name`: Unique page identifier
- `page_title`: Display title
- `table_name`: Associated database table
- `group_name`: Grouping category
- `enable_list`, `enable_create`, `enable_update`, `enable_delete`: CRUD permissions
- `create_rule`, `update_rule`, `delete_rule`: Custom JavaScript validation rules
- `created_at`: Creation timestamp
- `updated_at`: Last update timestamp

#### `dashboard_widgets`
- `id`: Primary key
- `user_id`: Foreign key to `users`
- `title`: Widget title
- `widget_type`: Type (sql_count, sql_query, sql_single)
- `widget_config`: SQL query configuration
- `position`: Display order
- `width`: Grid width class
- `enabled`: Active status
- `created_at`: Creation timestamp
- `updated_at`: Last update timestamp

Note: Additional tables are created dynamically through the Dynamic Pages Builder feature.

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

All services are on `elf-network` bridge network, allowing them to communicate using service names (e.g., `mysql`, `php`).

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

- **Database Explorer**: Browse tables, execute queries, create/edit tables, and manage database schema
- **API Playground**: Test API endpoints with interactive interface
- **Cloud Functions**: Create and manage JavaScript cloud functions for server-side logic
- **Cloud Middlewares**: Create request/response middlewares to intercept and modify API calls
- **Cron Manager**: Schedule and manage background tasks with built-in cron daemon
- **Dynamic Pages Builder**: Create custom pages and forms with database-backed content
- **Dashboard Widgets**: Create custom dashboard widgets with SQL queries for data visualization

## Project Structure

```
ELF/
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
│   ├── elf.db             # SQLite database (if using SQLite)
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

### Creating Dynamic Pages

1. **Navigate to Admin Dashboard**
2. **Go to Pages Builder** (`/admin/pages-builder.php`)
3. **Create new page:**
   - Click "Create New Page"
   - Enter page name (URL-friendly identifier)
   - Enter page title (display name)
   - Define table structure or use existing table
   - Configure CRUD permissions (list, create, update, delete)
   - Add custom validation rules (optional)
   - Save

4. **Access your dynamic page:**
   - Navigate to `/admin/dynamic-page.php?page=your_page_name`
   - Use the interface to manage content
   - Data is stored in the associated database table

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

### Dashboard Widgets

Create custom dashboard widgets to display data:

1. **Go to Dashboard Widgets** (`/admin/dashboard-widgets.php`)
2. **Create new widget:**
   - Enter widget title
   - Select widget type:
     - **SQL Count**: Returns a single count number
     - **SQL Query**: Returns multiple rows of data
     - **SQL Single**: Returns a single value
   - Enter SQL query
   - Configure width and position
   - Save

3. **View widgets:**
   - Widgets appear on the main dashboard
   - Arrange and customize as needed

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
- Test connection: `docker-compose exec mysql mysql -u elf -p`
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
docker-compose exec php cp database/elf.db database/elf.db.backup

# Restore
docker-compose exec php cp database/elf.db.backup database/elf.db
```

**MySQL:**
```bash
# Backup
docker-compose exec mysql mysqldump -u elf -p elf > backup.sql

# Restore
docker-compose exec -T mysql mysql -u elf -p elf < backup.sql
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
