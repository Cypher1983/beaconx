<?php

namespace WatchTowerX\BeaconX;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Beacon
{
    public function emit(): array
    {
        return [
            'identity' => config('app.name'),
            'system' => [
                'disk' => $this->getDiskPercentage(),
                'ram' => $this->getRamPercentage(),
                'cpu' => $this->getCpuPercentage(),
                'network' => $this->getNetworkStats(),
                'disk_io' => $this->getDiskIO(),
                'uptime' => $this->getUptime(),
            ],
            'workload' => [
                'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
                'pending_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
                'processed_today' => Schema::hasTable('jobs') ? DB::table('jobs')->whereDate('created_at', today())->count() : 0,
            ],
            'database' => $this->getDatabaseHealth(),
            'cache' => $this->getCacheStats(),
            'performance' => [
                'avg_response_time' => $this->getAvgResponseTime(),
            ],
            'security' => [
                'ssl_expiry' => $this->getSSLCertificateExpiry(),
                'file_permissions' => $this->checkFilePermissions(),
            ],
            'logs' => $this->getLogSizes(),
            'sessions' => [
                'active' => $this->getActiveSessions(),
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ]
        ];
    }

    private function getDiskPercentage()
    {
        return round((1 - (disk_free_space("/") / disk_total_space("/"))) * 100, 2);
    }

    private function getRamPercentage()
    {
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $mem = @shell_exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value');
                if (!$mem) return 0;
                $totalMatch = [];
                $freeMatch = [];
                preg_match('/TotalVisibleMemorySize=(\d+)/', $mem, $totalMatch);
                preg_match('/FreePhysicalMemory=(\d+)/', $mem, $freeMatch);
                if (!$totalMatch || !$freeMatch) return 0;
                $total = (int) $totalMatch[1];
                $free = (int) $freeMatch[1];
                $used = $total - $free;
                return round(($used / $total) * 100, 2);
            } else {
                $mem = @shell_exec('free -m');
                if (!$mem) return 0;
                $lines = explode("\n", trim($mem));
                if (count($lines) < 2) return 0;
                $stats = preg_split('/\s+/', $lines[1]);
                return count($stats) > 2 ? round(($stats[2] / $stats[1]) * 100, 2) : 0;
            }
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getCpuPercentage(): float
    {
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $cpu = @shell_exec('wmic cpu get loadpercentage /value');
                $matches = [];
                if ($cpu && preg_match('/LoadPercentage=(\d+)/', $cpu, $matches)) {
                    return (float) $matches[1];
                }
                return 0;
            } else {
                $load = @sys_getloadavg();
                if (!$load) return 0;
                // Convert load average to percentage (rough estimate)
                $cpuCount = (int) @shell_exec('nproc') ?: 1;
                return round(min($load[0] / $cpuCount * 100, 100), 2);
            }
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getNetworkStats(): array
    {
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                return ['rx' => 0, 'tx' => 0];
            }
            $netstat = @shell_exec('cat /proc/net/dev 2>/dev/null | grep -E "(eth0|enp|wlan)" | head -1');
            if (!$netstat) return ['rx' => 0, 'tx' => 0];

            $parts = preg_split('/\s+/', trim($netstat));
            if (count($parts) < 10) return ['rx' => 0, 'tx' => 0];

