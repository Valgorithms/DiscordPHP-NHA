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
 * The world's destinations, read from the world instead of from a constant.
 *
 * Every fact this class serves is already published per-observation, and the
 * brain was carrying a hand-transcribed copy of all of it:
 *
 *   `expansion.windows[body]`                → `dv_need`, `transit_ticks`, `open`, `opens_in`
 *   `expansion.preflight.destinations[body]` → `needs_in_hold`,
 *                                              `needs_landing_gear_on_ship`,
 *                                              `min_thrust_to_weight`,
 *                                              `course_correction_fuel`,
 *                                              `ready`, `blockers`
 *
 * Season 8 is why this matters. It added `enceladus`, `titan` and `triton`
 * while {@see Ladder::DEPART_ORDER} and {@see GameData::DV_NEED} still named
 * four bodies, so the agent could not see three of the seven places it was
 * allowed to fly — and the one body with open colony work (Triton, 0/3) was
 * among the invisible ones. A transcribed table does not fail loudly when the
 * world moves; it just quietly stops describing it.
 *
 * It also carried facts nobody had transcribed at all. `course_correction_fuel`
 * is charged over the crossing — 4 units to Deimos, 13 to Triton across its 260
 * ticks — and the fuel bar never counted it, which is a stranding on arrival
 * rather than a refusal at the pad.
 *
 * The constants stay as a FALLBACK, not as truth: an observation with no
 * `expansion` block (a cached obs, an offline test) still answers, and answers
 * with the Season-7 table it always used. Live data wins whenever there is any.
 *
 * @since 3.11.0
 */
final class Bodies
{
    /**
     * What the engine published before Season 8, used only when an observation
     * carries no `expansion` block at all.
     *
     * Deliberately NOT kept in sync by hand — it is the floor under a missing
     * observation, not a mirror of the world. When it disagrees with a live
     * observation, the observation is right.
     *
     * @var array<string,array{dv_need:int,needs_in_hold:list<string>,needs_gear:bool,twr:float}>
     */
    public const FALLBACK = [
        'deimos' => ['dv_need' => 50, 'needs_in_hold' => [], 'needs_gear' => true, 'twr' => 0.5],
        'phobos' => ['dv_need' => 55, 'needs_in_hold' => [], 'needs_gear' => true, 'twr' => 0.5],
        'mars' => ['dv_need' => 100, 'needs_in_hold' => ['heat_shield'], 'needs_gear' => true, 'twr' => 0.7],
        'venus' => ['dv_need' => 130, 'needs_in_hold' => ['heat_shield', 'acid_skin'], 'needs_gear' => false, 'twr' => 0.9],
    ];

    /**
     * Every destination the world currently offers, cheapest-Δv first.
     *
     * Merges the two blocks the engine splits this across. A body named in
     * either one is real: `windows` alone still means it can be flown to, and
     * `preflight.destinations` alone means the window is simply not quoted
     * right now.
     *
     * @param array<string,mixed> $raw a raw observation
     *
     * @return array<string,array{dv_need:int,transit_ticks:int,open:bool,opens_in:int,needs_in_hold:list<string>,needs_gear:bool,twr:float,correction_fuel:int,ready:bool,blockers:list<string>}>
     */
    public static function all(array $raw): array
    {
        $ex = (array) ($raw['expansion'] ?? []);
        $windows = (array) ($ex['windows'] ?? []);
        $dests = (array) (((array) ($ex['preflight'] ?? []))['destinations'] ?? []);

        if ($windows === [] && $dests === []) {
            return self::fromFallback();
        }

        $out = [];
        foreach (array_unique([...array_keys($windows), ...array_keys($dests)]) as $body) {
            $body = (string) $body;
            // `earth` is the way home, never an outbound destination to pick.
            if ($body === '' || $body === 'earth') {
                continue;
            }
            $w = (array) ($windows[$body] ?? []);
            $d = (array) ($dests[$body] ?? []);
            // An observation that quotes a window but no preflight row (a
            // trimmed obs, an older engine) tells us nothing about what the
            // arrival consumes — and "nothing" must not read as "nothing is
            // required". Missing data falls back to what the body was last
            // known to demand, so the gap can only ever ask for MORE gear than
            // the engine would, never less.
            $fb = self::FALLBACK[$body] ?? null;
            $out[$body] = [
                'dv_need' => (int) ($w['dv_need'] ?? ($fb['dv_need'] ?? 0)),
                'transit_ticks' => (int) ($w['transit_ticks'] ?? 0),
                'open' => (bool) ($w['open'] ?? false),
                'opens_in' => (int) ($w['opens_in'] ?? 0),
                'needs_in_hold' => array_values(array_map(
                    'strval',
                    (array) ($d['needs_in_hold'] ?? ($fb['needs_in_hold'] ?? [])),
                )),
                'needs_gear' => (bool) ($d['needs_landing_gear_on_ship'] ?? ($fb['needs_gear'] ?? false)),
                'twr' => (float) ($d['min_thrust_to_weight'] ?? ($fb['twr'] ?? 0.0)),
                'correction_fuel' => (int) ($d['course_correction_fuel'] ?? 0),
                'ready' => (bool) ($d['ready'] ?? false),
                'blockers' => array_values(array_map('strval', (array) ($d['blockers'] ?? []))),
            ];
        }

        uasort($out, static fn(array $a, array $b): int => $a['dv_need'] <=> $b['dv_need']);

        return $out;
    }

    /**
     * The live replacement for {@see Ladder::DEPART_ORDER}: body → the items its
     * arrival consumes, cheapest-Δv first.
     *
     * @param array<string,mixed> $raw
     *
     * @return array<string,list<string>>
     */
    public static function departOrder(array $raw): array
    {
        return array_map(static fn(array $b): array => $b['needs_in_hold'], self::all($raw));
    }

