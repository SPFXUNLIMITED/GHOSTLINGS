<?php

declare(strict_types=1);

if (!defined('GHOST_ENV_BOOTSTRAPPED')) {
    define('GHOST_ENV_BOOTSTRAPPED', true);

    $root = __DIR__;
    $envFile = $root . '/.env';

    $autoloadCandidates = [
        $root . '/vendor/autoload.php',
        $root . '/api/vendor/autoload.php',
    ];

    $autoloadPath = null;
    foreach ($autoloadCandidates as $candidate) {
        if (is_file($candidate)) {
            $autoloadPath = $candidate;
            break;
        }
    }

    if ($autoloadPath !== null) {
        require_once $autoloadPath;

        if (class_exists(\Dotenv\Dotenv::class) && is_file($envFile) && is_readable($envFile)) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }
    } elseif (is_file($envFile) && is_readable($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if ($name === '') {
                    continue;
                }

                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last  = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                if (getenv($name) === false) {
                    putenv($name . '=' . $value);
                }
                if (!array_key_exists($name, $_ENV)) {
                    $_ENV[$name] = $value;
                }
                if (!array_key_exists($name, $_SERVER)) {
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}
