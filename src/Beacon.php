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
        try {
            $root = PHP_OS_FAMILY === 'Windows' ? 'C:\\' : '/';
            $free = disk_free_space($root);
            $total = disk_total_space($root);
            if (!$free || !$total) return 0;
            return round((1 - ($free / $total)) * 100, 2);
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get disk usage', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function getRamPercentage()
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $mem = @shell_exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value');
                if (!$mem) return 0;
                preg_match('/TotalVisibleMemorySize=(\d+)/', $mem, $totalMatch);
                preg_match('/FreePhysicalMemory=(\d+)/', $mem, $freeMatch);
                if (!$totalMatch || !$freeMatch) return 0;
                $total = (int) $totalMatch[1];
                $free = (int) $freeMatch[1];
                $used = $total - $free;
                return round(($used / $total) * 100, 2);
            }
            $mem = @shell_exec('free -m');
            if (!$mem) return 0;
            $lines = explode("\n", trim($mem));
            if (count($lines) < 2) return 0;
            $stats = preg_split('/\s+/', $lines[1]);
            return count($stats) > 2 ? round(($stats[2] / $stats[1]) * 100, 2) : 0;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get RAM usage', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function getCpuPercentage(): float
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $cpu = @shell_exec('wmic cpu get loadpercentage /value');
                $matches = [];
                if ($cpu && preg_match('/LoadPercentage=(\d+)/', $cpu, $matches)) {
                    return (float) $matches[1];
                }
                return 0;
            }
            $load = @sys_getloadavg();
            if (!$load) return 0;
            $cpuCount = (int) @shell_exec('nproc') ?: 1;
            return round(min($load[0] / $cpuCount * 100, 100), 2);
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get CPU usage', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function getNetworkStats(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['rx' => 0, 'tx' => 0];
        }
        try {
            $netstat = @shell_exec('cat /proc/net/dev 2>/dev/null | grep -E "(eth0|enp|wlan)" | head -1');
            if (!$netstat) return ['rx' => 0, 'tx' => 0];

            $parts = preg_split('/\s+/', trim($netstat));
            if (count($parts) < 10) return ['rx' => 0, 'tx' => 0];

            return [
                'rx' => (int) $parts[1],
                'tx' => (int) $parts[9],
            ];
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get network stats', ['error' => $e->getMessage()]);
            return ['rx' => 0, 'tx' => 0];
        }
    }

    private function getDiskIO(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['reads' => 0, 'writes' => 0];
        }
        try {
            $iostat = @shell_exec('iostat -d 1 1 2>/dev/null | grep -E "(sda|vda|nvme)" | head -1');
            if (!$iostat) return ['reads' => 0, 'writes' => 0];

            $parts = preg_split('/\s+/', trim($iostat));
            if (count($parts) < 4) return ['reads' => 0, 'writes' => 0];

            return [
                'reads' => round((float) $parts[1], 2),
                'writes' => round((float) $parts[2], 2),
            ];
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get disk I/O', ['error' => $e->getMessage()]);
            return ['reads' => 0, 'writes' => 0];
        }
    }

    private function getUptime(): int
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = @shell_exec('wmic os get LastBootUpTime /Value 2>nul');
                if (!$output) return 0;
                preg_match('/LastBootUpTime=(\d{14})/', $output, $match);
                if (empty($match[1])) return 0;
                $boot = \DateTime::createFromFormat('YmdHis', substr($match[1], 0, 14));
                return $boot ? (int) (time() - $boot->getTimestamp()) : 0;
            }
            $uptime = @shell_exec('cat /proc/uptime 2>/dev/null');
            if (!$uptime) return 0;
            $parts = explode(' ', trim($uptime));
            return isset($parts[0]) ? (int) $parts[0] : 0;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get uptime', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function getDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            $maxTimeout = 3;

            DB::select('SELECT 1');
            $latency = microtime(true) - $start;

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
        } catch (\Throwable $e) {
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
            $connection = config('beacon.system_db_connection');
            $db = $connection ? DB::connection($connection) : DB::connection();

            if ($connection) {
                $driver = config("database.connections.{$connection}.driver");
            } else {
                $defaultConnection = config('database.default');
                $driver = config("database.connections.{$defaultConnection}.driver");
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                try {
                    $row = $db->selectOne('SELECT COUNT(*) AS cnt FROM performance_schema.data_locks');
                    return isset($row->cnt) ? (int) $row->cnt : 0;
                } catch (\Throwable $e) {
                    try {
                        $row = $db->selectOne('SELECT COUNT(*) AS cnt FROM information_schema.innodb_locks');
                        return isset($row->cnt) ? (int) $row->cnt : 0;
                    } catch (\Throwable $e2) {
                        return 0;
                    }
                }
            } elseif ($driver === 'pgsql') {
                $row = $db->selectOne('SELECT COUNT(*) AS cnt FROM pg_locks');
            } elseif ($driver === 'sqlsrv') {
                try {
                    $row = $db->selectOne('SELECT COUNT(*) AS cnt FROM sys.dm_tran_locks');
                    return isset($row->cnt) ? (int) $row->cnt : 0;
                } catch (\Throwable $e) {
                    $errorMsg = $e->getMessage();
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
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
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

            $stats = [
                'driver' => config('cache.default'),
                'size' => 0,
                'hits' => 0,
                'misses' => 0,
            ];

            if ($store instanceof \Illuminate\Cache\RedisStore) {
                try {
                    $redis = $store->connection();
                    $info = $redis->info();
                    $stats['size'] = $info['db0']['keys'] ?? 0;
                    $stats['hits'] = $info['keyspace_hits'] ?? 0;
                    $stats['misses'] = $info['keyspace_misses'] ?? 0;
                } catch (\Throwable $e) {
                    // Redis stats not available
                }
            }

            return $stats;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get cache stats', ['error' => $e->getMessage()]);
            return ['driver' => 'unknown', 'size' => 0, 'hits' => 0, 'misses' => 0];
        }
    }

    private function getAvgResponseTime(): float
    {
        try {
            if (!Schema::hasTable('request_logs')) {
                return 0;
            }

            return round(DB::table('request_logs')
                ->where('created_at', '>=', now()->subHour())
                ->avg('response_time') ?? 0, 2);
        } catch (\Throwable $e) {
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

            return $certInfo['validTo_time_t'] - time();
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
                return 0;
            } elseif ($driver === 'file') {
                $sessionPath = config('session.files') ?: session_save_path() ?: sys_get_temp_dir() . '/sessions';
                if (!is_dir($sessionPath)) return 0;

                $files = glob($sessionPath . '/sess_*');
                $active = 0;
                foreach ($files as $file) {
                    if (filemtime($file) >= time() - 1800) {
                        $active++;
                    }
                }
                return $active;
            }

            return 0;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get active sessions', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
