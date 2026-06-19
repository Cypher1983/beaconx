# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BeaconX is a Laravel package (not an application) that collects comprehensive system, application, and infrastructure health metrics and transmits them to a central WatchTowerX hub for monitoring and alerting.

**Key characteristics:**
- Composer package, not a standalone application
- PSR-4 autoloaded from `src/` namespace `WatchTowerX\BeaconX\`
- Installed as a dependency in client Laravel applications
- ~528 lines of PHP code across 4 files in `src/`
- No automated test suite in repository
- MIT licensed

## Architecture at a Glance

### Service Provider Pattern
The package integrates with Laravel via `BeaconServiceProvider`:
- Publishes `config/beacon.php` to client apps via `vendor:publish --tag=beacon-config`
- Registers the `beacon:transmit` Artisan command for metric transmission
- Merges default beacon config from `config/beacon.php` with client application config

### Single Metrics Collector
`src/Beacon.php` is the core class with one public method:
- **`emit(): array`** - Collects all metrics and returns organized associative array
- Metrics organized into 8 categories: identity, system, workload, database, cache, performance, security, logs, sessions, runtime
- 15+ private methods, each handling a specific metric or metric group
- All methods wrapped in try-catch blocks that return safe defaults (0, null, empty arrays) on failure

### Command-Driven Transmission
`src/Commands/TransmitMetrics.php` is the Artisan command:
- Command: `php artisan beacon:transmit`
- Retrieves metrics via `Beacon::emit()`
- POSTs to hub URL with X-Beacon-Token header authentication
- Runs synchronously; no queueing (intentional design)
- Intended for Laravel scheduler: `Schedule::command('beacon:transmit')->everyMinute()`

### Configuration
`config/beacon.php` provides two settings:
- `hub_url` - WatchTowerX hub endpoint (from `WATCHTOWER_HUB_URL` env var)
- `token` - API token (from `WATCHTOWER_API_TOKEN` env var)
- `system_db_connection` - Optional alternate DB connection for elevated monitoring user (from `DB_MONITOR_CONNECTION` env var)

## Metric Collection Strategy

Metrics span 8 categories. Key implementation details:

### System Metrics
Cross-platform collection with graceful fallbacks:
- **Disk/RAM/CPU**: Windows uses `wmic` commands; Linux uses `free`, `sys_getloadavg()`
- **Network/Disk I/O**: Linux only (uses `/proc/net/dev` and `iostat`); returns 0 on Windows
- **Uptime**: Linux only (uses `/proc/uptime`); returns 0 on Windows

### Application Workload
- Queries `failed_jobs`, `jobs` tables with `Schema::hasTable()` checks
- Counts pending and processed jobs

### Database Health
- Connection test + latency timing (3-second hard timeout)
- **Lock count**: Key feature requiring database driver knowledge
  - MySQL 8.0+: `performance_schema.data_locks`
  - MySQL 5.7: `information_schema.innodb_locks` (automatic fallback)
  - PostgreSQL: `pg_locks`
  - SQL Server: `sys.dm_tran_locks` (with permission error handling)
  - Optional elevated credentials: Queries on separate `system_db_connection`

### Cache Stats
- Driver detection from `config('cache.default')`
- Redis-aware: Uses `RedisStore` instanceof check to get hits/misses
- Degrades gracefully for file/array drivers

### Optional Features
- **Request logging**: Queries `request_logs` table (last hour average) - requires client to implement logging middleware
- **SSL expiry**: Uses `stream_socket_client()` to extract peer certificate
- **Session counting**: Adapts to driver: database (last 30 min), file (recent files), Redis (returns 0)

## Key Implementation Details

### Error Handling Pattern
All metric collection follows this pattern:
```php
private function getMetric()
{
    try {
        // Metric collection logic
        return $value;
    } catch (\Exception $e) {
        Log::warning('BeaconX: Failed to get metric', ['error' => $e->getMessage()]);
        return 0; // or null, or []
    }
}
```

### Timeout Protection
Database queries have explicit 3-second hard timeout:
```php
$start = microtime(true);
$maxTimeout = 3;
DB::select('SELECT 1');
$latency = microtime(true) - $start;
if ($latency > $maxTimeout) {
    return ['status' => 'unhealthy', 'error' => "Database query timeout (>{$maxTimeout}s)"];
}
```

### No Model Classes
Deliberately avoids Eloquent models:
- Direct DB facade calls: `DB::table()`, `DB::select()`, `DB::connection()`
- Minimal dependencies, faster execution
- No query scoping or relationship loading

### Graceful Permission Handling
SQL Server lock queries catch permission errors:
```php
if (str_contains($errorMsg, 'permission was denied') || str_contains($errorMsg, 'Login failed')) {
    return null; // Silent graceful failure
}
```

## Commands for Development

### Testing the Package
```bash
# Manual test of metric collection (outputs all metrics)
php test_beacon.php

# Test transmission to hub (requires .env config)
php artisan beacon:transmit

