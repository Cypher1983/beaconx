# BeaconX

**The Official Monitoring Signal for WatchTowerX.**

BeaconX is a comprehensive Laravel package designed to be installed on client applications. It gathers extensive system, application, and infrastructure health metrics and transmits them back to your central **WatchTowerX** hub for monitoring and alerting.

## Key Features

✅ **Comprehensive Monitoring** - Collects system, application, and database metrics  
✅ **Automatic Scheduling** - Set up once and forget with scheduled transmissions  
✅ **Multi-Database Support** - Works with MySQL, PostgreSQL, SQL Server, and MariaDB  
✅ **Elevated Privileges** - Optional monitoring user for advanced database metrics  
✅ **Docker Compatible** - Works seamlessly in containerized environments  
✅ **Multi-Tenant Ready** - Full compatibility with Laravel multi-tenancy packages  
✅ **Graceful Degradation** - Returns safe defaults if metrics unavailable  
✅ **Secure** - HTTPS transmission with token authentication  
✅ **Cross-Platform** - Supports Windows, Linux, and macOS  
✅ **Zero Configuration** - Works out of the box with sensible defaults

---

## Quick Start

Get BeaconX monitoring in 3 minutes:

```bash
# 1. Require the package
composer require watchtowerx/beaconx

# 2. Publish configuration
php artisan vendor:publish --tag=beacon-config

# 3. Add to .env
echo "WATCHTOWER_HUB_URL=https://your-hub.com/api/v1/report" >> .env
echo "WATCHTOWER_API_TOKEN=your_token_here" >> .env

# 4. Schedule the command (in routes/console.php)
Schedule::command('beacon:transmit')->everyMinute();

# 5. Test it
php artisan beacon:transmit
```

That's it! BeaconX is now monitoring your application.

---

## Installation

### 1. Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, 12.x, or 13.x

### 2. Add Repository to Composer

Since BeaconX is hosted on GitHub and not available on Packagist, you need to add the repository to your `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/watchtowerx/beaconx.git"
    }
  ]
}
```

### 3. Install via Composer

Add the package to your Laravel application:

```bash
composer require watchtowerx/beaconx
```

### 4. Publish Configuration

Publish the beacon.php config file to your application's config directory:

```bash
php artisan vendor:publish --tag=beacon-config
```

## Configuration

### Basic Setup

Add the following keys to your .env file. You can generate the API_TOKEN within your WatchTowerX dashboard.

```env
WATCHTOWER_HUB_URL=https://your-watchtower-hub.com/api/v1/report
WATCHTOWER_API_TOKEN=your_unique_site_token
```

### Elevated Database Credentials (Optional but Recommended)

BeaconX can monitor advanced database metrics like active locks and transaction counts. These metrics require elevated database privileges that may exceed your standard application database user's permissions.

#### Why Use Elevated Credentials?

- **Database Lock Monitoring**: Track active locks and wait events
- **Transaction Monitoring**: Monitor transaction counts and states
- **Performance Schema Access**: Query MySQL's `performance_schema` for detailed metrics
- **Privilege Isolation**: Keep elevated credentials separate from your main application user

#### Step-by-Step Setup Guide

##### **Step 1: Create Elevated Database User**

Create a new database user with monitoring privileges. Choose your database type:

**For MySQL/MariaDB:**

```sql
-- Connect to MySQL as root or admin user
mysql -u root -p

-- Create monitoring user
CREATE USER 'beacon_monitor'@'localhost' IDENTIFIED BY 'secure_password_here';

-- Grant monitoring privileges
GRANT USAGE ON *.* TO 'beacon_monitor'@'localhost';
GRANT PROCESS ON *.* TO 'beacon_monitor'@'localhost';
GRANT SELECT ON performance_schema.* TO 'beacon_monitor'@'localhost';
GRANT SELECT ON information_schema.* TO 'beacon_monitor'@'localhost';
GRANT REPLICATION CLIENT ON *.* TO 'beacon_monitor'@'localhost';  -- for SHOW BINARY LOGS
GRANT REPLICATION SLAVE ON *.* TO 'beacon_monitor'@'localhost';   -- for SHOW SLAVE/REPLICA STATUS

-- For older MySQL versions (5.7), also grant innodb_locks access:
GRANT SELECT ON information_schema.innodb_locks TO 'beacon_monitor'@'localhost';

-- Apply changes
FLUSH PRIVILEGES;

-- Verify user creation
SELECT user, host FROM mysql.user WHERE user = 'beacon_monitor';
```

