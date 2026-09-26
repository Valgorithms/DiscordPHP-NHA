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
 * The GitHub issue a drift becomes: its title, its body, and whether the one
 * open drift issue should be opened, rewritten, or closed.
 *
 * The body is written as a brief for whoever updates the code: what moved,
 * where in this repository each kind of change lands, and how to sign off.
 * A hidden marker carries one fingerprint per moved source
 * ({@see Snapshot::fingerprint()}), so a check that finds the same drift
 * again leaves the issue alone, and one that finds it moved further says
 * what moved.
 *
 * @since 3.23.0
 */
final class Report
{
    /** The HTML comment that carries the fingerprints. */
    public const MARKER = 'nha-upstream-watch';

    /** Lines shown per source before the rest is left to `composer upstream:check`. */
    public const SECTION_MAX = 60;

    /** GitHub refuses an issue body over 65,536 characters; stay clear of it. */
    public const BODY_MAX = 60000;

    /** Each source's name in a title or heading. */
    public const NAMES = [
        'updates' => 'Operator rule updates',
        'openapi' => 'API contract',
        'rules' => 'Crafting codex',
        'colonies' => 'Colony and terraform bills',
        'source' => 'Engine source',
    ];

    /** Where each kind of change lands in this repository. */
    public const WHERE = [
        'updates' => 'Announced by the operator. Read each one: rule changes belong in `src/NHA/Brain/GameData.php`, the playbook (`src/NHA/Brain/Playbook.php`, `docs/PLAYBOOK.md`) and `.agents/skills/nha-agent/SKILL.md`.',
        'openapi' => 'The routes and payloads the client is written against: `src/NHA/Http/Endpoint.php`, the matching `src/NHA/Parts/*` and `src/NHA/Repository/*`, and `src/NHA/VerbsTrait.php` (AGENTS.md, "Playbook: editing an NHA endpoint").',
        'rules' => 'Recipes and resource tags the brain crafts by: `src/NHA/Brain/GameData.php` and `src/NHA/Brain/Ladder.php`.',
        'colonies' => 'What colony modules and terraform stages cost, and who may fund them: the colony logic in `src/NHA/Brain/`.',
        'source' => '`src/NHA/Brain/GameData.php` is transcribed from this source: re-check every constant the new commits touch.',
    ];

    /**
     * @param array<string,list<string>> $drift {@see Drift::compare()}
     */
    public static function title(array $drift): string
    {
        return 'NHA server changed: ' . implode(', ', array_map(static fn(string $s): string => self::NAMES[$s] ?? $s, array_keys($drift)));
    }

    /**
     * The issue body: a section per moved source, how to sign off, and the
     * fingerprint marker.
     *
     * @param array<string,list<string>> $drift  {@see Drift::compare()}
     * @param array<string,string>       $prints Fingerprint of each moved source's live view.
     * @param string                     $when   When the check ran, as shown.
     */
    public static function body(array $drift, array $prints, string $when): string
    {
        $out = "The live NHA server no longer matches the baseline this code was written against (`upstream/*.json`, `openapi.json`). Found by `upstream-watch.php` at {$when}.\n";
        foreach ($drift as $source => $lines) {
            $out .= "\n## " . (self::NAMES[$source] ?? $source) . "\n\n_" . (self::WHERE[$source] ?? '') . "_\n\n";
            foreach (array_slice($lines, 0, self::SECTION_MAX) as $line) {
                $out .= "- {$line}\n";
            }
            if (count($lines) > self::SECTION_MAX) {
                $out .= '- …and ' . (count($lines) - self::SECTION_MAX) . " more. Run `composer upstream:check` for the full list.\n";
            }
        }
        $out .= "\n## When the code has caught up\n\n"
            . "1. `composer upstream:accept` takes the live server as the new baseline. To sign off only part of it: `php upstream-watch.php --accept=rules,source`.\n"
            . "2. Commit the refreshed baseline together with the code change.\n"
            . "3. The next scheduled check finds nothing moved and closes this issue.\n";

        $marker = "\n<!-- " . self::MARKER . ' ' . json_encode($prints, JSON_UNESCAPED_SLASHES) . " -->\n";
        if (strlen($out) + strlen($marker) > self::BODY_MAX) {
            $out = mb_strcut($out, 0, self::BODY_MAX - strlen($marker) - 200) . "\n\n…cut to fit GitHub's limit. Run `composer upstream:check` for the full report.\n";
        }

        return $out . $marker;
    }

    /**
     * The fingerprints an issue body carries, or none.
     *
     * @return array<string,string>
     */
    public static function prints(string $body): array
    {
        if (! preg_match('/<!-- ' . preg_quote(self::MARKER, '/') . ' (\{.*?\}) -->/', $body, $m)) {
            return [];
        }

        return array_map('strval', (array) json_decode($m[1], true));
    }

    /**
     * What to do with the one open drift issue.
     *
     * - nothing moved: close it, if there is one;
     * - moved, and no issue: open one;
     * - the same drift it already reports: leave it;
     * - anything else: rewrite it, and name the sources that moved since
     *   (`moved`), so the rewrite also notifies. A drift that only shrank,
     *   because part of it was accepted, rewrites quietly.
     *
     * @param array<string,mixed>|null   $open   The open issue (GitHub's issue object), if any.
     * @param array<string,list<string>> $drift  {@see Drift::compare()}
     * @param array<string,string>       $prints Fingerprint of each moved source's live view.
     *
     * @return array{action: 'none'|'create'|'update'|'close', moved: list<string>}
     */
    public static function action(?array $open, array $drift, array $prints): array
    {
        if ($drift === []) {
            return ['action' => $open === null ? 'none' : 'close', 'moved' => []];
        }
        if ($open === null) {
            return ['action' => 'create', 'moved' => array_keys($prints)];
        }
        $was = self::prints((string) ($open['body'] ?? ''));
        $moved = array_keys(array_filter($prints, static fn(string $p, string $s): bool => ($was[$s] ?? null) !== $p, ARRAY_FILTER_USE_BOTH));
        if ($moved === [] && count($was) === count($prints)) {
            return ['action' => 'none', 'moved' => []];
        }

        return ['action' => 'update', 'moved' => $moved];
    }

    /**
     * The comment that tells watchers the drift moved again.
     *
     * @param list<string> $moved
     */
    public static function movedComment(array $moved): string
    {
        return 'The server moved again: ' . implode(', ', array_map(static fn(string $s): string => self::NAMES[$s] ?? $s, $moved)) . '. The description above is the current state.';
    }
}
