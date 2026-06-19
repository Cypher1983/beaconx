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
            $connections = $this->getDbConnectionStats();
            $longRunning = $this->getDbLongRunningQueries();
            $deadlocks = $this->getDbDeadlockCount();
            $bufferPoolHitRatio = $this->getDbBufferPoolHitRatio();
            $replicationLag = $this->getDbReplicationLag();
            $dbSize = $this->getDbSizeMb();
            $deadTuples = $this->getDbDeadTuples();
            $abortedConnections = $this->getDbAbortedConnections();
            $slowQueries = $this->getDbSlowQueryCount();
            $waitingQueries = $this->getDbWaitingQueries();
            $largestTables = $this->getDbLargestTables();
            $autovacuumStaleness = $this->getDbAutovacuumStaleness();
            $rowLockWaits = $this->getDbRowLockWaits();
            $undoHistoryLength = $this->getDbUndoHistoryLength();
            $pageLifeExpectancy = $this->getDbPageLifeExpectancy();
            $unusedIndexes = $this->getDbUnusedIndexCount();
            $oldestTxnAge = $this->getDbOldestOpenTransactionAge();
            $tmpDiskRatio = $this->getDbTmpTableDiskRatio();
            $fullTableScans = $this->getDbFullTableScanRate();
            $tableFragmentation = $this->getDbTableFragmentationMb();
            $bgwriterPressure = $this->getDbBgwriterBackendWrites();
            $mssqlBufferCacheHit = $this->getDbMssqlBufferCacheHitRatio();
            $mssqlBatchRequests = $this->getDbMssqlBatchRequestsPerSec();
            $mssqlTempdbMb = $this->getDbMssqlTempdbUsageMb();

            return [
                'status' => 'healthy',
                'latency_ms' => (float) number_format($latency * 1000, 2, '.', ''),
                'locks' => $locks,
                'connections_active' => $connections['active'],
                'connections_max' => $connections['max'],
                'connections_used_pct' => $connections['used_pct'],
                'long_running_queries' => $longRunning,
                'waiting_queries' => $waitingQueries,
                'oldest_open_txn_seconds' => $oldestTxnAge,
                'deadlocks' => $deadlocks,
                'row_lock_waits' => $rowLockWaits['count'],
                'row_lock_avg_wait_ms' => $rowLockWaits['avg_ms'],
                'undo_history_length' => $undoHistoryLength,
                'aborted_connections' => $abortedConnections,
                'slow_queries' => $slowQueries,
                'tmp_tables_to_disk_pct' => $tmpDiskRatio,
                'full_table_scans' => $fullTableScans,
                'table_fragmentation_mb' => $tableFragmentation,
                'buffer_pool_hit_ratio' => $bufferPoolHitRatio,
                'bgwriter_backend_writes' => $bgwriterPressure,
                'page_life_expectancy' => $pageLifeExpectancy,
                'mssql_buffer_cache_hit_ratio' => $mssqlBufferCacheHit,
                'mssql_batch_requests_per_sec' => $mssqlBatchRequests,
                'mssql_tempdb_used_mb' => $mssqlTempdbMb,
                'replication_lag_seconds' => $replicationLag,
                'size_mb' => $dbSize,
                'dead_tuples' => $deadTuples,
                'unused_indexes' => $unusedIndexes,
                'autovacuum_max_staleness_seconds' => $autovacuumStaleness,
                'largest_tables' => $largestTables,
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

    private function getDbConnectionStats(): array
    {
        $default = ['active' => null, 'max' => null, 'used_pct' => null];

        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $status = DB::select("SHOW STATUS WHERE Variable_name IN ('Threads_connected','Max_used_connections')");
                $vars = DB::select("SHOW VARIABLES WHERE Variable_name = 'max_connections'");

                $active = null;
                foreach ($status as $row) {
                    if ($row->Variable_name === 'Threads_connected') {
                        $active = (int) $row->Value;
                    }
                }
                $max = isset($vars[0]) ? (int) $vars[0]->Value : null;
                $usedPct = ($active !== null && $max) ? round($active / $max * 100, 2) : null;

                return ['active' => $active, 'max' => $max, 'used_pct' => $usedPct];
            }

            if ($driver === 'pgsql') {
                $active = DB::selectOne("SELECT COUNT(*) AS cnt FROM pg_stat_activity WHERE state = 'active'");
                $max = DB::selectOne("SELECT setting FROM pg_settings WHERE name = 'max_connections'");

                $activeCount = $active ? (int) $active->cnt : null;
                $maxCount = $max ? (int) $max->setting : null;
                $usedPct = ($activeCount !== null && $maxCount) ? round($activeCount / $maxCount * 100, 2) : null;

                return ['active' => $activeCount, 'max' => $maxCount, 'used_pct' => $usedPct];
            }

            if ($driver === 'sqlsrv') {
                try {
                    $row = DB::selectOne('SELECT COUNT(*) AS cnt FROM sys.dm_exec_sessions WHERE is_user_process = 1');
                    $activeCount = $row ? (int) $row->cnt : null;
                    return ['active' => $activeCount, 'max' => null, 'used_pct' => null];
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    if (str_contains($msg, 'permission was denied') || str_contains($msg, 'Login failed')) {
                        return $default;
                    }
                    throw $e;
                }
            }

            return $default;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get connection stats', ['error' => $e->getMessage()]);
            return $default;
        }
    }

    private function getDbLongRunningQueries(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne("SELECT COUNT(*) AS cnt FROM information_schema.PROCESSLIST WHERE TIME > 10 AND COMMAND != 'Sleep'");
                return $row ? (int) $row->cnt : null;
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne("SELECT COUNT(*) AS cnt FROM pg_stat_activity WHERE state = 'active' AND query_start < NOW() - INTERVAL '10 seconds'");
                return $row ? (int) $row->cnt : null;
            }

            if ($driver === 'sqlsrv') {
                try {
                    $row = DB::selectOne('SELECT COUNT(*) AS cnt FROM sys.dm_exec_requests WHERE total_elapsed_time > 10000');
                    return $row ? (int) $row->cnt : null;
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    if (str_contains($msg, 'permission was denied') || str_contains($msg, 'Login failed')) {
                        return null;
                    }
                    throw $e;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get long-running query count', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbDeadlockCount(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'");
                return $row ? (int) $row->Value : null;
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne('SELECT SUM(deadlocks) AS cnt FROM pg_stat_database');
                return $row ? (int) $row->cnt : null;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get deadlock count', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbBufferPoolHitRatio(): ?float
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $rows = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_buffer_pool_read_requests','Innodb_buffer_pool_reads')");
                $stats = [];
                foreach ($rows as $row) {
                    $stats[$row->Variable_name] = (float) $row->Value;
                }
                $requests = $stats['Innodb_buffer_pool_read_requests'] ?? 0;
                $diskReads = $stats['Innodb_buffer_pool_reads'] ?? 0;
                if ($requests === 0.0) return null;
                return round((($requests - $diskReads) / $requests) * 100, 2);
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne('SELECT SUM(heap_blks_hit) AS hits, SUM(heap_blks_read) AS reads FROM pg_statio_user_tables');
                if (!$row) return null;
                $hits = (float) $row->hits;
                $reads = (float) $row->reads;
                $total = $hits + $reads;
                return $total > 0 ? round(($hits / $total) * 100, 2) : null;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get buffer pool hit ratio', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbReplicationLag(): ?float
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                try {
                    $rows = DB::select('SHOW SLAVE STATUS');
                    if (empty($rows)) {
                        $rows = DB::select('SHOW REPLICA STATUS');
                    }
                    if (empty($rows)) return null;
                    $lag = $rows[0]->Seconds_Behind_Master ?? $rows[0]->Seconds_Behind_Source ?? null;
                    return $lag !== null ? (float) $lag : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne("SELECT EXTRACT(EPOCH FROM (now() - pg_last_xact_replay_timestamp())) AS lag");
                if (!$row || $row->lag === null) return null;
                return round((float) $row->lag, 2);
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get replication lag', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbSizeMb(): ?float
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");
            $dbName = config("database.connections.{$defaultConnection}.database");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne(
                    "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                     FROM information_schema.tables WHERE table_schema = ?",
                    [$dbName]
                );
                return $row && $row->size_mb !== null ? (float) $row->size_mb : null;
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne('SELECT ROUND(pg_database_size(current_database()) / 1024.0 / 1024.0, 2) AS size_mb');
                return $row ? (float) $row->size_mb : null;
            }

            if ($driver === 'sqlsrv') {
                try {
                    $row = DB::selectOne(
                        "SELECT ROUND(SUM(size) * 8.0 / 1024, 2) AS size_mb FROM sys.database_files WHERE type_desc = 'ROWS'"
                    );
                    return $row ? (float) $row->size_mb : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get database size', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbDeadTuples(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if ($driver !== 'pgsql') return null;

            $row = DB::selectOne('SELECT SUM(n_dead_tup) AS cnt FROM pg_stat_user_tables');
            return $row ? (int) $row->cnt : null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get dead tuple count', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbAbortedConnections(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne("SHOW GLOBAL STATUS LIKE 'Aborted_connects'");
                return $row ? (int) $row->Value : null;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get aborted connections', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbSlowQueryCount(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne("SHOW GLOBAL STATUS LIKE 'Slow_queries'");
                return $row ? (int) $row->Value : null;
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne(
                    "SELECT COUNT(*) AS cnt FROM pg_stat_statements WHERE mean_exec_time > 1000"
                );
                return $row ? (int) $row->cnt : null;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get slow query count', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbWaitingQueries(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne("SELECT COUNT(*) AS cnt FROM information_schema.PROCESSLIST WHERE STATE != '' AND STATE != 'Sleep' AND STATE LIKE '%wait%'");
                return $row ? (int) $row->cnt : null;
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne("SELECT COUNT(*) AS cnt FROM pg_stat_activity WHERE wait_event IS NOT NULL AND state = 'active'");
                return $row ? (int) $row->cnt : null;
            }

            if ($driver === 'sqlsrv') {
                try {
                    $row = DB::selectOne('SELECT COUNT(*) AS cnt FROM sys.dm_exec_requests WHERE blocking_session_id > 0');
                    return $row ? (int) $row->cnt : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get waiting query count', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbLargestTables(): array
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");
            $dbName = config("database.connections.{$defaultConnection}.database");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $rows = DB::select(
                    "SELECT table_name, ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb
                     FROM information_schema.tables
                     WHERE table_schema = ?
                     ORDER BY size_mb DESC LIMIT 5",
                    [$dbName]
                );
                return array_map(fn($r) => ['table' => $r->table_name, 'size_mb' => (float) $r->size_mb], $rows);
            }

            if ($driver === 'pgsql') {
                $rows = DB::select(
                    "SELECT relname AS table_name, ROUND(pg_total_relation_size(relid) / 1024.0 / 1024.0, 2) AS size_mb
                     FROM pg_stat_user_tables
                     ORDER BY pg_total_relation_size(relid) DESC LIMIT 5"
                );
                return array_map(fn($r) => ['table' => $r->table_name, 'size_mb' => (float) $r->size_mb], $rows);
            }

            if ($driver === 'sqlsrv') {
                try {
                    $rows = DB::select(
                        "SELECT TOP 5 t.name AS table_name,
                            ROUND((SUM(a.total_pages) * 8) / 1024.0, 2) AS size_mb
                         FROM sys.tables t
                         INNER JOIN sys.indexes i ON t.object_id = i.object_id
                         INNER JOIN sys.partitions p ON i.object_id = p.object_id AND i.index_id = p.index_id
                         INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
                         GROUP BY t.name ORDER BY size_mb DESC"
                    );
                    return array_map(fn($r) => ['table' => $r->table_name, 'size_mb' => (float) $r->size_mb], $rows);
                } catch (\Throwable $e) {
                    return [];
                }
            }

            return [];
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get largest tables', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function getDbAutovacuumStaleness(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if ($driver !== 'pgsql') return null;

            $row = DB::selectOne(
                "SELECT EXTRACT(EPOCH FROM (NOW() - MIN(GREATEST(last_autovacuum, last_autoanalyze))))::int AS staleness
                 FROM pg_stat_user_tables
                 WHERE last_autovacuum IS NOT NULL OR last_autoanalyze IS NOT NULL"
            );

            return $row && $row->staleness !== null ? (int) $row->staleness : null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get autovacuum staleness', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbRowLockWaits(): array
    {
        $default = ['count' => null, 'avg_ms' => null];
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (!in_array($driver, ['mysql', 'mariadb'], true)) return $default;

            $rows = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits','Innodb_row_lock_time_avg')");
            $stats = [];
            foreach ($rows as $row) {
                $stats[$row->Variable_name] = $row->Value;
            }

            return [
                'count' => isset($stats['Innodb_row_lock_waits']) ? (int) $stats['Innodb_row_lock_waits'] : null,
                'avg_ms' => isset($stats['Innodb_row_lock_time_avg']) ? (float) $stats['Innodb_row_lock_time_avg'] : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get row lock waits', ['error' => $e->getMessage()]);
            return $default;
        }
    }

    private function getDbUndoHistoryLength(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (!in_array($driver, ['mysql', 'mariadb'], true)) return null;

            $rows = DB::select('SHOW ENGINE INNODB STATUS');
            if (empty($rows)) return null;

            $status = $rows[0]->Status ?? '';
            if (preg_match('/History list length\s+(\d+)/i', $status, $m)) {
                return (int) $m[1];
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get undo history length', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbPageLifeExpectancy(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if ($driver !== 'sqlsrv') return null;

            $row = DB::selectOne(
                "SELECT cntr_value FROM sys.dm_os_performance_counters
                 WHERE counter_name = 'Page life expectancy'
                 AND object_name LIKE '%Buffer Manager%'"
            );

            return $row ? (int) $row->cntr_value : null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get page life expectancy', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbUnusedIndexCount(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if ($driver === 'pgsql') {
                $row = DB::selectOne(
                    "SELECT COUNT(*) AS cnt FROM pg_stat_user_indexes
                     WHERE idx_scan = 0 AND indexrelname NOT LIKE '%pkey%'"
                );
                return $row ? (int) $row->cnt : null;
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $dbName = config("database.connections.{$defaultConnection}.database");
                $row = DB::selectOne(
                    "SELECT COUNT(*) AS cnt FROM sys.schema_unused_indexes
                     WHERE object_schema = ?",
                    [$dbName]
                );
                return $row ? (int) $row->cnt : null;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get unused index count', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbOldestOpenTransactionAge(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne(
                    "SELECT MAX(TIME) AS age FROM information_schema.INNODB_TRX"
                );
                return $row && $row->age !== null ? (int) $row->age : null;
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne(
                    "SELECT EXTRACT(EPOCH FROM (NOW() - MIN(xact_start)))::int AS age
                     FROM pg_stat_activity
                     WHERE xact_start IS NOT NULL AND state != 'idle'"
                );
                return $row && $row->age !== null ? (int) $row->age : null;
            }

            if ($driver === 'sqlsrv') {
                try {
                    $row = DB::selectOne(
                        "SELECT MAX(DATEDIFF(SECOND, transaction_begin_time, GETDATE())) AS age
                         FROM sys.dm_tran_active_transactions"
                    );
                    return $row && $row->age !== null ? (int) $row->age : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get oldest open transaction age', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbTmpTableDiskRatio(): ?float
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (!in_array($driver, ['mysql', 'mariadb'], true)) return null;

            $rows = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Created_tmp_disk_tables','Created_tmp_tables')");
            $stats = [];
            foreach ($rows as $row) {
                $stats[$row->Variable_name] = (int) $row->Value;
            }

            $total = $stats['Created_tmp_tables'] ?? 0;
            $disk = $stats['Created_tmp_disk_tables'] ?? 0;

            return $total > 0 ? round(($disk / $total) * 100, 2) : null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get tmp table disk ratio', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbFullTableScanRate(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (!in_array($driver, ['mysql', 'mariadb'], true)) return null;

            $rows = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Select_scan','Select_full_join')");
            $total = 0;
            foreach ($rows as $row) {
                $total += (int) $row->Value;
            }

            return $total;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get full table scan rate', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbTableFragmentationMb(): ?float
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if (!in_array($driver, ['mysql', 'mariadb'], true)) return null;

            $dbName = config("database.connections.{$defaultConnection}.database");
            $row = DB::selectOne(
                "SELECT ROUND(SUM(DATA_FREE) / 1024 / 1024, 2) AS fragmented_mb
                 FROM information_schema.tables
                 WHERE table_schema = ? AND DATA_FREE > 0",
                [$dbName]
            );

            return $row && $row->fragmented_mb !== null ? (float) $row->fragmented_mb : 0.0;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get table fragmentation', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbBgwriterBackendWrites(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if ($driver !== 'pgsql') return null;

            $row = DB::selectOne('SELECT buffers_backend FROM pg_stat_bgwriter');
            return $row ? (int) $row->buffers_backend : null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get bgwriter backend writes', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbMssqlBufferCacheHitRatio(): ?float
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if ($driver !== 'sqlsrv') return null;

            $rows = DB::select(
                "SELECT counter_name, cntr_value FROM sys.dm_os_performance_counters
                 WHERE counter_name IN ('Buffer cache hit ratio','Buffer cache hit ratio base')
                 AND object_name LIKE '%Buffer Manager%'"
            );

            $values = [];
            foreach ($rows as $row) {
                $values[trim($row->counter_name)] = (float) $row->cntr_value;
            }

            $base = $values['Buffer cache hit ratio base'] ?? 0;
            $hit  = $values['Buffer cache hit ratio'] ?? null;

            if ($hit === null || $base == 0) return null;
            return round(($hit / $base) * 100, 2);
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get MSSQL buffer cache hit ratio', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbMssqlBatchRequestsPerSec(): ?int
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if ($driver !== 'sqlsrv') return null;

            $row = DB::selectOne(
                "SELECT cntr_value FROM sys.dm_os_performance_counters
                 WHERE counter_name = 'Batch Requests/sec'
                 AND object_name LIKE '%SQL Statistics%'"
            );

            return $row ? (int) $row->cntr_value : null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get MSSQL batch requests/sec', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getDbMssqlTempdbUsageMb(): ?float
    {
        try {
            $defaultConnection = config('database.default');
            $driver = config("database.connections.{$defaultConnection}.driver");

            if ($driver !== 'sqlsrv') return null;

            $row = DB::selectOne(
                "SELECT ROUND(SUM(unallocated_extent_page_count + version_store_reserved_page_count
                    + internal_object_reserved_page_count + user_object_reserved_page_count) * 8.0 / 1024, 2) AS used_mb
                 FROM tempdb.sys.dm_db_file_space_usage"
            );

            return $row && $row->used_mb !== null ? (float) $row->used_mb : null;
        } catch (\Throwable $e) {
            Log::warning('BeaconX: Failed to get tempdb usage', ['error' => $e->getMessage()]);
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