**For PostgreSQL:**

```sql
-- Connect as postgres or superuser
psql -U postgres

-- Create monitoring user
CREATE USER beacon_monitor WITH PASSWORD 'secure_password_here';

-- Grant monitoring privileges
GRANT CONNECT ON DATABASE your_app_database TO beacon_monitor;
GRANT USAGE ON SCHEMA public TO beacon_monitor;
GRANT SELECT ON pg_locks TO beacon_monitor;
GRANT SELECT ON pg_stat_activity TO beacon_monitor;
GRANT SELECT ON pg_stat_bgwriter TO beacon_monitor;
GRANT SELECT ON pg_stat_database TO beacon_monitor;
GRANT SELECT ON pg_stat_user_tables TO beacon_monitor;
GRANT SELECT ON pg_stat_user_indexes TO beacon_monitor;
GRANT SELECT ON pg_statio_user_tables TO beacon_monitor;
GRANT SELECT ON pg_replication_slots TO beacon_monitor;
GRANT SELECT ON pg_stat_replication TO beacon_monitor;
GRANT SELECT ON pg_stat_progress_vacuum TO beacon_monitor;
GRANT pg_monitor TO beacon_monitor;  -- PostgreSQL 10+ convenience role (covers all the above)

-- Apply changes
\c your_app_database
GRANT USAGE ON SCHEMA public TO beacon_monitor;
```

**For SQL Server:**

```sql
-- Connect as sa or admin
USE master;

-- Create login
CREATE LOGIN beacon_monitor WITH PASSWORD = 'secure_password_here';

-- Create database user
USE your_app_database;
CREATE USER beacon_monitor FOR LOGIN beacon_monitor;

-- Grant monitoring permissions (covers all DMV access for BeaconX)
GRANT VIEW SERVER STATE TO beacon_monitor;
GRANT VIEW DATABASE STATE TO beacon_monitor;
```

##### **Step 2: Add Database Connection to config/database.php**

In your `config/database.php`, add a new connection for the monitoring user:

```php
'connections' => [
    'mysql' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'laravel'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ],

    // Add new elevated connection
    'mysql_monitor' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'laravel'),
        'username' => env('DB_MONITOR_USERNAME', 'beacon_monitor'),
        'password' => env('DB_MONITOR_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ],

    // ... other connections
],
```

##### **Step 3: Add Environment Variables**

Add the monitor user credentials to your `.env` file:

```env
# Standard database connection
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_app_database
DB_USERNAME=app_user
DB_PASSWORD=app_password

# Elevated monitor user credentials
DB_MONITOR_USERNAME=beacon_monitor
DB_MONITOR_PASSWORD=secure_password_here
```

##### **Step 4: Configure BeaconX**

