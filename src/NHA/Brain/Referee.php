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

namespace NHA\Brain;

/**
 * What the Inventors' Guild referee has already told us, mined from the
 * agent's own profile milestones.
 *
 * Every filing costs 50 credits, refunded only if granted, and four in five
 * filings world-wide are rejected. The referee does not just say no — it says
 * WHY, in prose, and that verdict is kept on the profile forever. The brain was
 * paying for those lessons and then throwing them away.
 *
 * Read back over agent #142285's 24 recorded rejections, two patterns account
 * for nearly all of them, and both are free to avoid:
 *
 *  1. **A crafted item has no tags, so it can never be an ingredient.** The
 *     referee is explicit — *"'composite' has a 'shaped' tag, but
 *     'healing_salve' does not have any tags"*. After inventing
 *     `medicinal_potion` the agent tried it against composite, carbon,
 *     c_regolith and brine; all four were refused. It then invented
 *     `healing_salve` and tried **the same four partners**, and was refused the
 *     same four times. Eight filings, 400 credits, to learn one rule twice.
 *
 *  2. **An ingredient that keeps failing is exhausted.** `herb` was paired with
 *     wire, silicon, ore, nickel, metal, glass, composite, carbon and
 *     c_regolith — nine filings, nine refusals, no invention. The referee's
 *     reason barely changes: organic and medicinal properties do not combine
 *     with metallic or mineral ones.
 *
 * So this class answers one question — *is this pair worth 50 credits?* — from
 * evidence the world already gave us, rather than from a tag ontology we would
 * have to guess at and keep in sync.
 *
 * @since 3.13.0
 */
final class Referee
{
    /**
     * Rejections of one ingredient, with no invention ever from it, before it
     * is considered exhausted.
     *
     * Three is deliberately forgiving: two refusals can be bad luck in the
     * partner, and the goal is to stop a nine-deep sweep, not to abandon a line
     * after its first no.
     */
    public const EXHAUSTED_AFTER = 3;

    /**
     * Everything the referee has taught this agent, in one pass over the
     * profile.
     *
     * @param array<string,mixed> $profile    a decoded `GET /agent/{id}` body
     * @param array<string,bool>  $worldKnown the codex of known combine signatures
     *                                        (`/rules` dynamic) — the only place an
     *                                        INGREDIENT of a granted recipe is named,
     *                                        since an `invent` milestone records the
     *                                        output and never its inputs
     *
     * @return array{tagless:list<string>,rejected:array<string,string>,exhausted:list<string>,invented:list<string>,productive:list<string>}
     */
    public static function lore(array $profile, array $worldKnown = []): array
    {
        $milestones = (array) ($profile['milestones'] ?? []);

        $invented = [];
        $rejected = [];
        $rejectCount = [];
        $tagless = [];

        foreach ($milestones as $m) {
            $m = (array) $m;
            $data = (array) ($m['data'] ?? []);
            $kind = (string) ($m['kind'] ?? '');

            if ($kind === 'invent') {
                if (($item = (string) ($data['item'] ?? '')) !== '') {
                    $invented[$item] = true;
                    $tagless[$item] = true;
                }

                continue;
            }
            if ($kind !== 'reject') {
                continue;
            }

            $sig = self::signature((string) ($data['sig'] ?? ''));
            $reason = (string) ($data['reason'] ?? '');
            if ($sig !== '') {
                $rejected[$sig] = $reason;
                foreach (explode('+', $sig) as $ing) {
                    $rejectCount[$ing] = ($rejectCount[$ing] ?? 0) + 1;
                }
            }
            foreach (self::taglessNamedIn($reason) as $item) {
                $tagless[$item] = true;
            }
        }

        // Anything in the discovery log is a crafted output too, whether or not
        // a milestone for it is still in the window — the profile keeps only
        // the most recent 40, and "this is a crafted item" never expires.
        foreach ((array) ($profile['discoveries'] ?? []) as $d) {
            if (($name = (string) (((array) $d)['name'] ?? '')) !== '') {
                $tagless[self::slug($name)] = true;
            }
        }

        // An ingredient that has EVER produced something is never exhausted,
        // however often it has also failed. `ore` is the cautionary case: it
        // was refused against wire, water, salt, metal, ice and glass — six
        // times, well past any threshold — while also being the ingredient in
        // BOTH of this agent's granted recipes (nickel_ore_ingot,
        // silicon_ore_alloy). Reading the `invent` milestones alone misses
        // that completely, because they record the OUTPUT and never the
        // inputs. Writing off the most productive ingredient we have is a far
        // worse error than a few wasted filings, so this leans toward keeping.
        $productive = [];
        foreach (array_keys($worldKnown) as $sig) {
            foreach (explode('+', (string) $sig) as $ing) {
                if ($ing !== '') {
                    $productive[$ing] = true;
                }
            }
        }
        foreach (array_keys($invented) as $item) {
            // Fallback when the codex is not to hand: a granted recipe is
            // almost always named after what went into it.
            foreach (explode('_', (string) $item) as $part) {
                if (strlen($part) > 2) {
                    $productive[$part] = true;
                }
            }
        }

        $exhausted = [];
        foreach ($rejectCount as $ing => $n) {
            if ($n >= self::EXHAUSTED_AFTER && ! isset($invented[$ing]) && ! isset($productive[$ing])) {
                $exhausted[] = (string) $ing;
            }
        }

        return [
            'tagless' => array_values(array_keys($tagless)),
            'rejected' => $rejected,
            'exhausted' => $exhausted,
            'invented' => array_values(array_keys($invented)),
            'productive' => array_values(array_keys($productive)),
        ];
    }