    /** Every destination the world offers, cheapest-Δv first. @return list<string> */
    public static function names(array $raw): array
    {
        return array_keys(self::all($raw));
    }

    /**
     * Destinations whose `depart` also demands a `landing_gear` part ON the
     * ship — the live {@see GameData::GEAR_BODIES}.
     *
     * @param array<string,mixed> $raw
     *
     * @return list<string>
     */
    public static function gearBodies(array $raw): array
    {
        $out = [];
        foreach (self::all($raw) as $body => $b) {
            if ($b['needs_gear']) {
                $out[] = (string) $body;
            }
        }

        return $out;
    }

    /**
     * Thrust-to-weight each destination demands — the live
     * {@see GameData::TWR_DEPART}, with `earth` kept for the trip home.
     *
     * @param array<string,mixed> $raw
     *
     * @return array<string,float>
     */
    public static function twr(array $raw): array
    {
        $out = ['earth' => (float) (GameData::TWR_DEPART['earth'] ?? 0.5)];
        foreach (self::all($raw) as $body => $b) {
            $out[(string) $body] = $b['twr'] > 0 ? $b['twr'] : (float) (GameData::TWR_DEPART[$body] ?? 0.5);
        }

        return $out;
    }

    /**
     * Fuel the crossing itself spends on course corrections, beyond the
     * departure burn.
     *
     * Never modelled before, and it is not a rounding error: Triton charges 13
     * over its 260 ticks. Leaving it out does not get the `depart` refused — it
     * gets the agent to the far side of the solar system with an empty tank.
     *
     * @param array<string,mixed> $raw
     */
    public static function correctionFuel(array $raw, string $body): int
    {
        return (int) ((self::all($raw)[$body] ?? [])['correction_fuel'] ?? 0);
    }

    /**
     * Arrival gear this brain has a crafting path for.
     *
     * Deliberately a statement about THIS CODE, not about the game — it lists
     * what {@see Ladder} has rungs to produce, and it is the one table here that
     * should be hand-maintained. `thermal_core` is absent because its recipe
     * (a battery + `mars_ice` + a non-magnetic metal) needs a body resource
     * hauled from Mars, and no rung plans that trip. Add a gear item here in the
     * same change that adds the rung that makes it.
     *
     * @since 3.14.0
     */
    public const CRAFTABLE_GEAR = ['heat_shield', 'acid_skin'];

    /**
     * Whether the agent could ever equip for this destination: every item its
     * arrival consumes is either already in hand or something the ladder knows
     * how to make.
     *
     * The distinction this draws is between a destination that is merely
     * *waiting* (the window is shut) and one that is *blocked* (the gear will
     * never appear). Treating the second as the first is a dead end with a
     * patient face: live, #142285 sat in Earth orbit for days "holding for a
     * transfer window to open" for Triton — the one body with colony work left
     * — whose window was never the obstacle, because nothing was ever going to
     * produce the `thermal_core` it needs.
     *
     * @param array<string,mixed> $raw
     *
     * @since 3.14.0
     */
    public static function reachable(array $raw, string $body): bool
    {
        $b = self::all($raw)[$body] ?? null;
        if ($b === null) {
            return false;
        }
        $inv = (array) ($raw['inventory'] ?? []);
        foreach ($b['needs_in_hold'] as $item) {
            if ((int) ($inv[$item] ?? 0) < 1 && ! in_array($item, self::CRAFTABLE_GEAR, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The gear standing between the agent and a destination that it cannot
     * make — empty when the body is {@see reachable()}.
     *
     * @param array<string,mixed> $raw
     *
     * @return list<string>
     *
     * @since 3.14.0
     */
    public static function missingGear(array $raw, string $body): array
    {
        $inv = (array) ($raw['inventory'] ?? []);

        return array_values(array_filter(
            (array) ((self::all($raw)[$body] ?? [])['needs_in_hold'] ?? []),
            static fn(string $item): bool => (int) ($inv[$item] ?? 0) < 1 && ! in_array($item, self::CRAFTABLE_GEAR, true),
        ));
    }

    /**
     * Whether a live warp-gate pair joins Earth to this body.
     *
     * A pair lets `depart` ignore the launch window and costs a fifth of the
     * Δv — but it carries exotic body cargo ONLY in single-use `warp_container`
     * crates, so for an agent hauling a body's worth of regolith the gate is a
     * wall, not a shortcut. Read from `expansion.gates.linked_from_here`.
     *
     * @param array<string,mixed> $raw
     *
     * @since 3.14.0
     */
    public static function gateLinked(array $raw, string $body): bool
    {
        $gates = (array) (((array) ($raw['expansion'] ?? []))['gates'] ?? []);

        return in_array($body, array_map('strval', (array) ($gates['linked_from_here'] ?? [])), true);
    }

    /**
     * The engine's own verdict on a destination, when it has published one.
     * `null` means it said nothing — not that the answer is no.
     *
     * @param array<string,mixed> $raw
     */
    public static function ready(array $raw, string $body): ?bool
    {
        $b = self::all($raw)[$body] ?? null;

        return $b === null || $b['blockers'] === [] && ! $b['ready'] ? null : $b['ready'];
    }

    /** @return array<string,array<string,mixed>> */
    private static function fromFallback(): array
    {
        $out = [];
        foreach (self::FALLBACK as $body => $b) {
            $out[$body] = $b + [
                'transit_ticks' => 0, 'open' => false, 'opens_in' => 0,
                'correction_fuel' => 0, 'ready' => false, 'blockers' => [],
            ];
        }

        return $out;
    }
}
