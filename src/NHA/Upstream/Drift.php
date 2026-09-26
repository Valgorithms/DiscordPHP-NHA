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

/**
 * What moved between the baseline and the live server, one Markdown line per
 * change, grouped by source ({@see Snapshot::SOURCES}).
 *
 * The lines are written for whoever updates the code, often a model, so each
 * says what the rule is now, not only that it changed: an operator update is
 * quoted, a reworded description shows its old and new lines, and a changed
 * bill shows the old and new amounts.
 *
 * @since 3.23.0
 */
final class Drift
{
    /** The most commits, files or diff lines listed for one change before "…and N more". */
    public const LIST_MAX = 40;

    /** The longest operator update detail quoted in full. */
    public const DETAIL_MAX = 2000;

    /**
     * Every source that moved, baseline → live. A source missing from either
     * side is not compared.
     *
     * @param array<string,mixed>      $baseline Views by source, from the committed baseline.
     * @param array<string,mixed>      $live     Views by source, from the server now.
     * @param array<string,mixed>|null $compare  GitHub's compare of the two engine commits, when fetched.
     *
     * @return array<string,list<string>> Change lines by source, in report order; only sources that moved.
     */
    public static function compare(array $baseline, array $live, ?array $compare = null): array
    {
        $out = [];
        foreach (Snapshot::SOURCES as $s) {
            if (! isset($baseline[$s], $live[$s])) {
                continue;
            }
            $lines = match ($s) {
                'updates' => self::updates($baseline[$s], $live[$s]),
                'openapi' => self::openapi($baseline[$s], $live[$s]),
                'rules' => self::rules($baseline[$s], $live[$s]),
                'colonies' => self::colonies($baseline[$s], $live[$s]),
                'source' => self::source($baseline[$s], $live[$s], $compare),
            };
            if ($lines !== []) {
                $out[$s] = $lines;
            }
        }

        return $out;
    }

    /**
     * New, revised and withdrawn operator updates, each quoted in full.
     *
     * @param array<string,array<string,mixed>> $old
     * @param array<string,array<string,mixed>> $new
     *
     * @return list<string>
     */
    public static function updates(array $old, array $new): array
    {
        $lines = [];
        foreach ($new as $id => $u) {
            if (! isset($old[$id])) {
                $lines[] = self::update('New', (string) $id, $u);
            } elseif (Snapshot::canonical($old[$id]) !== Snapshot::canonical($u)) {
                $lines[] = self::update('Revised', (string) $id, $u);
            }
        }
        foreach (array_diff_key($old, $new) as $id => $u) {
            $lines[] = "Withdrawn: **#{$id}** " . ($u['title'] ?? '');
        }

        return $lines;
    }