            return [
                'rx' => (int) $parts[1], // bytes received
                'tx' => (int) $parts[9], // bytes transmitted
            ];
        } catch (\Exception $e) {
            return ['rx' => 0, 'tx' => 0];
        }
    }

    private function getDiskIO(): array
    {
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                return ['reads' => 0, 'writes' => 0];
            }
            $iostat = @shell_exec('iostat -d 1 1 2>/dev/null | grep -E "(sda|vda|nvme)" | head -1');
            if (!$iostat) return ['reads' => 0, 'writes' => 0];

            $parts = preg_split('/\s+/', trim($iostat));
            if (count($parts) < 4) return ['reads' => 0, 'writes' => 0];

            return [
                'reads' => round((float) $parts[1], 2),  // reads/sec
                'writes' => round((float) $parts[2], 2), // writes/sec
            ];
        } catch (\Exception $e) {
            return ['reads' => 0, 'writes' => 0];
        }
    }

    private function getUptime(): int
    {
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                return 0;
            }
            $uptime = @shell_exec('cat /proc/uptime 2>/dev/null');
            if (!$uptime) return 0;

            $parts = explode(' ', trim($uptime));
            return isset($parts[0]) ? (int) $parts[0] : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            $maxTimeout = 3; // 3 second hard limit

            // Execute query with timeout protection
            DB::select('SELECT 1');
            $latency = microtime(true) - $start;

            // Return timeout error if query took too long
            if ($latency > $maxTimeout) {
                return [
                    'status' => 'unhealthy',
                    'error' => "Database query timeout (>{$maxTimeout}s)",
                    'latency_ms' => (float) number_format($latency * 1000, 2, '.', ''),
                ];
            }

            $locks = $this->getDatabaseLockCount();

            return [
                'status' => 'healthy',
                'latency_ms' => (float) number_format($latency * 1000, 2, '.', ''),
                'locks' => $locks,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'latency_ms' => 0,
            ];
        }
    }

    private function getDatabaseLockCount(): ?int
    {
        try {
            // Use system connection if configured (for elevated privileges)
            $connection = config('beacon.system_db_connection');
            $db = $connection ? DB::connection($connection) : DB::connection();

            // Get the driver name properly
            if ($connection) {
                $driver = config("database.connections.{$connection}.driver");
            } else {
                $defaultConnection = config('database.default');
                $driver = config("database.connections.{$defaultConnection}.driver");
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                // Try MySQL 8.0+ performance_schema first
                try {
                    $row = $db->selectOne('SELECT COUNT(*) AS cnt FROM performance_schema.data_locks');
                    return isset($row->cnt) ? (int) $row->cnt : 0;
                } catch (\Exception $e) {
                    // Fall back to older information_schema for MySQL 5.7 and below
                    try {
                        $row = $db->selectOne('SELECT COUNT(*) AS cnt FROM information_schema.innodb_locks');
                        return isset($row->cnt) ? (int) $row->cnt : 0;
                    } catch (\Exception $e2) {
                        // If both fail, return 0 instead of null
                        return 0;
                    }
                }
            } elseif ($driver === 'pgsql') {
                $row = $db->selectOne('SELECT COUNT(*) AS cnt FROM pg_locks');
            } elseif ($driver === 'sqlsrv') {
                // SQL Server - handle permission issues gracefully
                try {
                    $row = $db->selectOne('SELECT COUNT(*) AS cnt FROM sys.dm_tran_locks');
                    return isset($row->cnt) ? (int) $row->cnt : 0;
                } catch (\Exception $e) {
                    $errorMsg = $e->getMessage();
                    // Permission denied or authentication failures - return null silently
                    if (
                        str_contains($errorMsg, 'permission was denied') ||
                        str_contains($errorMsg, 'Login failed')
                    ) {
                        return null;
                    }
                    throw $e;
                }
            } else {
                return null;
            }

            return isset($row->cnt) ? (int) $row->cnt : 0;
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            // Only log unexpected errors (not permissions or auth issues)
            if (
                !str_contains($errorMsg, 'permission was denied') &&
                !str_contains($errorMsg, 'Login failed')
            ) {
                Log::warning('BeaconX: Failed to get database lock count', ['error' => $errorMsg]);
            }
            return null;
        }
    }

    private function getCacheStats(): array
    {
        try {
            $cache = app('cache');
            $store = $cache->getStore();

            // Basic cache info
            $stats = [
                'driver' => config('cache.default'),
                'size' => 0,
                'hits' => 0,
                'misses' => 0,
            ];

            // Try to get Redis stats if using Redis
            if ($store instanceof \Illuminate\Cache\RedisStore) {
                try {
                    $redis = $store->connection();
                    $info = $redis->info();
                    $stats['size'] = $info['db0']['keys'] ?? 0;
                    $stats['hits'] = $info['keyspace_hits'] ?? 0;
                    $stats['misses'] = $info['keyspace_misses'] ?? 0;
                } catch (\Exception $e) {
                    // Redis stats not available
                }
            }

            return $stats;
        } catch (\Exception $e) {
            Log::warning('BeaconX: Failed to get cache stats', ['error' => $e->getMessage()]);
            return ['driver' => 'unknown', 'size' => 0, 'hits' => 0, 'misses' => 0];
        }
    }

    private function getAvgResponseTime(): float
    {
        try {
            // This assumes you have request logging middleware
            // If not implemented, returns 0
            if (!Schema::hasTable('request_logs')) {
                return 0;
            }

            return round(DB::table('request_logs')
                ->where('created_at', '>=', now()->subHour())
                ->avg('response_time') ?? 0, 2);
        } catch (\Exception $e) {
            Log::warning('BeaconX: Failed to get average response time', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function getSSLCertificateExpiry(): ?int
    {
        try {
            $url = parse_url(config('app.url'), PHP_URL_HOST);
            if (!$url) return null;

            $context = stream_context_create([
                "ssl" => ["capture_peer_cert" => true]
            ]);

            $fp = @stream_socket_client("ssl://{$url}:443", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
            if (!$fp) return null;

            $params = stream_context_get_params($fp);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if (!$cert) return null;

            $certInfo = openssl_x509_parse($cert);
            if (!$certInfo || !isset($certInfo['validTo_time_t'])) return null;

            return $certInfo['validTo_time_t'] - time(); // seconds until expiry
        } catch (\Exception $e) {
            Log::warning('BeaconX: Failed to check SSL certificate', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function checkFilePermissions(): array
    {
        try {
            $criticalFiles = [
                '.env' => base_path('.env'),
                'storage' => storage_path(),
                'bootstrap/cache' => base_path('bootstrap/cache'),
            ];

            $results = [];
            foreach ($criticalFiles as $name => $path) {
                if (file_exists($path)) {
                    $results[$name] = [
                        'exists' => true,
                        'writable' => is_writable($path),
                        'readable' => is_readable($path),
                    ];
                } else {
                    $results[$name] = ['exists' => false];
                }
            }
            return $results;
        } catch (\Exception $e) {
            Log::warning('BeaconX: Failed to check file permissions', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function getLogSizes(): array
    {
        try {
            $logPath = storage_path('logs');
            if (!is_dir($logPath)) return [];

            $logs = glob($logPath . '/*.log');
            $sizes = [];

            foreach ($logs as $log) {
                $sizes[basename($log)] = filesize($log);
            }

            return $sizes;
        } catch (\Exception $e) {
            Log::warning('BeaconX: Failed to get log sizes', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function getActiveSessions(): int
    {
        try {
            $driver = config('session.driver');

            if ($driver === 'database' && Schema::hasTable('sessions')) {
                return DB::table('sessions')
                    ->where('last_activity', '>=', now()->subMinutes(30)->timestamp)
                    ->count();
            } elseif ($driver === 'redis') {
                // For Redis sessions, we'd need to scan keys
                // This is complex, so return 0 for now
                return 0;
            } elseif ($driver === 'file') {
                // Count session files modified in last 30 minutes
                $sessionPath = config('session.files') ?: session_save_path() ?: sys_get_temp_dir() . '/sessions';
                if (!is_dir($sessionPath)) return 0;

                $files = glob($sessionPath . '/sess_*');
                $active = 0;
                foreach ($files as $file) {
                    if (filemtime($file) >= time() - 1800) { // 30 minutes
                        $active++;
                    }
                }
                return $active;
            }

            return 0;
        } catch (\Exception $e) {
            Log::warning('BeaconX: Failed to get active sessions', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}