Update your `config/beacon.php` (or publish it if you haven't already):

```bash
php artisan vendor:publish --tag=beacon-config
```

Then edit `config/beacon.php`:

```php
<?php

return [
    'hub_url' => env('WATCHTOWER_HUB_URL'),
    'token'   => env('WATCHTOWER_API_TOKEN'),

    // Add this line to use elevated credentials for database monitoring
    'system_db_connection' => env('DB_MONITOR_CONNECTION', 'mysql_monitor'),
];
```

##### **Step 5: Update Environment Configuration**

Add to your `.env` file:

```env
# Specify which connection to use for elevated monitoring
DB_MONITOR_CONNECTION=mysql_monitor
```

##### **Step 6: Test the Configuration**

Run the beacon command to verify everything works:

```bash
php artisan beacon:transmit
```

You should see output like:

```
📡 Signal received by WatchTowerX.
```

Check the database metrics in the response to confirm lock monitoring is working.

#### Verification Checklist

- [ ] Monitoring user created in database
- [ ] User has appropriate privileges granted
- [ ] New connection added to `config/database.php`
- [ ] Environment variables set in `.env`
- [ ] `config/beacon.php` updated with system_db_connection
- [ ] `beacon:transmit` command runs successfully
- [ ] Database lock count appears in metrics (not null)

#### Troubleshooting Elevated Credentials

**Problem: Lock count returns null or 0**

- Verify the monitoring user has SELECT privileges on performance_schema/information_schema
- Check database logs for permission denied errors
- Ensure connection name matches exactly in config

**Problem: "Permission denied" error**

```bash
# Check user permissions (MySQL)
SHOW GRANTS FOR 'beacon_monitor'@'localhost';

# Check user login ability
mysql -u beacon_monitor -p your_app_database -e "SELECT 1"
```

**Problem: Connection refused**

- Verify host/port in database connection config
- Ensure monitoring user can connect from application's IP address
- Check firewall rules if using remote database

## Docker Compatibility

BeaconX is fully compatible with Docker containers and works seamlessly in containerized Laravel applications.

### ✅ **Fully Supported Metrics**

- Application identity and runtime versions
- Database health and connection monitoring
- Cache performance statistics
- File permissions and log sizes
- SSL certificate expiry checks
- Session monitoring

### ⚠️ **Limited System Metrics in Minimal Containers**

Some system-level metrics require additional utilities that may not be present in minimal Docker images:

- **RAM Usage**: Requires `free` command
- **CPU Usage**: Requires `nproc` command
- **Network I/O**: Requires `/proc/net/dev` access
- **Disk I/O**: Requires `iostat` command
- **System Uptime**: Requires `/proc/uptime` access

When these utilities are unavailable, BeaconX gracefully returns safe default values (typically `0`) without breaking functionality.

### 🔧 **Recommended Docker Setup**

For full system monitoring capabilities, ensure your Docker image includes the necessary utilities:

```dockerfile
FROM php:8.1-fpm

# Install system monitoring utilities
RUN apt-get update && apt-get install -y \
    procps \
    sysstat \
    && rm -rf /var/lib/apt/lists/*
```

Or for Alpine-based images:

```dockerfile
RUN apk add --no-cache procps sysstat
```

### 📊 **Docker Metrics Status**

| Metric Category          | Docker Status | Notes                           |
| ------------------------ | ------------- | ------------------------------- |
| **Application Identity** | ✅ Full       | Uses Laravel configuration      |
| **Runtime Versions**     | ✅ Full       | PHP and Laravel versions        |
| **Database Health**      | ✅ Full       | Laravel DB connections          |
| **Cache Stats**          | ✅ Full       | Laravel cache facade            |
| **File Permissions**     | ✅ Full       | Standard PHP file functions     |
| **SSL Certificate**      | ✅ Full       | PHP stream functions            |
| **Log Sizes**            | ✅ Full       | File system access              |
| **Sessions**             | ✅ Full       | Laravel session handling        |
| **Disk Usage**           | ✅ Full       | PHP `disk_free_space()`         |
| **RAM Usage**            | ⚠️ Limited    | Requires `free` command         |
| **CPU Usage**            | ⚠️ Limited    | Requires `nproc` command        |
| **Network I/O**          | ⚠️ Limited    | Requires `/proc/net/dev` access |
| **Disk I/O**             | ⚠️ Limited    | Requires `iostat` command       |
| **System Uptime**        | ⚠️ Limited    | Requires `/proc/uptime` access  |

## Laravel Multi-Tenancy Compatibility

BeaconX is fully compatible with Laravel multi-tenancy applications and automatically adapts to tenant-specific contexts.

### ✅ **Fully Supported Features**

- **Database Monitoring**: Automatically monitors the current tenant's database connections and job queues
- **Cache Monitoring**: Respects tenant-specific cache stores and configurations
- **Session Monitoring**: Handles tenant-specific session drivers (database, Redis, file-based)
- **Configuration**: Uses Laravel's standard config system, respecting tenant-specific settings

### ⚠️ **Tenant-Specific Considerations**

Some metrics may vary based on your multi-tenancy implementation:

- **File Permissions**: May check tenant-specific storage paths if your multi-tenancy package isolates file systems
- **Log Files**: Monitors tenant-specific log directories when available
- **SSL Monitoring**: Uses tenant-specific URLs from `config('app.url')`
- **Application Identity**: Displays tenant-specific app names when configured

### 🔧 **Multi-Tenancy Setup Recommendations**

#### **1. Tenant-Specific Configuration**

Make beacon settings tenant-aware:

```php
// In your tenant configuration
'beacon' => [
    'hub_url' => env('TENANT_WATCHTOWER_HUB_URL'),
    'token'   => env('TENANT_WATCHTOWER_API_TOKEN'),
]
```

#### **2. Command Execution**

Ensure the beacon command runs in the correct tenant context:

```php
// Schedule per tenant or globally based on your needs
Schedule::command('beacon:transmit')
    ->everyMinute()
    ->onOneServer();
```

#### **3. Monitoring Strategy**

Choose your monitoring approach:

- **Per-Tenant**: Run commands in each tenant context for isolated monitoring
- **Global**: Monitor shared infrastructure metrics
- **Hybrid**: Combine tenant-specific data with system-wide metrics

### 📊 **Multi-Tenancy Compatibility Matrix**

| Feature              | Multi-Tenant Status | Notes                                |
| -------------------- | ------------------- | ------------------------------------ |
| **Database Health**  | ✅ Full Support     | Uses tenant's DB connection          |
| **Cache Stats**      | ✅ Full Support     | Respects tenant cache config         |
| **Job Queues**       | ✅ Full Support     | Monitors tenant-specific queues      |
| **File Permissions** | ⚠️ Depends          | May check tenant-specific paths      |
| **Log Files**        | ⚠️ Depends          | May monitor tenant-specific logs     |
| **Sessions**         | ⚠️ Depends          | Depends on session isolation         |
| **System Metrics**   | ✅ Full Support     | System-level (shared across tenants) |
| **SSL Monitoring**   | ⚠️ Depends          | Uses tenant-specific URLs            |
| **App Identity**     | ⚠️ Depends          | May show tenant-specific names       |

## Usage

### Manual Transmission

You can test the signal manually using the following Artisan command:

```bash
php artisan beacon:transmit
```

### Automatic Scheduling

To keep WatchTowerX updated, configure the beacon transmission in your scheduler. Choose your approach:

#### **Option 1: Using routes/console.php (Laravel 11+)**

```php
<?php

use Illuminate\Support\Facades\Schedule;

// Schedule beacon transmission every minute
Schedule::command('beacon:transmit')
    ->everyMinute()
    ->runInBackground();
```

#### **Option 2: Using Console Kernel (Laravel 10+)**

Edit `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Transmit metrics every minute
    $schedule->command('beacon:transmit')
        ->everyMinute()
        ->runInBackground();
}
```

#### **Option 3: For Distributed Systems**

If running on multiple servers, ensure beacon runs on only one server:

```php
Schedule::command('beacon:transmit')
    ->everyMinute()
    ->onOneServer()
    ->runInBackground();
```

#### **Option 4: Custom Frequency**

Adjust transmission frequency based on your monitoring needs:

```php
// Every 5 minutes
Schedule::command('beacon:transmit')->everyFiveMinutes();

// Every hour
Schedule::command('beacon:transmit')->hourly();

// Every 30 seconds (not recommended for production)
Schedule::command('beacon:transmit')->everyThirtySeconds();
```

### Ensure Scheduler is Running

The Laravel scheduler itself must be running. Add the following to your system's crontab:

```bash
* * * * * cd /path/to/application && php artisan schedule:run >> /dev/null 2>&1
```

Or on Windows using Task Scheduler, run every minute:

```cmd
php artisan schedule:run
```

### Testing the Schedule

Verify your scheduler is working:

```bash
# Dry run (see what would execute)
php artisan schedule:list

# Run pending tasks
php artisan schedule:work
```

## Advanced Features

### Request Logging for Performance Metrics

To enable average response time tracking, create a middleware and database table:

```php
// Create migration for request logs
php artisan make:migration create_request_logs_table

// In the migration:
Schema::create('request_logs', function (Blueprint $table) {
    $table->id();
    $table->string('method');
    $table->string('url');
    $table->float('response_time');
    $table->timestamps();
});

// Create middleware
php artisan make:middleware LogRequestTime

// In LogRequestTime middleware:
public function handle($request, Closure $next)
{
    $start = microtime(true);
    $response = $next($request);
    $end = microtime(true);

    DB::table('request_logs')->insert([
        'method' => $request->method(),
        'url' => $request->fullUrl(),
        'response_time' => ($end - $start) * 1000, // milliseconds
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $response;
}
```

### Cache Monitoring

For enhanced cache statistics, use Redis as your cache driver. BeaconX will automatically detect and report detailed cache metrics including hit rates and cache size.

## Metrics Collected

BeaconX automatically gathers and transmits comprehensive health metrics across multiple categories:

### System Metrics

- **Disk Usage:** Percentage of disk space used
- **RAM Usage:** Percentage of memory utilization
- **CPU Usage:** System load average as percentage
- **Network I/O:** Bytes received and transmitted
- **Disk I/O:** Read/write operations per second
- **System Uptime:** Seconds since system boot

### Application Workload

- **Failed Jobs:** Total number of failed background jobs
- **Pending Jobs:** Number of queued jobs waiting to be processed
- **Processed Today:** Jobs completed in the current day

### Database Health

BeaconX collects 36 database metrics across MySQL/MariaDB, PostgreSQL, and SQL Server. Metrics marked with a driver badge are only populated for that driver; all others work cross-driver or degrade gracefully to `null`.

#### Connectivity & Latency
- **Connection Status:** `healthy` / `unhealthy` with error message
- **Query Latency (ms):** Round-trip time for a `SELECT 1` probe
- **DB Size (MB):** Total database size in megabytes

#### Connections
- **Active Connections:** Current open connections
- **Max Connections:** Server-configured limit (MySQL, PostgreSQL)
- **Connections Used %:** Active as a percentage of max
- **Aborted Connections:** Cumulative failed connection attempts — signals pool misconfiguration `[MySQL]`
- **Thread Cache Hit Ratio (%):** Connections served from thread cache vs. newly created `[MySQL]`

#### Query Activity
- **Long-Running Queries:** Count of queries running longer than 10 seconds
- **Waiting / Blocked Queries:** Queries blocked waiting on a lock or resource
- **Slow Queries:** Cumulative count from the server's slow query log `[MySQL]` / `pg_stat_statements` `[PostgreSQL]`
- **Full Table Scans:** Cumulative full scans + full joins — proxy for missing indexes `[MySQL]`
- **Temp Tables to Disk (%):** Percentage of temp tables that spilled to disk — signals `tmp_table_size` pressure `[MySQL]`

#### Transactions & Locks
- **Active Locks:** Number of current database locks
- **Deadlocks:** Cumulative deadlock count
- **Row Lock Waits:** Cumulative times a query waited for a row lock `[MySQL]`
- **Row Lock Avg Wait (ms):** Average row lock wait time `[MySQL]`
- **Undo History Length:** InnoDB purge backlog — large values mean a long-running transaction is blocking cleanup `[MySQL]`
- **Oldest Open Transaction (s):** Age of the oldest uncommitted transaction — catches stuck transactions
- **Idle-in-Transaction Connections:** Connections with an open transaction but no active query — common application bug that holds locks silently `[PostgreSQL]`

#### Storage & Fragmentation
- **Table Fragmentation (MB):** Wasted space from DELETEs/UPDATEs across all tables `[MySQL]`
- **Tables Without Primary Key:** Count of tables missing a primary key — replication and ORM risk
- **Largest Tables:** Top 5 tables by size (name + MB)
- **Binary Log Disk Usage (MB):** Total disk space consumed by binary logs — unrotated logs silently fill disk `[MySQL]`

#### Buffer / Cache Efficiency
- **Buffer Pool Hit Ratio (%):** Percentage of InnoDB reads served from memory — should stay above 99% `[MySQL]`; equivalent buffer cache ratio for PostgreSQL heap blocks
- **Page Life Expectancy:** Seconds a page stays in the SQL Server buffer pool — below 300 signals memory pressure `[SQL Server]`
- **MSSQL Buffer Cache Hit Ratio (%):** Percentage of page requests served from memory `[SQL Server]`

#### Index Health
- **Unused Indexes:** Count of indexes with zero reads since last restart — maintenance signal
- **MSSQL Fragmented Indexes:** Top 5 indexes with >30% fragmentation and >100 pages (table, index name, fragmentation %) `[SQL Server]`

#### Replication
- **Replication Lag (s):** Seconds behind primary for replica instances
- **Replication Slot WAL Retention (MB):** WAL retained by replication slots — an inactive slot causes unbounded disk growth `[PostgreSQL]`

#### PostgreSQL-Specific
- **Dead Tuples:** Total dead tuples across all user tables — signals VACUUM falling behind
- **Autovacuum Max Staleness (s):** Seconds since the least-recently vacuumed table was processed
- **BGWriter Backend Writes:** Buffers written by backends directly — non-zero means checkpoint cannot keep up with I/O demand
- **Forced Checkpoint Ratio (%):** Percentage of checkpoints that were forced vs. scheduled — high values indicate WAL filling faster than the checkpoint interval

#### SQL Server-Specific
- **MSSQL Batch Requests/sec:** Cumulative batch request count — load baseline for alerting
- **MSSQL SQL Compilations/sec:** SQL compilation rate — high values indicate plan cache pressure or unparameterized queries
- **MSSQL Tempdb Used (MB):** Tempdb space in use — exhaustion crashes the entire SQL Server instance

### Cache Performance

- **Cache Driver:** Type of cache driver in use
- **Cache Size:** Number of cached items
- **Hit/Miss Rates:** Cache performance statistics

### Application Performance

- **Average Response Time:** Mean response time for HTTP requests (requires request logging)

### Security Monitoring

- **SSL Certificate Expiry:** Days until SSL certificate expires
- **File Permissions:** Read/write access for critical files (.env, storage, cache directories)

### Operational Metrics

- **Log File Sizes:** Size of application log files
- **Active Sessions:** Number of users with recent activity

### Runtime Environment

- **Identity:** The APP_NAME defined in your client application
- **PHP Version:** Current PHP version
- **Laravel Version:** Current Laravel framework version

## Security

Data is transmitted over HTTPS and authenticated via a secure X-Beacon-Token header. No sensitive environment variables (like .env contents) are ever transmitted. All metrics are designed to be safe for external monitoring without exposing confidential information.

## Error Handling

BeaconX includes comprehensive error handling for all metrics. If a metric cannot be collected (due to permissions, missing dependencies, or system limitations), it gracefully degrades by returning safe default values and logging warnings for debugging.

## Contributing

BeaconX provides comprehensive monitoring across 36 database metrics and covers system, cache, security, session, and runtime telemetry. If you wish to extend the metrics gathered or add new monitoring features, please submit a PR to the internal watchtowerx/beaconx repository. The package is designed to be extensible while maintaining performance and security standards.

## FAQ

### Installation & Setup

**Q: Where do I get the WATCHTOWER_API_TOKEN?**

A: Generate your unique site token in your WatchTowerX dashboard under Settings → API Tokens → Create New Beacon Token.

**Q: Do I need to create the elevated database user?**

A: No, it's optional. The package works without it and will return 0 for lock counts. Create an elevated user only if you need detailed lock monitoring.

**Q: Can I use BeaconX with HTTP instead of HTTPS?**

A: While possible, HTTPS is strongly recommended for security. BeaconX automatically bypasses SSL verification for self-signed certificates in development environments.

### Configuration

**Q: How often should I transmit metrics?**

A: Every minute is recommended for real-time monitoring. Adjust `->everyMinute()` in your console scheduler based on your needs. Every 5 minutes is acceptable for most applications.

**Q: Can I use a custom database connection name?**

A: Yes! Set `DB_MONITOR_CONNECTION` to your custom connection name in `.env` and configure it in `config/database.php`.

**Q: What if my database user doesn't have elevated privileges?**

A: BeaconX gracefully falls back to basic database health checks. Lock counts will return 0 or null, but the connection status and latency will still be monitored.

### Metrics & Monitoring

**Q: Why is my CPU usage always 0 on Windows?**

A: The `wmic` command is required. Ensure it's available on your Windows system - it's included by default on most Windows installations.

**Q: Why are system metrics returning 0 in Docker?**

A: Some utilities like `free`, `npm`, and `iostat` may not be installed. Install them in your Docker image - see the Docker Compatibility section for details.

**Q: How do I enable request logging for response time metrics?**

A: Create a middleware to log request times to a `request_logs` table. See the Advanced Features section for complete implementation.

**Q: What's the difference between active sessions and pending jobs?**

A: **Active sessions** = currently logged-in users. **Pending jobs** = background tasks queued in the jobs table waiting to be processed.

### Database Monitoring

**Q: Why does my lock count show null?**

A: This usually means:

1. The monitoring user doesn't have SELECT permission on performance_schema
2. The database connection isn't set up correctly
3. The database driver doesn't support lock monitoring (e.g., SQLite)

Check your database logs and verify user permissions.

**Q: Do I need to restart Laravel after adding elevated credentials?**

A: Yes, restart your Laravel application (or queue workers) to pick up new environment variables.

**Q: Will monitoring queries affect my database performance?**

A: No, these are read-only queries that execute in milliseconds. The performance_schema is designed for this purpose.

**Q: Why do most new database metrics return `null`?**

A: Many metrics are driver-specific — for example, `undo_history_length` only applies to MySQL, `dead_tuples` only to PostgreSQL, and `mssql_tempdb_used_mb` only to SQL Server. A `null` value means the metric is not applicable to your database driver, not that something is broken.

**Q: Why does `idle_in_transaction_connections` matter?**

A: A connection in the `idle in transaction` state has opened a transaction but sent no query. It holds any locks it acquired until it commits or times out. This is a common application bug (e.g., a connection returned to the pool mid-transaction) that causes seemingly random lock timeouts elsewhere. Alert on this metric if it stays non-zero for extended periods.

**Q: My `replication_slot_wal_retention_mb` is growing — what should I do?**

A: An inactive or stalled replication slot causes PostgreSQL to retain all WAL segments since the slot's restart LSN. This can fill your disk entirely. Identify unused slots with `SELECT * FROM pg_replication_slots WHERE active = false` and drop them with `SELECT pg_drop_replication_slot('slot_name')` if no longer needed.

**Q: What does a high `forced_checkpoint_ratio` mean?**

A: PostgreSQL triggers checkpoints either on schedule (`checkpoints_timed`) or because the WAL has filled (`checkpoints_req`). A high forced ratio means your WAL is filling faster than your checkpoint interval — increase `max_wal_size` or reduce `checkpoint_completion_target` to spread I/O.

**Q: Why is `slow_queries` a cumulative counter, not a current rate?**

A: Both MySQL's `Slow_queries` and PostgreSQL's `pg_stat_statements` expose cumulative counters since the last server restart (or stats reset). Your WatchTowerX hub should compute the delta between transmissions to derive a rate. The raw counter is what BeaconX reports.

### Troubleshooting

**Q: I see "Hub URL or Token not found in .env"**

A: Make sure both `WATCHTOWER_HUB_URL` and `WATCHTOWER_API_TOKEN` are set in your `.env` file, and run `php artisan config:cache` if using configuration caching.

**Q: Connection to the WatchTowerX hub is failing**

A: Check:

1. Hub URL is correct and reachable
2. API token is valid
3. Your firewall allows outgoing HTTPS connections
4. The hub server is running and responding

**Q: Some metrics show 0 but I expect them to be non-zero**

A: This typically happens when:

1. Required system utilities aren't installed (see Docker Compatibility)
2. The application doesn't have sufficient permissions
3. The feature hasn't been set up yet (e.g., request logging, sessions)

Run the beacon command with verbose logging to debug:

```bash
php artisan beacon:transmit --verbose
```

**Q: Certificate SSL errors when transmitting**

A: BeaconX automatically bypasses SSL verification. If you're still getting errors, check if:

1. HTTPS connectivity works from your server
2. Certificate chain is complete
3. System time is correct (SSL validation depends on this)

### Performance & Optimization

**Q: Will running beacon:transmit every minute impact my app?**

A: No, the metrics collection and transmission take typically 100-500ms and run asynchronously. The command is non-blocking.

**Q: Should I run beacon:transmit on one server or all servers in a cluster?**

A: Run it on **all servers** if monitoring per-server metrics, or on **one server only** if monitoring application metrics (recommend using `->onOneServer()` scheduler constraint).

**Q: Can I customize which metrics are collected?**

A: Currently, all metrics are collected. Future versions will support selective metric collection. For now, all data collected is transmitted.

## Support

For issues or questions about BeaconX:

1. Check the FAQ above
2. Review the inline code documentation
3. Check the WatchTowerX hub logs
4. Open an issue on the internal repository

## License

BeaconX is open-source software licensed under the MIT license.
