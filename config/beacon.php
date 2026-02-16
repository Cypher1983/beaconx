<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WatchTowerX Hub Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your WatchTowerX hub connection details here.
    |
    */

    'hub_url' => env('WATCHTOWER_HUB_URL'),
    'token'   => env('WATCHTOWER_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Elevated Database Connection (Optional)
    |--------------------------------------------------------------------------
    |
    | Specify an alternative database connection with elevated privileges
    | for monitoring advanced database metrics like lock counts.
    |
    | This allows BeaconX to query performance_schema and other monitoring
    | tables that may require elevated database user permissions.
    |
    | Leave null or blank to use the default database connection.
    | Example: 'system_db_connection' => 'mysql_monitor'
    |
    | See README.md for step-by-step setup instructions.
    |
    */

    'system_db_connection' => env('DB_MONITOR_CONNECTION', null),
];
