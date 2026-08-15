<?php

declare(strict_types=1);

/**
 * Enforce Peanut License Server's distinct PHP host and development floors.
 *
 * The distributed plugin supports PHP 8.0. Its PHPUnit 10 development graph
 * requires PHP 8.1, so required CI proves those contracts in separate lanes.
 */

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root . '/' . $relative;
    $contents = is_file($path) ? file_get_contents($path) : false;

    if ($contents === false) {
        $failures[] = sprintf('%s is missing or unreadable', $relative);

        return '';
    }

    return $contents;
};

$composer = json_decode($read('composer.json'), true);

if (!is_array($composer)) {
    $failures[] = 'composer.json is not valid JSON';
} else {
    if (($composer['require']['php'] ?? null) !== '>=8.0') {
        $failures[] = 'composer.json require.php must preserve the PHP 8.0 host floor';
    }
    if (($composer['config']['platform']['php'] ?? null) !== '8.1.0') {
        $failures[] = 'composer.json config.platform.php must be exact PHP 8.1.0';
    }
    if (($composer['require-dev']['phpunit/phpunit'] ?? null) !== '^10.0') {
        $failures[] = 'composer.json must retain the PHPUnit 10 development-floor witness';
    }
}

if (!preg_match('/^\s*\* Requires PHP:\s*8\.0\s*$/m', $read('peanut-license-server.php'))) {
    $failures[] = 'peanut-license-server.php must declare PHP 8.0';
}

$readme = $read('README.md');
if (!preg_match('/^- PHP 8\.0\+$/m', $readme)
    || !str_contains($readme, 'full mock test suites require PHP 8.1 or later')) {
    $failures[] = 'README.md must document PHP 8.0 hosts and PHP 8.1 development';
}

$workflow = $read('.github/workflows/tests.yml');

$job = static function (string $name) use ($workflow, &$failures): string {
    $pattern = sprintf('/^  %s:\s*$\R(?<body>(?:(?!^  [a-zA-Z0-9_-]+:\s*$).)*)/ms', preg_quote($name, '/'));

    if (!preg_match($pattern, $workflow, $match)) {
        $failures[] = sprintf('.github/workflows/tests.yml is missing required %s job', $name);

        return '';
    }

    return $match[0];
};

$runtimeJob = $job('php-runtime-minimum');
$developmentJob = $job('php-development-minimum');
$currentJob = $job('php-tests');

$requiredPatterns = [
    [$runtimeJob, '/php-version:\s*["\']8\.0["\']/', 'php-runtime-minimum exact PHP 8.0 setup'],
    [$runtimeJob, '/verify-php-runtime\.php --expect-runtime=8\.0/', 'php-runtime-minimum runtime identity assertion'],
    [$runtimeJob, "/git ls-files -z '\\*\.php' \\| xargs -0 -n1 php -l/", 'php-runtime-minimum tracked-tree parser gate'],
    [$developmentJob, '/php-version:\s*["\']8\.1["\']/', 'php-development-minimum exact PHP 8.1 setup'],
    [$developmentJob, '/composer install --no-interaction --prefer-dist/', 'php-development-minimum dependency installation'],
    [$developmentJob, '/verify-php-runtime\.php --expect-development-runtime=8\.1/', 'php-development-minimum runtime identity assertion'],
    [$developmentJob, '/phpunit --testsuite=Unit/', 'php-development-minimum Unit suite'],
    [$developmentJob, '/phpunit --testsuite=Integration/', 'php-development-minimum Integration suite'],
    [$developmentJob, '/phpunit --testsuite=Property/', 'php-development-minimum Property suite'],
    [$currentJob, '/php-version:\s*["\']8\.2["\']/', 'php-tests current PHP 8.2 coverage lane'],
];

foreach ($requiredPatterns as [$subject, $pattern, $description]) {
    if (!preg_match($pattern, $subject)) {
        $failures[] = sprintf('%s is missing', $description);
    }
}

$contractWorkflow = $read('.github/workflows/wp-contract.yml');
if (!preg_match('/php-version:\s*["\']8\.3["\']/', $contractWorkflow)) {
    $failures[] = 'wp-contract must retain the real WordPress PHP 8.3 lane';
}

$argument = $argv[1] ?? '';
if ($argument === '--expect-runtime=8.0' && PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== '8.0') {
    $failures[] = sprintf('expected the PHP 8.0 host runtime, got %s', PHP_VERSION);
}
if ($argument === '--expect-development-runtime=8.1' && PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== '8.1') {
    $failures[] = sprintf('expected the PHP 8.1 development runtime, got %s', PHP_VERSION);
}

if ($failures !== []) {
    fwrite(STDERR, "PHP runtime declaration contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PHP runtime declaration contract passed (host 8.0, development 8.1).\n");
