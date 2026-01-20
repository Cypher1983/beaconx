<?php

require_once 'vendor/autoload.php';

// Simple test of Beacon class
use WatchTowerX\BeaconX\Beacon;

try {
    $beacon = new Beacon();
    $data = $beacon->emit();

    echo "BeaconX Test Results:\n";
    echo "===================\n\n";

    foreach ($data as $category => $metrics) {
        echo ucfirst($category) . ":\n";
        if (is_array($metrics)) {
            foreach ($metrics as $key => $value) {
                if (is_array($value)) {
                    echo "  {$key}: " . json_encode($value) . "\n";
                } else {
                    echo "  {$key}: {$value}\n";
                }
            }
        } else {
            echo "  {$metrics}\n";
        }
        echo "\n";
    }

    echo "✅ BeaconX implementation successful!\n";
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
