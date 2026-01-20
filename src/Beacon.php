<?php

namespace WatchTowerX\BeaconX;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Beacon
{
    public function emit(): array
    {
        return [
            'identity' => config('app.name'),
            'system' => [
                'disk' => $this->getDiskPercentage(),
                'ram' => $this->getRamPercentage(),
            ],
            'workload' => [
                'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
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
        $mem = shell_exec('free -m');
        if (!$mem) return 0;
        $lines = explode("\n", trim($mem));
        $stats = preg_split('/\s+/', $lines[1]);
        return round(($stats[2] / $stats[1]) * 100, 2);
    }
}
