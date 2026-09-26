<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-NHA project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

/**
 * Compares the live NHA server with the baseline this code was written
 * against, so a rule change on the server becomes a task instead of a
 * surprise. See {@see \NHA\Upstream\Watcher}.
 *
 *   php upstream-watch.php                    Report what moved. Exit 1 if anything did.
 *   php upstream-watch.php --issue            Open, rewrite or close the drift issue on GitHub.
 *   php upstream-watch.php --issue --dry-run  Say what it would do to the issue, and do nothing.
 *   php upstream-watch.php --accept           Take the live server as the new baseline.
 *   php upstream-watch.php --accept=rules,source   The same, for only these sources.
 *
 * Environment:
 *   GH_TOKEN / GITHUB_TOKEN  Needed to write the issue. Reads work without one, within
 *                            GitHub's anonymous rate limit.
 *   GITHUB_REPOSITORY        Where the issue goes (default Valgorithms/DiscordPHP-NHA);
 *                            `--repo=owner/name` overrides it.
 *   NHA_BASE_URL             Another NHA world to watch instead of the public one.
 *
 * Exit codes: 0 nothing moved (or, with --issue, the issue is up to date); 1 the server
 * moved; 2 the check itself failed. A failed check leaves the issue alone.
 */

use Discord\Http\Drivers\Guzzle;
use NHA\Http\Http;
use NHA\Upstream\GitHub;
use NHA\Upstream\Report;
use NHA\Upstream\Snapshot;
use NHA\Upstream\Watcher;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Http\Browser;

require __DIR__ . '/vendor/autoload.php';

$opts = getopt('', ['issue', 'dry-run', 'accept::', 'repo:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php upstream-watch.php [--issue [--dry-run]] [--accept[=source,...]] [--repo=owner/name]\nSources: " . implode(', ', Snapshot::SOURCES) . "\n");
    exit(0);
}
$accept = null;
if (array_key_exists('accept', $opts)) {
    $accept = $opts['accept'] === false ? Snapshot::SOURCES : array_values(array_filter(array_map('trim', explode(',', (string) $opts['accept']))));
}
$repo = (string) ($opts['repo'] ?? (getenv('GITHUB_REPOSITORY') ?: 'Valgorithms/DiscordPHP-NHA'));
$token = (string) (getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN') ?: '');

$loop = Loop::get();
// Guzzle, not React: react/socket stalls on the live host's TLS (AGENTS.md, rule 16).
$nha = new Http('', $loop, new NullLogger(), new Guzzle($loop), (string) (getenv('NHA_BASE_URL') ?: Http::BASE_URL));
$watcher = new Watcher($nha, new GitHub(new Browser(null, $loop), $token), __DIR__ . '/upstream', __DIR__ . '/openapi.json');

$exit = 2;
$watcher->live()
    ->then(function (array $live) use ($watcher, $accept, $opts, $repo) {
        $baseline = $watcher->baseline();

        return $watcher->drift($baseline, $live['views'])->then(function (array $drift) use ($watcher, $live, $accept, $opts, $repo): mixed {
            foreach ($watcher->missing() as $s) {
                fwrite(STDERR, "No baseline for `{$s}` yet: `php upstream-watch.php --accept={$s}` starts one.\n");
            }
            echo $drift === []
                ? "The live server matches the baseline.\n"
                : '# ' . Report::title($drift) . "\n\n" . preg_replace('/\n<!-- .* -->\n$/s', '', Report::body($drift, [], gmdate('Y-m-d H:i') . ' UTC')) . "\n";

            if ($accept !== null) {
                foreach ($watcher->accept($live, $accept) as $path) {
                    echo 'Baseline written: ' . basename(dirname($path)) . '/' . basename($path) . "\n";
                }

                return 0;
            }
            if (! isset($opts['issue'])) {
                return $drift === [] ? 0 : 1;
            }

            return $watcher->file($repo, $drift, $live['views'], isset($opts['dry-run']))->then(static function (string $done) use ($repo): int {
                echo "Issue ({$repo}): {$done}\n";

                return 0;
            });
        });
    })
    ->then(static function (int $code) use (&$exit): void {
        $exit = $code;
    }, static function (\Throwable $e) use (&$exit): void {
        fwrite(STDERR, 'upstream-watch failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
        $exit = 2;
    })
    ->finally(static fn() => $loop->stop());

$loop->run();
exit($exit);
