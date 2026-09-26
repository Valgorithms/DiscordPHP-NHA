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

namespace NHA\Upstream;

use NHA\Http\Endpoint;
use NHA\Http\Http;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Watches the NHA server for rule changes this code has not caught up with.
 *
 * It reads the live server ({@see live()}), compares it with the committed
 * baseline ({@see baseline()}, {@see drift()}), and keeps one labelled GitHub
 * issue in step with the difference ({@see file()}). Once the code has been
 * updated, {@see accept()} makes the live server the new baseline, and the
 * next check closes the issue.
 *
 * NHA reads go through the library's own HTTP client and endpoint map, like
 * every other NHA call. GitHub reads and writes go through {@see GitHub}.
 *
 * Run by `upstream-watch.php` (`composer upstream:check`, and on a schedule
 * by `.github/workflows/upstream-watch.yml`).
 *
 * @since 3.23.0
 */
final class Watcher
{
    /** The public engine source `GameData` is transcribed from. */
    public const SOURCE_REPO = 'Recluse/nha-mmo';

    /** The label that marks the one drift issue. */
    public const LABEL = 'upstream-drift';

    /**
     * @param Http   $nha         An NHA HTTP client. The endpoints read here are public, so it needs no token.
     * @param GitHub $github      For the engine source, and for the issue.
     * @param string $baselineDir Where the committed views live (`upstream/`).
     * @param string $openapiPath The committed API contract (`openapi.json`, also read by the schema-drift test).
     * @param string $sourceRepo  The public engine source.
     */
    public function __construct(
        private Http $nha,
        private GitHub $github,
        private string $baselineDir,
        private string $openapiPath,
        private string $sourceRepo = self::SOURCE_REPO,
    ) {}

    /**
     * The server now: a view of each source ({@see Snapshot}), plus the
     * contract as served, which is what {@see accept()} writes.
     *
     * @return PromiseInterface<array{views: array<string,mixed>, openapi: mixed}>
     */
    public function live(): PromiseInterface
    {
        return all([
            'updates' => $this->nha->get(Endpoint::bind(Endpoint::UPDATES)),
            'openapi' => $this->nha->get(Endpoint::bind(Endpoint::OPENAPI)),
            'rules' => $this->nha->get(Endpoint::bind(Endpoint::RULES)),
            'expansion' => $this->nha->get(Endpoint::bind(Endpoint::EXPANSION)),
            'source' => $this->github->head($this->sourceRepo),
        ])->then(fn(array $r): array => [
            'views' => [
                'updates' => Snapshot::updates(self::arr($r['updates'])),
                'openapi' => Snapshot::openapi(self::arr($r['openapi'])),
                'rules' => Snapshot::rules(self::arr($r['rules'])),
                'colonies' => Snapshot::colonies(self::arr($r['expansion'])),
                'source' => Snapshot::source(self::arr($r['source']), $this->sourceRepo),
            ],
            'openapi' => $r['openapi'],
        ]);
    }

    /**
     * The committed views. A source with no baseline file yet is left out,
     * and so is never reported ({@see missing()}).
     *
     * @return array<string,mixed>
     */
    public function baseline(): array
    {
        $views = [];
        foreach (Snapshot::SOURCES as $s) {
            $path = $this->path($s);
            if (is_file($path)) {
                $data = self::arr(json_decode((string) file_get_contents($path), true));
                $views[$s] = $s === 'openapi' ? Snapshot::openapi($data) : $data;
            }
        }

        return $views;
    }

    /**
     * Sources that have no baseline file yet.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_filter(Snapshot::SOURCES, fn(string $s): bool => ! is_file($this->path($s))));
    }

    /**
     * What moved, fetching GitHub's compare of the engine commits when the
     * source moved.
     *
     * @param array<string,mixed> $baseline {@see baseline()}
     * @param array<string,mixed> $live     `views` of {@see live()}
     *
     * @return PromiseInterface<array<string,list<string>>>
     */
    public function drift(array $baseline, array $live): PromiseInterface
    {
        $from = (string) ($baseline['source']['sha'] ?? '');
        $to = (string) ($live['source']['sha'] ?? '');
        $compare = ($from !== '' && $to !== '' && $from !== $to)
            ? $this->github->compare($this->sourceRepo, $from, $to)
            : resolve(null);

        return $compare->then(static fn(?array $c): array => Drift::compare($baseline, $live, $c));
    }