    /**
     * Endpoints and schemas added, removed or changed.
     *
     * @param array<string,mixed> $old {@see Snapshot::openapi()}
     * @param array<string,mixed> $new {@see Snapshot::openapi()}
     *
     * @return list<string>
     */
    public static function openapi(array $old, array $new): array
    {
        $lines = [];
        if (($old['version'] ?? '') !== ($new['version'] ?? '')) {
            $lines[] = "API version `{$old['version']}` → **`{$new['version']}`**. The world API's major is this library's major (README, Versioning).";
        }

        $was = (array) ($old['operations'] ?? []);
        foreach ((array) ($new['operations'] ?? []) as $op => $def) {
            if (! isset($was[$op])) {
                $lines[] = "New endpoint `{$op}`" . self::gist((string) $def['doc']);
                continue;
            }
            $parts = [];
            if (($d = self::keys((array) $was[$op]['params'], (array) $def['params'])) !== '') {
                $parts[] = "parameters {$d}";
            }
            foreach (['body' => 'request body', 'returns' => 'response schema'] as $k => $label) {
                if (Snapshot::canonical($was[$op][$k] ?? null) !== Snapshot::canonical($def[$k] ?? null)) {
                    $parts[] = "{$label} " . self::json($was[$op][$k] ?? null) . ' → ' . self::json($def[$k] ?? null);
                }
            }
            if ($parts !== []) {
                $lines[] = "`{$op}`: " . implode('; ', $parts);
            }
            if ($was[$op]['doc'] !== $def['doc']) {
                $lines[] = "`{$op}` documentation reworded:\n" . self::text((string) $was[$op]['doc'], (string) $def['doc']);
            }
        }
        foreach (array_diff_key($was, (array) ($new['operations'] ?? [])) as $op => $_) {
            $lines[] = "Endpoint removed: `{$op}`";
        }

        $was = (array) ($old['schemas'] ?? []);
        foreach ((array) ($new['schemas'] ?? []) as $name => $s) {
            if (! isset($was[$name])) {
                $lines[] = "New schema `{$name}`: " . implode(', ', array_map(static fn($p): string => "`{$p}`", array_keys((array) $s['properties'])));
                continue;
            }
            $parts = [];
            if (($d = self::keys((array) $was[$name]['properties'], (array) $s['properties'])) !== '') {
                $parts[] = "properties {$d}";
            }
            if ($was[$name]['required'] !== $s['required']) {
                $parts[] = 'required ' . self::json($was[$name]['required']) . ' → ' . self::json($s['required']);
            }
            if ($parts !== []) {
                $lines[] = "Schema `{$name}`: " . implode('; ', $parts);
            }
            if ($was[$name]['doc'] !== $s['doc']) {
                $lines[] = "Schema `{$name}` documentation reworded:\n" . self::text((string) $was[$name]['doc'], (string) $s['doc']);
            }
        }
        foreach (array_diff_key($was, (array) ($new['schemas'] ?? [])) as $name => $_) {
            $lines[] = "Schema removed: `{$name}`";
        }

        return $lines;
    }

    /**
     * Resources, recipes and the codex note.
     *
     * @param array<string,mixed> $old {@see Snapshot::rules()}
     * @param array<string,mixed> $new {@see Snapshot::rules()}
     *
     * @return list<string>
     */
    public static function rules(array $old, array $new): array
    {
        $lines = [];
        if (($old['note'] ?? '') !== ($new['note'] ?? '')) {
            $lines[] = "Codex note reworded:\n" . self::text((string) ($old['note'] ?? ''), (string) ($new['note'] ?? ''));
        }
        foreach (self::entries((array) ($old['resources'] ?? []), (array) ($new['resources'] ?? [])) as [$name, $a, $b]) {
            $lines[] = match (true) {
                $a === null => "New resource `{$name}`: " . self::pairs((array) $b),
                $b === null => "Resource removed: `{$name}`",
                default => "Resource `{$name}` tags: " . self::map((array) $a, (array) $b),
            };
        }
        foreach (self::entries((array) ($old['recipes'] ?? []), (array) ($new['recipes'] ?? [])) as [$item, $a, $b]) {
            if ($a === null) {
                $lines[] = "New recipe `{$item}`: needs " . self::json($b['needs']) . '; gives ' . self::pairs((array) $b['props']);
            } elseif ($b === null) {
                $lines[] = "Recipe removed: `{$item}`";
            } else {
                $parts = [];
                if (Snapshot::canonical($a['needs']) !== Snapshot::canonical($b['needs'])) {
                    $parts[] = 'needs ' . self::json($a['needs']) . ' → ' . self::json($b['needs']);
                }
                if (Snapshot::canonical($a['props']) !== Snapshot::canonical($b['props'])) {
                    $parts[] = 'props ' . self::map((array) $a['props'], (array) $b['props']);
                }
                $lines[] = "Recipe `{$item}`: " . implode('; ', $parts);
            }
        }

        return $lines;
    }

