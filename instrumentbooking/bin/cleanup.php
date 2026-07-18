#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cleanup.php must be run from PHP CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/helper.php';

try {
    $options = parse_args($argv);
    $helper = new helper_plugin_instrumentbooking();
    $envConfig = getenv('PLUGIN_CONFIG');
    $configPath = $options['config'] ?? (is_string($envConfig) && $envConfig !== '' ? $envConfig : null);
    $config = $helper->loadConfig($configPath);
    $pdo = $helper->connect($config);

    if (!empty($options['verbose'])) {
        echo "Database: " . $config['database_path'] . "\n";
        echo "Cancelled retention days: " . $config['cancelled_retention_days'] . "\n";
        echo "History retention days: " . $config['history_retention_days'] . "\n";
    }

    $result = $helper->cleanup($pdo, $config, !empty($options['dry-run']));
    echo ($result['dryRun'] ? "Dry run complete.\n" : "Cleanup complete.\n");
    echo "Cancelled events checked: " . $result['cancelledChecked'] . "\n";
    echo "Historical bookings checked: " . $result['historyChecked'] . "\n";
    echo "Cancelled events deleted: " . $result['cancelledDeleted'] . "\n";
    echo "Historical bookings deleted: " . $result['historyDeleted'] . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Cleanup failed: " . $e->getMessage() . "\n");
    exit(1);
}

function parse_args(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dry-run'] = true;
        } elseif ($arg === '--verbose') {
            $options['verbose'] = true;
        } elseif (str_starts_with($arg, '--config=')) {
            $options['config'] = substr($arg, 9);
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "Usage: php bin/cleanup.php [--config=/path/to/instrumentbooking.local.php] [--dry-run] [--verbose]\n";
            exit(0);
        } else {
            throw new RuntimeException('Unknown option: ' . $arg);
        }
    }
    return $options;
}
