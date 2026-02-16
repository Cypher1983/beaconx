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

        try {
            // Bypass SSL verification for HTTPS (common on Windows with self-signed certs)
            $response = Http::withHeaders(['X-Beacon-Token' => $token])
                ->withoutVerifying()
                ->timeout(10)
                ->post($url, $beacon->emit());

            if ($response->successful()) {
                $this->info('📡 Signal received by WatchTowerX.');
            } else {
                $statusCode = $response->status();
                $body = $response->body();
                $this->error("❌ Signal lost. Status: {$statusCode}");
                if (!empty($body)) {
                    $this->error("Response: " . substr($body, 0, 500));
                }
            }
        } catch (\Exception $e) {
            $this->error('❌ Failed to transmit signal: ' . $e->getMessage());
        }
    }
}