    /**
     * Whether a pair is worth filing, given what the referee has already said.
     *
     * @param list<string>                                                                             $ingredients
     * @param array{tagless:list<string>,rejected:array<string,string>,exhausted:list<string>}|array{} $lore
     */
    public static function worthFiling(array $ingredients, array $lore): bool
    {
        if ($lore === [] || $ingredients === []) {
            return true;    // nothing learned yet — the referee is the teacher
        }
        foreach ($ingredients as $ing) {
            if (in_array((string) $ing, (array) ($lore['tagless'] ?? []), true)) {
                return false;
            }
            if (in_array((string) $ing, (array) ($lore['exhausted'] ?? []), true)) {
                return false;
            }
        }

        return ! isset(((array) ($lore['rejected'] ?? []))[self::signature(implode(',', $ingredients))]);
    }

    /**
     * Why a pair was skipped, for the decision reason line — so a human reading
     * the log sees the referee's own words rather than a silent omission.
     *
     * @param list<string>        $ingredients
     * @param array<string,mixed> $lore
     */
    public static function refusalNote(array $ingredients, array $lore): ?string
    {
        // Tagless first, across ALL ingredients, before falling back to
        // exhaustion: "it is a crafted item" is the precise reason and the one
        // that generalises, where "this ingredient keeps failing" is a
        // statistical shrug. A pair can be both.
        foreach ($ingredients as $ing) {
            if (in_array((string) $ing, (array) ($lore['tagless'] ?? []), true)) {
                return "{$ing} is a crafted item and carries no tags — the Guild refuses those on sight";
            }
        }
        foreach ($ingredients as $ing) {
            if (in_array((string) $ing, (array) ($lore['exhausted'] ?? []), true)) {
                return "{$ing} has been refused " . self::EXHAUSTED_AFTER . '+ times and never granted';
            }
        }
        $sig = self::signature(implode(',', $ingredients));

        return isset($lore['rejected'][$sig]) ? "the Guild already refused {$sig}" : null;
    }

    /**
     * The referee names the tagless item in its reason, in a handful of
     * phrasings. Matching on those is what lets the rule cover an item invented
     * by somebody else, or one whose `invent` milestone has aged out.
     *
     * @return list<string>
     */
    private static function taglessNamedIn(string $reason): array
    {
        $out = [];
        // At most three words before the verb, so a clause like "…, while
        // healing salve has no properties" yields `healing_salve` and not
        // `while_healing_salve`. Leading conjunctions are stripped below.
        $patterns = [
            "/'([a-z0-9_]+)'\\s+does not have any tags/i",
            "/((?:[a-z0-9_]+ ){0,2}[a-z0-9_]+)\\s+has no (?:defined )?(?:tags|properties)/i",
            "/((?:[a-z0-9_]+ ){0,2}[a-z0-9_]+)\\s+does not have any (?:tags|properties)/i",
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $reason, $m)) {
                foreach ($m[1] as $name) {
                    if (($slug = self::stripLeadingStopwords(self::slug((string) $name))) !== '') {
                        $out[] = $slug;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Drops the connective words a reason clause can begin with, so a phrase
     * captured mid-sentence resolves to the item rather than to the grammar
     * around it.
     */
    private static function stripLeadingStopwords(string $slug): string
    {
        $stop = ['while', 'but', 'and', 'the', 'a', 'an', 'that', 'whereas', 'however', 'though'];
        $parts = array_values(array_filter(explode('_', $slug), 'strlen'));
        while ($parts !== [] && in_array($parts[0], $stop, true)) {
            array_shift($parts);
        }

        return implode('_', $parts);
    }

    /** `"Healing Salve"` / `"healing salve"` → `healing_salve`. */
    private static function slug(string $name): string
    {
        $s = strtolower(trim($name));
        $s = (string) preg_replace('/[^a-z0-9]+/', '_', $s);

        return trim($s, '_');
    }

    /** The sorted `"a+b"` signature the rest of the brain uses. */
    private static function signature(string $csv): string
    {
        $tokens = array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)), 'strlen')));
        sort($tokens);

        return implode('+', $tokens);
    }
}
