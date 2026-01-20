# BeaconX

**The Official Monitoring Signal for WatchTowerX.**

BeaconX is a comprehensive Laravel package designed to be installed on client applications. It gathers extensive system, application, and infrastructure health metrics and transmits them back to your central **WatchTowerX** hub for monitoring and alerting.

---

## Installation

### 1. Requirements

- PHP 8.1+
- Laravel 10.x or 11.x

### 2. Install via Composer

Add the package to your Laravel application:

```bash
composer require watchtowerx/beaconx
```

### 3. Publish Configuration

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

---

### Final Project Recap

You now have a complete, professional monitoring ecosystem:

1. **WatchTowerX (The Hub):** A VILT stack app that receives data, stores it in `health_logs`, and sends alerts via Telegram/Email if a "heartbeat" is missed.
2. **BeaconX (The Package):** A standalone Composer package that you can install on any Laravel site to turn it into a reporting "node."

### One last pro-tip:

If you plan on hosting this package privately on GitHub, make sure you add the repository to your client app's `composer.json` like this before running `composer require`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/your-username/beaconx.git"
    }
]
```
