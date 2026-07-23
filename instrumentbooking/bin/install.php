#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "install.php must be run from PHP CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/helper.php';

try {
    $options = parse_args($argv);
    $helper = new helper_plugin_instrumentbooking();
    $envConfig = getenv('PLUGIN_CONFIG');
    $configPath = $options['config'] ?? (is_string($envConfig) && $envConfig !== '' ? $envConfig : null);
    $config = $helper->loadBookingConfig($configPath);
    $databasePath = $config['database_path'];
    $databaseDir = dirname($databasePath);

    $dokuRoot = getenv('DOKUWIKI_ROOT');
    if (is_string($dokuRoot) && $dokuRoot !== '' && path_is_inside($databasePath, $dokuRoot)) {
        throw new RuntimeException('Refusing to place SQLite database inside the DokuWiki web root.');
    }

    $fsProbePath = nearest_existing_parent($databaseDir);
    $fsType = $helper->detectFilesystemType($fsProbePath);
    if ($helper->isNetworkFilesystem($fsType)) {
        throw new RuntimeException('SQLite target is on unsupported network filesystem type: ' . $fsType . '. Use PostgreSQL instead.');
    }

    if (!is_dir($databaseDir) && !mkdir($databaseDir, 0770, true) && !is_dir($databaseDir)) {
        throw new RuntimeException('Unable to create database directory.');
    }
    if (!is_writable($databaseDir)) {
        throw new RuntimeException('Database directory is not writable by this user.');
    }

    $pdo = $helper->connect($config, true);
    $helper->applySchema($pdo, dirname(__DIR__) . '/db/schema.sql');
    @chmod($databasePath, 0660);

    echo "Instrument Booking database initialized.\n";
    echo "Config: " . ($configPath ?: $helper->defaultConfigPath()) . "\n";
    echo "Database: " . $databasePath . "\n";
    echo "Filesystem type: " . $fsType . "\n";
    echo "Schema version: " . $helper->schemaVersion($pdo) . "\n";
    if ($fsType === 'unknown') {
        echo "Warning: filesystem type could not be detected automatically. Verify it is local disk, not NFS/SMB.\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Install failed: " . $e->getMessage() . "\n");
    exit(1);
}

function parse_args(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--config=')) {
            $options['config'] = substr($arg, 9);
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "Usage: php bin/install.php [--config=/path/to/instrumentbooking.local.php]\n";
            exit(0);
        } else {
            throw new RuntimeException('Unknown option: ' . $arg);
        }
    }
    return $options;
}

function nearest_existing_parent(string $path): string
{
    $current = $path;
    while (!is_dir($current)) {
        $parent = dirname($current);
        if ($parent === $current) {
            return $current;
        }
        $current = $parent;
    }
    return $current;
}

function path_is_inside(string $path, string $root): bool
{
    $rootReal = realpath($root);
    if ($rootReal === false) {
        return false;
    }
    $pathReal = realpath($path) ?: realpath(dirname($path));
    if ($pathReal === false) {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $rootReal), '/');
        return str_starts_with($normalizedPath, $normalizedRoot . '/');
    }
    return str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}