    /**
     * The era, and each body's colony modules and terraform stages.
     *
     * @param array<string,mixed> $old {@see Snapshot::colonies()}
     * @param array<string,mixed> $new {@see Snapshot::colonies()}
     *
     * @return list<string>
     */
    public static function colonies(array $old, array $new): array
    {
        $lines = [];
        if (($old['era'] ?? '') !== ($new['era'] ?? '')) {
            $lines[] = "Era `{$old['era']}` → **`{$new['era']}`**";
        }
        foreach (self::entries((array) ($old['bodies'] ?? []), (array) ($new['bodies'] ?? [])) as [$body, $a, $b]) {
            if ($a === null) {
                $lines[] = "New body `{$body}`: " . self::json($b);
                continue;
            }
            if ($b === null) {
                $lines[] = "Body removed: `{$body}`";
                continue;
            }
            foreach (['label', 'cap_pct_per_agent', 'min_funders_per_module'] as $k) {
                if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                    $lines[] = "`{$body}` {$k}: " . self::json($a[$k] ?? null) . ' → ' . self::json($b[$k] ?? null);
                }
            }
            foreach (['modules' => 'module', 'terraform' => 'terraform stage'] as $k => $noun) {
                foreach (self::entries((array) ($a[$k] ?? []), (array) ($b[$k] ?? [])) as [$name, $x, $y]) {
                    $lines[] = match (true) {
                        $x === null => "`{$body}` new {$noun} `{$name}`: " . self::json($y),
                        $y === null => "`{$body}` {$noun} removed: `{$name}`",
                        default => "`{$body}` {$noun} `{$name}`: " . self::map((array) $x, (array) $y),
                    };
                }
            }
        }

