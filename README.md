# BeaconX

**The Official Monitoring Signal for WatchTowerX.**

BeaconX is a lightweight Laravel package designed to be installed on client applications. It gathers critical system health metrics and transmits them back to your central **WatchTowerX** hub.

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

## Metrics Collected

BeaconX automatically gathers and transmits:

- **System:** Disk Usage and RAM Usage.
- **Workload:** Total number of failed background jobs.
- **Runtime:** PHP and Laravel versions for environment consistency.
- **Identity:** The APP_NAME defined in your client application.

## Security

Data is transmitted over HTTPS and authenticated via a secure X-Beacon-Token header. No sensitive environment variables (like .env contents) are ever transmitted.

## Contributing

If you wish to extend the metrics gathered, please submit a PR to the internal watchtowerx/beaconx repository.

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
