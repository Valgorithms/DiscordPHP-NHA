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
 * The supply run: fetch a body resource that a piece of arrival gear needs,
 * make the gear on that body, and bring it home.
 *
 * Triton's colony has work left and needs a `thermal_core` in the hold to land.
 * A thermal_core is "a battery + mars_ice + a non-magnetic metal", and
 * `mars_ice` is mined only on Mars (the engine's `BODY_MINE`). Nothing else
 * could make that trip: the depart gate never picked a finished colony, and on
 * a finished body the only job left was the trip home. So the plan to reach
 * Triton stalled on its step 3 however well the model reasoned.
 *
 * The engine's own rules shape the run (`engine/engine.py`):
 *  - Earth and Mars are gate-linked, and a linked pair ALWAYS warps: no window,
 *    a fifth of the Δv, but exotic body cargo crosses only in `warp_container`
 *    crates of 25. The agent carries millions of `c_regolith` and its
 *    extractors add about ten a tick, so the bulk is shed into a 1-credit sell
 *    order (escrow leaves the hold at once) and a few crates carry the drip.
 *  - `mine` on a body ignores `resource` and yields its whole table: six
 *    `mars_ice` a turn on Mars.
 *  - A thermal_core is not exotic cargo, so it is made ON Mars and rides home
 *    through the gate without a crate.
 *  - `depart {dest:'earth'}` leaves from a body's surface.
 *
 * Every move is derived from the observation, so there is no run state to
 * drift: whatever the agent holds and wherever it stands says what is next.
 * {@see AutoPlayer} submits the move directly, past the model-facing gates,
 * and stands the run down for a while if the game refuses one of its moves
 * for a reason shedding cannot fix.
 *
 * @since 3.20.0
 */
final class SupplyRun
{
    /** Gear this class can finish on the body that yields its exotic input, and how. */
    public const ON_SITE = [
        'thermal_core' => ['battery' => 1, 'mars_ice' => 1, 'copper' => 1],
    ];

    /**
     * How to make each input at home, one of each ingredient per copy: the
     * world's known signatures (`/rules` `dynamic`) and the codex's own
     * combinations — the acid_skin chain is the one {@see Ladder} already
     * crafts for the Venus leg.
     */
    public const HOME_CRAFTS = [
        'battery' => ['metal' => 1, 'salt' => 1, 'silicon' => 1],
        'chip' => ['metal' => 1, 'silicon' => 1],
        'plastic' => ['oil' => 1, 'carbon' => 1],
        'rubber' => ['sulfur' => 1, 'plastic' => 1],
        'acid' => ['sulfur' => 1, 'water' => 1],
        'acid_skin' => ['acid' => 1, 'rubber' => 1],
        'composite' => ['aluminum' => 1, 'carbon' => 1],
        'heat_shield' => ['superalloy' => 1, 'composite' => 1],
        'warp_container' => ['superalloy' => 1, 'chip' => 1, 'acid_skin' => 1],
    ];

    /**
     * Crates to hold before a gated trip: the extractor drip is about ten
     * units a tick, so roughly three crates a crossing, both ways, plus margin.
     */
    public const CRATES = 8;

    /** Batteries to pack: a failed combine on Mars must not strand the run. */
    public const BATTERIES = 2;

    /** Body resource to hold before crafting on site (the craft takes one; one mine yields six). */
    public const RESOURCE_TARGET = 3;

    /** The most of one line bought in a turn — {@see Ladder::DEPOT_SAFE_LOT}. */
    private const BUY_LOT = 25;

    /**
     * The run to make, if any: arrival gear that an UNFOUNDED destination
     * needs, that the agent lacks and this class can finish on site, and the
     * body resource it needs from where.
     *
     * Unfounded only. A founded colony takes credits from home through
     * `invest`, so going there is not the only way to help it; an unfounded
     * one refuses money until someone lands and lays it, which is the one
     * thing only the trip can do.
     *
     * @param array<string,mixed> $raw         The observation (its destinations' `needs_in_hold`).
     * @param list<string>        $colonyDone  Bodies whose colony is finished.
     * @param list<string>        $unfounded   Bodies whose colony nobody has laid yet.
     * @param list<string>        $unreachable Bodies the engine has refused for good — Triton,
     *                                         past the Δv any ship can make. Gear for a trip
     *                                         that cannot be flown is not worth a run.
     *
     * @return array{item: string, resource: string, body: string}|null
     */
    public static function need(array $raw, array $colonyDone, array $unfounded, array $unreachable = []): ?array
    {
        $inv = (array) ($raw['inventory'] ?? []);
        $here = Ladder::atBody($raw);
        foreach (Bodies::all($raw) as $dest => $b) {
            if (! in_array($dest, $unfounded, true) || in_array($dest, $colonyDone, true) || in_array($dest, $unreachable, true)) {
                continue;
            }
            foreach ((array) ($b['needs_in_hold'] ?? []) as $item) {
                foreach (array_keys(self::ON_SITE[$item] ?? []) as $input) {
                    if (($bodies = GameData::minedOn((string) $input)) === []) {
                        continue;
                    }
                    // Missing: go and get it. Held but still on the supply
                    // body: the run is not over until it is home. Live, the
                    // first run made its thermal_core on Mars and stopped
                    // right there; the finished-colony machine then held for
                    // a window the gate does not need, hit its strand limit,
                    // and called `distress` (-20 HP, the Mars haul jettisoned).
                    if ((int) ($inv[$item] ?? 0) < 1 || $here === $bodies[0]) {
                        return ['item' => (string) $item, 'resource' => (string) $input, 'body' => $bodies[0]];
                    }
                }
            }
        }

        return null;
    }

    /**
     * This turn's move for the run, or null to leave the turn to ordinary play
     * (in transit, at some other body, or unable to progress).
     *
     * @param array<string,mixed>                                 $raw          The observation.
     * @param array{item: string, resource: string, body: string} $need         From {@see need()}.
     * @param bool                                                $cargoRefused Last turn's depart was refused for uncrated cargo.
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    public static function step(array $raw, array $need, bool $cargoRefused = false): ?array
    {
        if (Ladder::inTransit($raw)) {
            return null;
        }
        $inv = (array) ($raw['inventory'] ?? []);
        $here = Ladder::atBody($raw);
        if ($here === $need['body'] && Ladder::atBodyOrbit($raw) === $need['body']) {
            return self::act('land_body', [], "over {$need['body']} — land to mine {$need['resource']}");
        }
        if ($here === $need['body']) {
            return self::onBody($raw, $need, $inv, $cargoRefused);
        }

        return $here === null ? self::atHome($raw, $need, $inv, $cargoRefused) : null;
    }

    /**
     * The next move toward holding `$n` of `$item`: buy it if the depot sells
     * it, else craft it (all the missing copies in one batched `combine`),
     * acquiring its inputs first. Null when it cannot be had this turn.
     *
     * @param array<string,mixed>             $inv
     * @param array<string,array<string,int>> $recipes
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    public static function make(string $item, int $n, array $inv, array $recipes = self::HOME_CRAFTS, int $depth = 0): ?array
    {
        $short = $n - (int) ($inv[$item] ?? 0);
        if ($short < 1 || $depth > 4) {
            return null;
        }
        $recipe = $recipes[$item] ?? null;
        if ($recipe === null) {
            $buy = Ladder::affordableBuy($item, min($short, self::BUY_LOT), (int) ($inv['credits'] ?? 0));

            return $buy === null ? null : self::act('buy', $buy['args'], "buy {$buy['n']} {$item}");
        }
        foreach ($recipe as $input => $per) {
            if ((int) ($inv[$input] ?? 0) < $per * $short) {
                return self::make((string) $input, $per * $short, $inv, $recipes, $depth + 1);
            }
        }

        return self::act('combine', ['ingredients' => $recipe, 'n' => $short], "combine {$short} {$item}");
    }

    /**
     * At home: craft on the spot if the body resource is already held, else
     * pack (spare batteries, the other on-site inputs, the body's arrival
     * items, crates for a gated route), then fly: ground → elevator → the
     * depart band → shed what the crates cannot carry → depart.
     *
     * @param array<string,mixed>                                 $raw
     * @param array{item: string, resource: string, body: string} $need
     * @param array<string,mixed>                                 $inv
     */
    private static function atHome(array $raw, array $need, array $inv, bool $cargoRefused): ?array
    {
        $body = $need['body'];
        $onSite = self::ON_SITE[$need['item']];
        if ((int) ($inv[$need['resource']] ?? 0) >= 1) {
            return self::make($need['item'], 1, $inv, [$need['item'] => $onSite] + self::HOME_CRAFTS);
        }

        $gated = Bodies::gateLinked($raw, $body);
        $pack = [];
        foreach ($onSite as $input => $per) {
            if ($input !== $need['resource']) {
                $pack[(string) $input] = $input === 'battery' ? self::BATTERIES : $per;
            }
        }
        foreach ((array) ((Bodies::all($raw)[$body] ?? [])['needs_in_hold'] ?? GameData::BODY_ITEMS[$body] ?? []) as $arrival) {
            $pack[(string) $arrival] = max(1, $pack[(string) $arrival] ?? 0);
        }
        if ($gated) {
            $pack['warp_container'] = self::CRATES;
        }

        $inSpace = (bool) ($raw['in_space'] ?? false);
        $alt = (int) ($raw['altitude'] ?? 0);
        foreach ($pack as $what => $n) {
            if ((int) ($inv[$what] ?? 0) < $n) {
                // The depot is on the ground; packing happens there.
                if ($inSpace) {
                    return self::toElevator($raw, "down to the depot to pack {$what}");
                }
                $move = self::make($what, $n, $inv);

                return $move === null ? null : self::act($move['verb'], $move['args'], "packing for {$body}: {$move['why']}");
            }
        }

        if (! $inSpace) {
            return self::toElevator($raw, "packed for {$body} — up to the depart band");
        }
        if ($alt < 300) {
            // Below the band: a ride from space drops to the base; the next climbs to the top.
            return self::toElevator($raw, "below the depart band (alt {$alt}) — reset at the elevator");
        }

        $room = ($gated || $cargoRefused) ? GameData::WARP_CRATE_CAP * (int) ($inv['warp_container'] ?? 0) : PHP_INT_MAX;
        if ($cargoRefused || self::haul($inv) > $room) {
            return self::shed($inv, "the {$body} gate carries only " . ($room === PHP_INT_MAX ? 'crated' : $room) . ' units of body cargo');
        }

        return self::act('depart', ['dest' => $body], 'packed' . ($gated ? ' (' . (int) ($inv['warp_container'] ?? 0) . ' crates)' : '') . " — depart for {$body}");
    }

    /**
     * On the body: mine the resource, make the item, shed what the crates
     * cannot carry, and depart for home from the surface.
     *
     * @param array<string,mixed>                                 $raw
     * @param array{item: string, resource: string, body: string} $need
     * @param array<string,mixed>                                 $inv
     */
    private static function onBody(array $raw, array $need, array $inv, bool $cargoRefused): ?array
    {
        $item = $need['item'];
        $res = $need['resource'];
        if ((int) ($inv[$item] ?? 0) >= 1) {
            $gated = Bodies::gateLinked($raw, 'earth') || Bodies::gateLinked($raw, $need['body']);
            $room = ($gated || $cargoRefused) ? GameData::WARP_CRATE_CAP * (int) ($inv['warp_container'] ?? 0) : PHP_INT_MAX;
            if ($cargoRefused || self::haul($inv) > $room) {
                return self::shed($inv, "{$item} made; the gate home carries only " . ($room === PHP_INT_MAX ? 'crated' : $room) . ' units of body cargo');
            }

            return self::act('depart', ['dest' => 'earth'], "{$item} made — home");
        }
        if ((int) ($inv[$res] ?? 0) < self::RESOURCE_TARGET) {
            return self::act('mine', ['n' => 6], "mine {$need['body']} for {$res} (" . (int) ($inv[$res] ?? 0) . '/' . self::RESOURCE_TARGET . ')');
        }
        foreach (self::ON_SITE[$item] as $input => $per) {
            if ((int) ($inv[$input] ?? 0) < $per) {
                return null; // an input left behind — nothing to make it from here
            }
        }

        return self::act('combine', ['ingredients' => self::ON_SITE[$item]], "make the {$item} here, where the {$res} is");
    }

    /**
     * Walk to the tall elevator, or ride it: up from the ground, down from space.
     *
     * @param array<string,mixed> $raw
     */
    private static function toElevator(array $raw, string $why): ?array
    {
        $lift = Ladder::orbitElevator($raw);
        if ($lift === null) {
            return null;
        }
        $pos = array_values((array) ($raw['position'] ?? [0, 0]));

        return ((int) ($pos[0] ?? -1) === $lift['x'] && (int) ($pos[1] ?? -1) === $lift['y'])
            ? self::act('ride', [], $why)
            : self::act('move', ['x' => $lift['x'], 'y' => $lift['y']], "{$why}: walk to the elevator");
    }

    /**
     * Put the largest line of body cargo into a 1-credit sell order. The
     * escrow leaves the hold at once, which is what a gate crossing needs;
     * the largest line goes first so small useful lines stay if they fit.
     *
     * @param array<string,mixed> $inv
     */
    private static function shed(array $inv, string $why): ?array
    {
        $lines = [];
        foreach (GameData::EXPANSION_CARGO as $r) {
            if (($q = (int) ($inv[$r] ?? 0)) > 0) {
                $lines[$r] = $q;
            }
        }
        if ($lines === []) {
            return null;
        }
        arsort($lines);
        $r = (string) array_key_first($lines);

        return self::act('order', ['side' => 'sell', 'resource' => $r, 'qty' => $lines[$r], 'price' => 1], "{$why}: shed {$lines[$r]} {$r} into a 1-credit sell order");
    }

    /**
     * Units of exotic body cargo held.
     *
     * @param array<string,mixed> $inv
     */
    public static function haul(array $inv): int
    {
        $n = 0;
        foreach (GameData::EXPANSION_CARGO as $r) {
            $n += max(0, (int) ($inv[$r] ?? 0));
        }

        return $n;
    }

    /** @return array{verb: string, args: array<string,mixed>, why: string} */
    private static function act(string $verb, array $args, string $why): array
    {
        return ['verb' => $verb, 'args' => $args, 'why' => $why];
    }
}
