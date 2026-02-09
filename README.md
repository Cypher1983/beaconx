# BeaconX

**The Official Monitoring Signal for WatchTowerX.**

BeaconX is a comprehensive Laravel package designed to be installed on client applications. It gathers extensive system, application, and infrastructure health metrics and transmits them back to your central **WatchTowerX** hub for monitoring and alerting.

---

## Installation

### 1. Requirements

- PHP 8.1+
- Laravel 10.x or 11.x

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

Add the following keys to your .env file. You can generate the API_TOKEN within your WatchTowerX dashboard.

```env
WATCHTOWER_HUB_URL=https://your-watchtower-hub.com/api/v1/report
WATCHTOWER_API_TOKEN=your_unique_site_token
```

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

To keep WatchTowerX updated, add the transmission command to your routes/console.php (or app/Console/Kernel.php):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('beacon:transmit')->everyMinute();
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

- **Connection Status:** Database connectivity and health
- **Query Latency:** Response time for database queries
- **Active Connections:** Current database connection count

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

BeaconX now provides comprehensive monitoring capabilities. If you wish to extend the metrics gathered or add new monitoring features, please submit a PR to the internal watchtowerx/beaconx repository. The package is designed to be extensible while maintaining performance and security standards.