        return $lines;
    }

    /**
     * New commits on the public engine source: which ones, which files, and
     * which upper-case constants their patches touch. `GameData` is
     * transcribed from those constants.
     *
     * @param array<string,mixed>      $old     {@see Snapshot::source()}
     * @param array<string,mixed>      $new     {@see Snapshot::source()}
     * @param array<string,mixed>|null $compare GitHub's `GET /repos/{repo}/compare/{old}...{new}`, if it could be fetched.
     *
     * @return list<string>
     */
    public static function source(array $old, array $new, ?array $compare = null): array
    {
        $from = (string) ($old['sha'] ?? '');
        $to = (string) ($new['sha'] ?? '');
        if ($from === $to) {
            return [];
        }
        $repo = (string) ($new['repo'] ?? '');
        $lines = ["`{$repo}` moved `" . substr($from, 0, 7) . '` → `' . substr($to, 0, 7) . "` ({$new['date']}): https://github.com/{$repo}/compare/{$from}...{$to}"];
        if ($compare === null) {
            $lines[] = 'The two commits could not be compared (a rewritten history?); read the new commits on GitHub.';

            return $lines;
        }

        $commits = array_reverse((array) ($compare['commits'] ?? []));
        foreach (array_slice($commits, 0, self::LIST_MAX) as $c) {
            $lines[] = 'Commit `' . substr((string) ($c['sha'] ?? ''), 0, 7) . '` ' . (strtok((string) ($c['commit']['message'] ?? ''), "\n") ?: '');
        }
        if (count($commits) > self::LIST_MAX) {
            $lines[] = '…and ' . (count($commits) - self::LIST_MAX) . ' more commits';
        }

        $files = (array) ($compare['files'] ?? []);
        $touched = [];
        foreach ($files as $f) {
            $consts = self::constants((string) ($f['patch'] ?? ''));
            $touched[] = "`{$f['filename']}` (+" . (int) ($f['additions'] ?? 0) . ' −' . (int) ($f['deletions'] ?? 0) . ')'
                . ($consts === [] ? '' : ', constants ' . implode(', ', array_map(static fn($c): string => "`{$c}`", $consts)));
        }
        foreach (array_slice($touched, 0, self::LIST_MAX) as $t) {
            $lines[] = "File {$t}";
        }
        if (count($touched) > self::LIST_MAX) {
            $lines[] = '…and ' . (count($touched) - self::LIST_MAX) . ' more files';
        }

        return $lines;
    }

    /**
     * Upper-case names assigned on the added or removed lines of a unified
     * diff: `DV_CEILING = 300`, `PART: dict = {`, `"BODY_MINE": …` is not one.
     *
     * @return list<string>
     */
    public static function constants(string $patch): array
    {
        preg_match_all('/^[+-](?![+-])\s*([A-Z][A-Z0-9_]{2,})\s*(?::[^=\n]*)?=(?!=)/m', $patch, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /**
     * Two texts' lines, as a `diff` block: what was removed, what was added.
     * Unchanged lines are left out, so a reworded paragraph shows only its
     * old and new lines.
     */
    public static function text(string $old, string $new): string
    {
        $a = array_values(array_filter(array_map('rtrim', explode("\n", $old)), 'strlen'));
        $b = array_values(array_filter(array_map('rtrim', explode("\n", $new)), 'strlen'));
        $diff = array_merge(
            array_map(static fn($l): string => "- {$l}", array_values(array_diff($a, $b))),
            array_map(static fn($l): string => "+ {$l}", array_values(array_diff($b, $a))),
        );
        if (count($diff) > self::LIST_MAX) {
            $diff = array_merge(array_slice($diff, 0, self::LIST_MAX), ['… ' . (count($diff) - self::LIST_MAX) . ' more lines']);
        }

        return "  ```diff\n  " . implode("\n  ", $diff) . "\n  ```";
    }

    /**
     * One operator update, its detail quoted.
     *
     * @param array<string,mixed> $u
     */
    private static function update(string $what, string $id, array $u): string
    {
        $verb = ($u['verb'] ?? '') !== '' ? " · `{$u['verb']}`" : '';
        $line = "{$what}: **#{$id}** (tick " . number_format((int) ($u['tick'] ?? 0)) . "{$verb}) **{$u['title']}**";
        $detail = trim((string) ($u['detail'] ?? ''));
        if ($detail === '') {
            return $line;
        }
        if (mb_strlen($detail) > self::DETAIL_MAX) {
            $detail = mb_substr($detail, 0, self::DETAIL_MAX) . '…';
        }

        return $line . "\n  > " . str_replace("\n", "\n  > ", $detail);
    }

    /**
     * Every key of either map, with its old and new value (null when absent),
     * where they differ.
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     *
     * @return list<array{0: string, 1: mixed, 2: mixed}>
     */
    private static function entries(array $old, array $new): array
    {
        $out = [];
        foreach (array_keys($old + $new) as $k) {
            $a = $old[$k] ?? null;
            $b = $new[$k] ?? null;
            if (Snapshot::canonical($a) !== Snapshot::canonical($b)) {
                $out[] = [(string) $k, $a, $b];
            }
        }

        return $out;
    }

    /**
     * A map's changes, key by key: `titanium 200 → 150, +ice 40, −salt`.
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     */
    private static function map(array $old, array $new): string
    {
        return implode(', ', array_map(static fn(array $e): string => match (true) {
            $e[1] === null => "+{$e[0]} " . self::json($e[2]),
            $e[2] === null => "−{$e[0]}",
            default => "{$e[0]} " . self::json($e[1]) . ' → ' . self::json($e[2]),
        }, self::entries($old, $new)));
    }

    /**
     * Keys added, removed and changed between two maps, by name only:
     * `+vault, −seal, ~tick`.
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     */
    private static function keys(array $old, array $new): string
    {
        return implode(', ', array_map(static fn(array $e): string => match (true) {
            $e[1] === null => "+`{$e[0]}`",
            $e[2] === null => "−`{$e[0]}`",
            default => "~`{$e[0]}`",
        }, self::entries($old, $new)));
    }

    /**
     * @param array<string,mixed> $map
     */
    private static function pairs(array $map): string
    {
        return implode(', ', array_map(static fn($k, $v): string => "{$k} " . self::json($v), array_keys($map), $map));
    }

    /**
     * An endpoint's documentation in one line, after " — ": the first line of
     * its description, or its summary when it has none.
     */
    private static function gist(string $doc): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $doc)), 'strlen'));
        $gist = $lines[1] ?? $lines[0] ?? '';

        return $gist === '' ? '' : " — {$gist}";
    }

    private static function json(mixed $v): string
    {
        return is_string($v) ? "\"{$v}\"" : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