# Test transmission with verbose output
php artisan beacon:transmit --verbose
```

### Publishing Configuration (for client apps using this package)
```bash
php artisan vendor:publish --tag=beacon-config
```

### No Build, Lint, or Test Commands
This package has no:
- Build step (pure PHP, no compilation)
- Linter configuration
- PHPUnit/Pest test suite
- Continuous integration scripts

Testing responsibility falls on client applications using BeaconX.

## File Modifications Guide

### Adding a New Metric

1. **Create a private method in `src/Beacon.php`**:
   ```php
   private function getNewMetric(): array
   {
       try {
           // Collection logic
           return $value;
       } catch (\Exception $e) {
           Log::warning('BeaconX: Failed to get new metric', ['error' => $e->getMessage()]);
           return []; // Safe default
       }
   }
   ```

2. **Register in `emit()` method**:
   ```php
   public function emit(): array
   {
       return [
           // ... existing metrics
           'new_category' => $this->getNewMetric(),
       ];
   }
   ```

3. **Update README.md** in "Metrics Collected" section

4. **Test with `test_beacon.php`** and verify output

### Adding Configuration Options

1. **Add to `config/beacon.php`**:
   ```php
   'new_option' => env('NEW_OPTION_ENV_VAR'),
   ```

2. **Document in README.md** Configuration section

3. **Update transmission logic** in `src/Commands/TransmitMetrics.php` if it affects transmission

## Database Connection Strategy

### Default Connection
All queries use application's default connection:
```php
DB::select('SELECT 1'); // Uses config('database.default')
```

### Elevated Monitoring Connection
Optional separate connection for lock monitoring:
```php
$connection = config('beacon.system_db_connection');
$db = $connection ? DB::connection($connection) : DB::connection();
```

Client must:
1. Create monitoring user with SELECT permissions on `performance_schema` or equivalent
2. Add connection to `config/database.php`
3. Set `DB_MONITOR_CONNECTION` env var
4. Set `DB_MONITOR_USERNAME` and `DB_MONITOR_PASSWORD` env vars

## Multi-Tenancy & Docker Considerations

### Multi-Tenancy
- All database queries respect Laravel''s connection resolution, so tenant-specific connections work automatically
- Cache and session metrics adapt to tenant config
- No special tenant handling needed in code

### Docker
- Works in minimal images; gracefully returns 0 for unavailable system utilities
- Recommends `procps` and `sysstat` packages for full system metrics
- All system calls wrapped in try-catch, so missing utilities don''t break transmission

## Cross-Platform Windows/Linux Handling

Platform detection:
```php
if (strtoupper(substr(PHP_OS, 0, 3)) === ''WIN'') {
    // Windows-specific logic (wmic commands)
} else {
    // Linux/Mac logic (shell commands)
}
```

**Windows limitations:**
- Network I/O: Returns 0 (no /proc/net/dev)
- Disk I/O: Returns 0 (iostat not available)
- System uptime: Returns 0 (no /proc/uptime)
- These intentional graceful failures, not bugs

## HTTP Transmission Details

From `src/Commands/TransmitMetrics.php`:
- POST request to `config(''beacon.hub_url'')`
- Header: `X-Beacon-Token: {config(''beacon.token'')}`
- Body: JSON from `$beacon->emit()`
- SSL verification disabled via `->withoutVerifying()` (self-signed certs in dev)
- 10-second timeout
- Logs error if status code not 2xx

## README Documentation Highlights

The README.md includes critical sections for users:
- Quick Start (3-minute setup)
- Elevated Database Credentials step-by-step guide (for lock monitoring)
- Docker Compatibility matrix (which metrics work in containers)
- Multi-Tenancy Compatibility matrix
- Comprehensive FAQ with troubleshooting
- Full Metrics Collected reference with descriptions

When modifying core functionality, ensure README FAQ and troubleshooting remain accurate.

## Security Considerations

### Data Transmission
- HTTPS only (with SSL verification bypass allowed for self-signed dev certs)
- Token-based auth via X-Beacon-Token header
- No sensitive environment variables transmitted

### File Permissions Check
Only checks existence and read/write flags for:
- `.env`
- `storage/`
- `bootstrap/cache/`

Does NOT transmit file contents.

### Permission Isolation
Supports separate monitoring user with minimal elevated privileges. Gracefully handles permission errors without breaking transmission.

## Performance Profile

- **Typical execution**: 100-500ms per `beacon:transmit` command
- **Database timeout**: 3-second hard limit (returns unhealthy if exceeded)
- **HTTP timeout**: 10 seconds
- **Runs non-blocking**: Intended for Laravel scheduler, not critical path
- **No N+1 queries**: Uses direct table counts, no relationship loading

## Package Dependency Requirements

From `composer.json`:
```json
"require": {
    "php": "^8.1",
    "illuminate/support": "^10.0|^11.0|^12.0",
    "illuminate/console": "^10.0|^11.0|^12.0",
    "illuminate/http": "^10.0|^11.0|^12.0"
}
```

- Supports Laravel 10, 11, and 12
- Only 3 Illuminate packages; no external monitoring libraries
- Uses native PHP for system metrics

## Graceful Degradation Philosophy

Core principle throughout codebase:
- Missing utilities (e.g., `free` command) → returns 0
- Unsupported databases (e.g., SQLite for locks) → returns null
- Missing tables (e.g., `request_logs`) → returns 0
- Permission errors (especially SQL Server) → returns null silently
- Exceptions → logged as warnings, transmission continues with safe defaults

Never throws exceptions upward; always returns valid metrics structure.