    /**
     * Makes the live server the baseline for the given sources.
     *
     * @param array{views: array<string,mixed>, openapi: mixed} $live    {@see live()}
     * @param list<string>                                      $sources Which ones; {@see Snapshot::SOURCES} for all.
     *
     * @return list<string> The files written.
     */
    public function accept(array $live, array $sources): array
    {
        if (! is_dir($this->baselineDir)) {
            mkdir($this->baselineDir, 0o777, true);
        }
        $written = [];
        foreach ($sources as $s) {
            if (! in_array($s, Snapshot::SOURCES, true)) {
                throw new \InvalidArgumentException("Unknown source `{$s}`; expected one of " . implode(', ', Snapshot::SOURCES) . '.');
            }
            // The contract is kept as served (the schema-drift test reads it
            // whole); the others as their views.
            $data = $s === 'openapi' ? $live['openapi'] : $live['views'][$s];
            file_put_contents($this->path($s), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            $written[] = $this->path($s);
        }

        return $written;
    }

    /**
     * Brings the one open drift issue on `$repo` in line with `$drift`:
     * opens, rewrites, or closes it ({@see Report::action()}).
     *
     * @param array<string,list<string>> $drift  {@see drift()}
     * @param array<string,mixed>        $live   `views` of {@see live()}
     * @param bool                       $dryRun Decide, but write nothing.
     *
     * @return PromiseInterface<string> What was done (or would be), for the log.
     */
    public function file(string $repo, array $drift, array $live, bool $dryRun = false): PromiseInterface
    {
        $prints = [];
        foreach (array_keys($drift) as $s) {
            $prints[$s] = Snapshot::fingerprint($live[$s] ?? null);
        }

        return $this->github->openIssue($repo, self::LABEL)->then(function (?array $open) use ($repo, $drift, $prints, $dryRun): PromiseInterface|string {
            $act = Report::action($open, $drift, $prints);
            $number = (int) ($open['number'] ?? 0);
            $said = match ($act['action']) {
                'none' => $open === null ? 'nothing moved; no issue open' : "#{$number} already reports this drift",
                'create' => 'open a new issue',
                'update' => "rewrite #{$number}" . ($act['moved'] === [] ? '' : ' and comment that ' . implode(', ', $act['moved']) . ' moved'),
                'close' => "close #{$number}: the baseline matches the server again",
            };
            if ($dryRun || $act['action'] === 'none') {
                return ($dryRun ? 'dry run, would ' : '') . $said;
            }

            $title = Report::title($drift);
            $body = Report::body($drift, $prints, gmdate('Y-m-d H:i') . ' UTC');

            return match ($act['action']) {
                'create' => $this->github->ensureLabel($repo, self::LABEL, 'fbca04', 'The live NHA server moved away from the baseline this code was written against')
                    ->then(fn() => $this->github->createIssue($repo, $title, $body, [self::LABEL]))
                    ->then(static fn(array $issue): string => "opened #{$issue['number']} {$issue['html_url']}"),
                'update' => $this->github->updateIssue($repo, $number, ['title' => $title, 'body' => $body])
                    ->then(fn() => $act['moved'] === [] ? null : $this->github->comment($repo, $number, Report::movedComment($act['moved'])))
                    ->then(static fn(): string => $said),
                'close' => $this->github->comment($repo, $number, 'The live server matches the committed baseline again. Closing.')
                    ->then(fn() => $this->github->updateIssue($repo, $number, ['state' => 'closed', 'state_reason' => 'completed']))
                    ->then(static fn(): string => $said),
            };
        });
    }

    private function path(string $source): string
    {
        return $source === 'openapi' ? $this->openapiPath : $this->baselineDir . DIRECTORY_SEPARATOR . "{$source}.json";
    }

    /**
     * A decoded response (objects from the HTTP client) as nested arrays.
     *
     * @return array<string,mixed>
     */
    private static function arr(mixed $v): array
    {
        return (array) json_decode((string) json_encode($v), true);
    }
}
