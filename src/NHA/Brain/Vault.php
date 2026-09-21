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
 * Season 8's alien vault on Titan — the first mechanic in this world whose
 * intended solution is a conversation.
 *
 * The seal is six symbols in order, from an alphabet of twelve. Six obelisks
 * stand across the system (Earth, Mars, Venus, Phobos, Enceladus, Triton, in
 * that order of position) and standing at one reveals exactly ONE symbol, to
 * that agent alone, recorded permanently with its position.
 *
 * The design deliberately closes every solitary route:
 *
 *  - Brute force is hopeless: 12⁶ is nearly three million, a wrong code freezes
 *    the stone for 90 ticks, and a refusal reveals nothing about how close it
 *    was. {@see unlockStep()} therefore NEVER guesses — it fires only on a
 *    complete code, and a partial one is a chat message instead.
 *  - Walking all six alone is six journeys across four gravity wells, behind
 *    launch windows.
 *  - Six agents who talk open it in an afternoon.
 *
 * So this class does three things in order of cost: read any obelisk within
 * reach for free, say what it read and ask for what it lacks, and speak the
 * seal the moment the six are in hand.
 *
 * Reading a fragment takes NO verb — the stone lights up on arrival. The only
 * move is `move` to its cell (the Earth obelisk publishes x/y; the others are
 * anywhere on their body, so landing is the arrival). Speaking it is
 * `unlock{code:[…]}` while standing on Titan.
 *
 * @link https://nha.recluse.lol/AGENTS.md §8, and `GET /vault`
 *
 * @since 3.12.0
 */
final class Vault
{
    /** How close the engine counts as "standing at" an obelisk. */
    public const OBELISK_RANGE = 0;

    /** World chat's hard limit — over it the `say` is refused, not truncated. */
    public const SAY_MAX = 280;

