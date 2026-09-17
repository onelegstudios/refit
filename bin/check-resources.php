#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Vendored resource drift checks
|--------------------------------------------------------------------------
|
| Runs every check that compares a committed resource against the upstream it
| was recorded from, and reports them together.
|
| Each is a command of its own; this exists because they only answer a useful
| question as a set. A Composer script list aborts at the first non-zero exit,
| so a Lucide bundle that has drifted would hide whatever Flux and Sheaf had to
| say — the one time you most want to hear it. Here every check runs and the
| exit code is the worst of them.
|
| The Flux scan is passed --optional, which turns "no Flux installed anywhere"
| into a skip rather than an error. Most contributors have no licensed sidecar,
| and an aggregate they cannot run is an aggregate nobody runs.
|
| Usage:
|   php bin/check-resources.php [--help]
|
| Set GITHUB_TOKEN (or GH_TOKEN) to raise the API rate limit. Every request the
| checks below make is to a public repository; none of them needs credentials.
|
*/

const CHECKS = [
    'Lucide bundle' => ['download-icons.php', '--check'],
    'Flux internals' => ['scan-flux-internals.php', '--check', '--optional'],
    'Sheaf components' => ['scan-sheaf-components.php', '--check'],
];

/**
 * What --optional exits with when it found nothing to scan.
 */
const SKIPPED = 2;

exit(main($argv));

function main(array $argv): int
{
    if (in_array('--help', array_slice($argv, 1), true)) {
        info('Usage: php bin/check-resources.php');

        return 0;
    }

    $results = [];

    foreach (CHECKS as $label => $command) {
        info(($results === [] ? '' : PHP_EOL).$label);

        $results[$label] = run($command);
    }

    return report($results);
}

/**
 * Run one check as a child process, letting its own output through as it goes.
 */
function run(array $command): int
{
    $script = array_shift($command);

    array_unshift($command, PHP_BINARY, __DIR__.'/'.$script);

    passthru(implode(' ', array_map('escapeshellarg', $command)), $status);

    return $status;
}

/**
 * @param  array<string, int>  $results
 */
function report(array $results): int
{
    info(PHP_EOL.'Summary');

    foreach ($results as $label => $status) {
        info(sprintf('  %-18s %s', $label, match ($status) {
            0 => 'ok',
            SKIPPED => 'skipped',
            default => 'failed',
        }));
    }

    $failed = array_keys(array_filter(
        $results,
        static fn (int $status): bool => $status !== 0 && $status !== SKIPPED,
    ));

    if ($failed === []) {
        return 0;
    }

    error(sprintf('%s%d check(s) failed: %s', PHP_EOL, count($failed), implode(', ', $failed)));
    error('Scroll up for what each one reported.');

    return 1;
}

function info(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function error(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}
