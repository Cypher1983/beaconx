<?php

namespace WatchTowerX\BeaconX\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use WatchTowerX\BeaconX\Beacon;

class TransmitMetrics extends Command
{
    protected $signature = 'beacon:transmit';
    protected $description = 'Transmit health signal to WatchTowerX Hub';

    public function handle(Beacon $beacon)
    {
        $url = config('beacon.hub_url');
        $token = config('beacon.token');

        if (!$url || !$token) {
            $this->error('BeaconX: Hub URL or Token not found in .env');
            return;
        }

        $response = Http::withHeaders(['X-Beacon-Token' => $token])
            ->post($url, $beacon->emit());

        $response->successful()
            ? $this->info('📡 Signal received by WatchTowerX.')
            : $this->error('❌ Signal lost. Status: ' . $response->status());
    }
}