    /** Symbols the agent has read, position (1-based) → symbol. @return array<int,string> */
    public static function fragments(array $raw): array
    {
        $own = ((array) self::block($raw))['your_fragments'] ?? [];
        if (! is_array($own)) {
            // Unauthenticated observes put a "hidden: send your token" STRING
            // here. That is not "no fragments" — it is "you did not ask
            // properly", and treating the two alike would have the agent
            // re-walk obelisks it has already read.
            return [];
        }
        $out = [];
        foreach ($own as $pos => $sym) {
            if (is_numeric($pos) && is_string($sym) && $sym !== '') {
                $out[(int) $pos] = $sym;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Whether the observation could even see our fragments. `false` means the
     * call went out without a token, so every count below is unproven.
     */
    public static function authenticated(array $raw): bool
    {
        $v = self::block($raw);

        return $v !== [] && ! is_string($v['your_fragments'] ?? null);
    }

    /** Seal positions still missing, ascending. @return list<int> */
    public static function missing(array $raw): array
    {
        $v = self::block($raw);
        $total = (int) ($v['symbols_in_seal'] ?? 6);
        $have = self::fragments($raw);
        $out = [];
        for ($i = 1; $i <= $total; ++$i) {
            if (! isset($have[$i])) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /** Whether the seal is already open — nothing below is worth a turn then. */
    public static function open(array $raw): bool
    {
        return (bool) (self::block($raw)['open'] ?? false);
    }

    /** Ticks left on a failed-unlock freeze. */
    public static function cooldown(array $raw): int
    {
        return (int) (self::block($raw)['cooldown_ticks_left'] ?? 0);
    }

    /**
     * The obelisk worth walking to RIGHT NOW, or `null`.
     *
     * Only ever an obelisk on the ground the agent is already standing on —
     * this never books a flight. Travel between bodies is the expansion
     * ladder's job and is driven by colony work; a fragment picked up while
     * there is a free rider on a trip already worth making.
     *
     * @return array{position:int,x:int,y:int,place:string}|null
     */
    public static function obeliskHere(array $raw): ?array
    {
        if (self::open($raw)) {
            return null;
        }
        $missing = self::missing($raw);
        if ($missing === []) {
            return null;
        }
        $here = Ladder::atBody($raw) ?? 'earth';
        // In space or mid-transit there is no ground to stand on.
        if (($raw['in_space'] ?? false) && Ladder::atBody($raw) === null) {
            return null;
        }

        foreach ((array) (self::block($raw)['obelisks'] ?? []) as $o) {
            $o = (array) $o;
            $pos = (int) ($o['position'] ?? 0);
            if (! in_array($pos, $missing, true) || (string) ($o['place'] ?? '') !== $here) {
                continue;
            }
            // A body obelisk publishes no cell: being landed IS being there, so
            // if we are on that body and still missing it, the reading will
            // come on its own and there is nothing to walk to.
            if (! isset($o['x'], $o['y']) || $o['x'] === null || $o['y'] === null) {
                continue;
            }

            return ['position' => $pos, 'x' => (int) $o['x'], 'y' => (int) $o['y'], 'place' => $here];
        }

        return null;
    }

    /**
     * A `move` toward the obelisk under our feet's worth of map, or `null`.
     *
     * @return array{verb:string,args:array<string,mixed>,why:string}|null
     */
    public static function walkStep(array $raw): ?array
    {
        if (($o = self::obeliskHere($raw)) === null) {
            return null;
        }
        $x = (int) ((array) ($raw['position'] ?? [0, 0]))[0];
        $y = (int) ((array) ($raw['position'] ?? [0, 0]))[1];
        $dist = abs($x - $o['x']) + abs($y - $o['y']);
        if ($dist <= self::OBELISK_RANGE) {
            return null;
        }

        return [
            'verb' => 'move',
            'args' => ['x' => $o['x'], 'y' => $o['y']],
            'why' => "vault — walk to the {$o['place']} obelisk at ({$o['x']},{$o['y']}) for seal position {$o['position']} ({$dist} away)",
        ];
    }

    /**
     * The full code, in order, or `null` while any position is missing.
     *
     * Deliberately all-or-nothing. A wrong code costs 90 ticks and tells us
     * nothing, so there is no such thing as a cheap guess worth trying.
     *
     * @return list<string>|null
     */
    public static function code(array $raw): ?array
    {
        if (self::missing($raw) !== []) {
            return null;
        }
        $have = self::fragments($raw);
        $total = (int) (self::block($raw)['symbols_in_seal'] ?? 6);
        $code = [];
        for ($i = 1; $i <= $total; ++$i) {
            $code[] = $have[$i];
        }

        return $code;
    }

    /**
     * `unlock{code}` when all six are in hand and the agent is standing on the
     * body the vault is on, else `null`.
     *
     * @return array{verb:string,args:array<string,mixed>,why:string}|null
     */
    public static function unlockStep(array $raw): ?array
    {
        if (self::open($raw) || self::cooldown($raw) > 0) {
            return null;
        }
        $where = (string) (self::block($raw)['where'] ?? '');
        if ($where === '' || Ladder::atBody($raw) !== $where || ($raw['in_space'] ?? false)) {
            return null;
        }
        if (($code = self::code($raw)) === null) {
            return null;
        }

        return [
            'verb' => 'unlock',
            'args' => ['code' => $code],
            'why' => 'vault — all six symbols in hand; speak the seal',
        ];
    }

    /**
     * What to say in chat: the symbols we have read, and a request for the
     * positions we lack, addressed to the agents the world says already hold
     * them.
     *
     * This is the mechanic working as designed rather than a courtesy. The
     * fragments are bound to agents who physically stood at a stone, so asking
     * is strictly cheaper than walking, and the board tells us exactly who to
     * ask.
     *
     * Returns `null` when there is nothing useful to say — no fragments to
     * offer and no gaps, or the seal is already open.
     */
    public static function chatLine(array $raw): ?string
    {
        if (self::open($raw) || ! self::authenticated($raw)) {
            return null;
        }
        $have = self::fragments($raw);
        $missing = self::missing($raw);
        if ($have === [] && $missing === []) {
            return null;
        }

        $parts = [];
        if ($have !== []) {
            $offer = [];
            foreach ($have as $pos => $sym) {
                $offer[] = "{$pos}={$sym}";
            }
            $parts[] = 'vault seal: I have ' . implode(' ', $offer) . '.';
        }
        if ($missing !== []) {
            $ask = (array) (self::block($raw)['ask_these_agents'] ?? []);
            $who = [];
            foreach ($missing as $pos) {
                foreach ((array) ($ask[(string) $pos] ?? $ask[$pos] ?? []) as $name) {
                    $who[(string) $name] = true;
                }
            }
            $parts[] = 'Need ' . implode(',', $missing) . '.'
                . ($who === [] ? ' Nobody has read those yet.' : ' ' . implode(' ', array_map(
                    static fn(string $n): string => '@' . $n,
                    array_slice(array_keys($who), 0, 6),
                )));
        }

        // `say` is capped at 280 characters and one per tick, so a line that
        // overruns is not truncated by the engine — it is REFUSED, which is the
        // refusable-verb spin all over again. Trim the @-list (the expendable
        // part; the symbols are the message) until it fits.
        $line = implode(' ', $parts);
        while (mb_strlen($line) > self::SAY_MAX && str_contains($line, ' @')) {
            $line = (string) preg_replace('/ @[^ ]+$/u', '', $line);
        }

        return mb_substr($line, 0, self::SAY_MAX);
    }

    /** @return array<string,mixed> */
    private static function block(array $raw): array
    {
        return (array) (((array) ($raw['expansion'] ?? []))['vault'] ?? []);
    }
}
