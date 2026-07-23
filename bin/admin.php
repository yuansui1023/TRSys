#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "admin.php must be run from PHP CLI.\n");
    exit(1);
}

try {
    $args = array_values(array_slice($argv, 1));
    if ($args === [] || in_array($args[0], ['--help', '-h'], true)) {
        echo "Usage:\n";
        echo "  php bin/admin.php bootstrap <username> [--config=/path/to/instrumentbooking.local.php]\n";
        echo "  php bin/admin.php revoke <username> [--config=/path/to/instrumentbooking.local.php]\n";
        echo "  php bin/admin.php list [--config=/path/to/instrumentbooking.local.php]\n";
        exit(0);
    }

    $command = array_shift($args);
    $configPath = null;
    $username = null;
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--config=')) {
            $configPath = substr($arg, 9);
            continue;
        }
        if ($username === null) {
            $username = $arg;
            continue;
        }
        throw new RuntimeException('Unknown option: ' . $arg);
    }

    if (!in_array($command, ['bootstrap', 'revoke', 'list'], true)) {
        throw new RuntimeException('Unknown command: ' . $command);
    }
    if ($command !== 'list' && (!is_string($username) || trim($username) === '')) {
        throw new RuntimeException('Username is required.');
    }
    if ($command === 'list' && $username !== null) {
        throw new RuntimeException('The list command does not accept a username.');
    }

    if (in_array($command, ['bootstrap', 'list'], true)) {
        load_dokuwiki_for_auth();
    }

    require_once dirname(__DIR__) . '/helper.php';
    $helper = new helper_plugin_instrumentbooking();
    $envConfig = getenv('PLUGIN_CONFIG');
    if ($configPath === null && is_string($envConfig) && $envConfig !== '') {
        $configPath = $envConfig;
    }
    $config = $helper->loadBookingConfig($configPath);
    $pdo = $helper->connect($config);

    if ($command === 'bootstrap') {
        $result = $helper->bootstrapPluginAdminCli($pdo, $username);
        echo "Bootstrapped first TRSys administrator: " . $result['username'] . "\n";
        echo "DokuWiki user accounts were not modified.\n";
        exit(0);
    }

    if ($command === 'list') {
        $admins = $helper->listPluginAdmins($pdo);
        if ($admins === []) {
            echo "No TRSys administrators configured.\n";
            exit(0);
        }
        echo "TRSys administrators:\n";
        foreach ($admins as $admin) {
            echo "  " . $admin['username'] . "\n";
        }
        exit(0);
    }

    $result = $helper->revokePluginAdminCli($pdo, $username);
    echo "Revoked TRSys administrator: " . $result['username'] . "\n";
    echo "Remaining TRSys administrators: " . $result['remainingAdmins'] . "\n";
    echo "DokuWiki user accounts were not modified.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Admin command failed: " . $e->getMessage() . "\n");
    exit(1);
}

function load_dokuwiki_for_auth(): void
{
    $root = getenv('DOKUWIKI_ROOT');
    if (!is_string($root) || $root === '') {
        $guess = dirname(__DIR__, 4);
        if (is_file($guess . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'init.php')) {
            $root = $guess;
        }
    }
    if (!is_string($root) || $root === '') {
        throw new RuntimeException(
            'Unable to load DokuWiki auth. Set DOKUWIKI_ROOT to the DokuWiki root directory.'
        );
    }

    $init = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'init.php';
    if (!is_file($init)) {
        throw new RuntimeException('DokuWiki init.php not found at: ' . $init);
    }

    if (!defined('DOKU_INC')) {
        define('DOKU_INC', rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
    require_once $init;
}
