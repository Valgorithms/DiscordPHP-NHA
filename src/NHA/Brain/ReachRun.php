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
 * The reach run: fit a ship and fuel it for a body that is within the
 * engine's Δv ceiling but beyond every ship the agent owns, then hand the trip
 * itself back to ordinary flight.
 *
 * Built for Triton. On 2026-09-30 its Δv came down from 320 out and 310 home
 * (past the ceiling of 300 for any ship) to 260 and 250. The agent noticed
 * ({@see AutoPlayer::reopenReach()}) and held for the window as
 * "flight-ready", but its best ship made 136 on cryo_fuel. The preflight now
 * says so: "Δv too low … carry helium3 (the only fuel that reaches the outer
 * system)". The departure would have spent a window on a refusal.
 *
 * The engine's arithmetic ({@see GameData::leg()}) says what a ship needs:
 * helium3 on an ion drive makes `loaded·1500 ÷ (mass + 5·loaded)`, so 260
 * needs a load of at least 1.3 × the ship's mass. The agent's hulls weigh 600
 * to 1,000 and carry at most 640, so none can. {@see LANDER} is the lightest
 * hull that flies on an ion jet with landing gear: mass 295, 600 units of
 * tank, 273 on full tanks.
 *
 * Helium3 comes only from the Moon: `mine` there yields six a turn. The Moon
 * is reached by riding the tall elevator to the top of the sky (altitude 600)
 * and `land_moon` in the SAME tick, because orbital decay takes two altitude
 * a tick and `land_moon` refuses anything below 600. So that move is two
 * intents, submitted back to back. A second `ride` from the elevator cell
 * brings the agent straight down to the ground and off the Moon.
 *
 * Like {@see SupplyRun}, every move is read off the observation, and
 * {@see AutoPlayer} submits it directly.
 *
 * @since 3.27.0
 */
final class ReachRun
{
    /**
     * The lander: `part => [count, with-item|null]`. Built in this order, so the
     * part that needs a bought item comes first.
     *
     * @var array<string,array{0: int, 1: string|null}>
     */
    public const LANDER = [
        'jet' => [1, 'ion_thruster'],
        'cockpit' => [1, null],
        'landing_gear' => [1, null],
        'fuel_tank' => [3, null],
        'wing' => [1, null],
    ];

    /** The name `finalize` gives the lander. */
    public const LANDER_NAME = 'reach_lander';

    /** Helium3 a `mine` on the Moon yields (`engine.py`: `min(n, 6)`). */
    public const MOON_YIELD = 6;

    /**
     * Helium3 mined past the target before leaving the Moon, so a stray unit
     * spent at home does not send the agent straight back.
     */
    public const MARGIN = 60;

    /** The altitude of the Moon; `land_moon` refuses anything lower (`engine.py` `SKY_TOP`). */
    public const MOON_ALT = 600;

    /** Refused moves the run simply makes again: a missed tick or a step out of place, not a wall. */
    public const RETRY_VERBS = ['ride', 'land_moon', 'mine', 'move'];

    /**
     * The reach plan for the first body that needs one, or null.
     *
     * A body qualifies when the agent is wanted there in person, it is not
     * finished for the agent, and its Δv is under the ceiling. `ready` says the
     * agent can already go and come back on what it holds.
     *
     * @param array<string,mixed> $raw
     * @param list<string>        $colonyDone
     * @param list<string>        $wanted     Bodies the agent has to stand on to help: an unfounded
     *                                        colony, or a line only on-site mining can fill
     *                                        ({@see Objectives::bodiesNeedingPresence()}).
     *
     * @return array{body: string, dv: int, transit: int, build: bool, helium3: int, ready: bool}|null
     */
    public static function plan(array $raw, array $colonyDone, array $wanted): ?array
    {
        $inv = (array) ($raw['inventory'] ?? []);
        foreach (Bodies::all($raw) as $dest => $b) {
            $dest = (string) $dest;
            if (! in_array($dest, $wanted, true) || in_array($dest, $colonyDone, true)) {
                continue;
            }
            $dv = (int) $b['dv_need'];
            if ($dv <= 0 || $dv >= GameData::DV_CEILING) {
                continue;
            }
            $transit = (int) $b['transit_ticks'];
            $hulls = self::hulls($raw, $b);

            $fuel = null;
            foreach ($hulls as $h) {
                $f = GameData::roundTripHelium3($h['mass'], $h['fuel_cap'], $dv, $transit);
                if ($f !== null && ($fuel === null || $f < $fuel)) {
                    $fuel = $f;
                }
            }
            $build = false;
            if ($fuel === null) {
                $lander = GameData::assess(self::LANDER);
                $fuel = GameData::roundTripHelium3($lander['mass'], $lander['fuel_cap'], $dv, $transit);
                if ($fuel === null) {
                    continue; // not even the lander: nothing this run can do
                }
                $build = true;
            }

            return [
                'body' => $dest, 'dv' => $dv, 'transit' => $transit, 'build' => $build, 'helium3' => $fuel,
                'ready' => ! $build && self::canGoAndReturn($hulls, $inv, $dv, $transit),
            ];
        }

        return null;
    }

    /**
     * This turn's move, or null to leave the turn to ordinary play.
     *
     * @param array<string,mixed> $raw
     * @param list<string>        $colonyDone
     * @param list<string>        $wanted     {@see plan()}
     *
     * @return array{verb: string, args: array<string,mixed>, why: string, then?: array{verb: string, args: array<string,mixed>}}|null
     */
    public static function turn(array $raw, array $colonyDone, array $wanted): ?array
    {
        if (Ladder::inTransit($raw)) {
            return null;
        }
        $plan = self::plan($raw, $colonyDone, $wanted);
        $he = (int) (((array) ($raw['inventory'] ?? []))['helium3'] ?? 0);

        if (self::onMoon($raw)) {
            if ($plan !== null && $he < $plan['helium3'] + self::MARGIN) {
                return self::act('mine', ['n' => self::MOON_YIELD], "Moon: helium3 {$he}/" . ($plan['helium3'] + self::MARGIN) . " for {$plan['body']}");
            }

            // Riding from the elevator cell brings the agent down and off the
            // Moon. If decay has already put it on the ground there, the ride
            // goes up first and the next one comes down.
            return self::act('ride', [], ($plan === null ? 'no reach plan' : "helium3 {$he} held") . ' — leave the Moon by the elevator');
        }
        if ($plan === null || $plan['ready'] || Ladder::atBody($raw) !== null) {
            return null;
        }
        if ($plan['build']) {
            return self::buildStep($raw, $plan);
        }
        if ($he < $plan['helium3']) {
            return self::toMoon($raw, $plan, $he);
        }

        return null;
    }

    /**
     * Whether the agent stands on the Moon (`engine.py` `place()`).
     *
     * @param array<string,mixed> $raw
     */
    public static function onMoon(array $raw): bool
    {
        return (((array) (((array) ($raw['expansion'] ?? []))['place'] ?? []))['where'] ?? '') === 'moon_surface';
    }

    /**
     * The agent's ships `depart` would consider for this body: flying, ion
     * driven, with tank, past its thrust gate, and geared where it lands.
     *
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $body {@see Bodies::all()} row
     *
     * @return list<array{mass: int, fuel_cap: int, name: string}>
     */
    public static function hulls(array $raw, array $body): array
    {
        $out = [];
        foreach ((array) ($raw['vehicles'] ?? []) as $v) {
            $v = (array) $v;
            $mass = max(1, (int) ($v['mass'] ?? 0));
            if (empty($v['flies']) || empty($v['orbital_engine']) || ! empty($v['wrecked']) || (int) ($v['fuel_cap'] ?? 0) < 1) {
                continue;
            }
            if ((int) ($v['thrust'] ?? 0) < (float) ($body['twr'] ?? 0) * GameData::GRAVITY * $mass) {
                continue;
            }
            if (! empty($body['needs_gear']) && (int) ($v['gear'] ?? 0) < 1) {
                continue;
            }
            $out[] = ['mass' => $mass, 'fuel_cap' => (int) $v['fuel_cap'], 'name' => (string) ($v['name'] ?? '')];
        }

        return $out;
    }

    /**
     * Whether the agent can go and come back on what it holds, choosing as
     * `depart` does: the ship and fuel with the most Δv, then the correction
     * gate on that one.
     *
     * @param list<array{mass: int, fuel_cap: int, name: string}> $hulls
     * @param array<string,mixed>                                 $inv
     */
    public static function canGoAndReturn(array $hulls, array $inv, int $need, int $transit): bool
    {
        $fuels = [];
        foreach (GameData::FUEL_VE as $f => $ve) {
            $fuels[$f] = (int) ($inv[$f] ?? 0);
        }
        for ($leg = 0; $leg < 2; ++$leg) {
            $best = null;
            foreach ($hulls as $h) {
                foreach (GameData::FUEL_VE as $f => $ve) {
                    if ($fuels[$f] < 1) {
                        continue;
                    }
                    $p = GameData::leg($h['mass'], $h['fuel_cap'], $fuels[$f], $need, $transit, $ve);
                    if ($best === null || $p['dv'] > $best['dv']) {
                        $best = $p + ['fuel' => $f];
                    }
                }
            }
            if ($best === null || ! $best['ok']) {
                return false;
            }
            $fuels[$best['fuel']] -= $best['cost'] + $best['corrections'];
        }

        return true;
    }

    /**
     * The next part of the lander: buy what a part needs (on the ground,
     * where the depot is), build it, and `finalize` once every part is loose.
     *
     * @param array<string,mixed>          $raw
     * @param array{body: string, dv: int} $plan
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    private static function buildStep(array $raw, array $plan): ?array
    {
        $inv = (array) ($raw['inventory'] ?? []);
        $loose = array_count_values(Ladder::looseParts($raw));
        $inSpace = (bool) ($raw['in_space'] ?? false) && (int) ($raw['altitude'] ?? 0) > 0;
        $for = "a lander for {$plan['body']} (Δv {$plan['dv']})";

        foreach (self::LANDER as $part => [$count, $with]) {
            if ((int) ($loose[$part] ?? 0) >= $count) {
                continue;
            }
            $bill = GameData::BUILD_COST[$part] + ($with === null ? [] : [$with => 1]);
            foreach ($bill as $item => $qty) {
                if ((int) ($inv[$item] ?? 0) >= $qty) {
                    continue;
                }
                if ($inSpace) {
                    return self::toLift($raw, "down to the depot for {$item}");
                }
                $buy = Ladder::affordableBuy((string) $item, max($qty, $item === $with ? 1 : 10), (int) ($inv['credits'] ?? 0));

                return $buy === null ? null : self::act('buy', $buy['args'], "{$for}: buy {$buy['n']} {$item} for the {$part}");
            }

            return self::act('build', array_filter(['part' => $part, 'with' => $with]), "{$for}: build a {$part}" . ($with === null ? '' : " with {$with}"));
        }

        return self::act('finalize', ['name' => self::LANDER_NAME], "{$for}: every part is built — finalize it");
    }

    /**
     * Toward the Moon: down to the elevator's base, then ride to the top and
     * set down in the same tick.
     *
     * @param array<string,mixed>               $raw
     * @param array{body: string, helium3: int} $plan
     *
     * @return array{verb: string, args: array<string,mixed>, why: string, then?: array{verb: string, args: array<string,mixed>}}|null
     */
    private static function toMoon(array $raw, array $plan, int $he): ?array
    {
        $lift = Ladder::orbitElevator($raw);
        if ($lift === null || $lift['height'] < self::MOON_ALT) {
            return null; // no elevator reaches the top of the sky
        }
        $why = "helium3 {$he}/{$plan['helium3']} for {$plan['body']} — the Moon";
        $inSpace = (bool) ($raw['in_space'] ?? false) && (int) ($raw['altitude'] ?? 0) > 0;
        if ($inSpace) {
            return self::toLift($raw, "{$why}: down the elevator first, to ride it to the top");
        }
        $move = self::toLift($raw, $why);
        if ($move === null || $move['verb'] !== 'ride') {
            return $move;
        }

        return [
            'verb' => 'ride', 'args' => [],
            'why' => "{$why}: ride to the top and land_moon in the same tick",
            'then' => ['verb' => 'land_moon', 'args' => []],
        ];
    }

    /**
     * Walk to the tall elevator, or ride it.
     *
     * @param array<string,mixed> $raw
     *
     * @return array{verb: string, args: array<string,mixed>, why: string}|null
     */
    private static function toLift(array $raw, string $why): ?array
    {
        $lift = Ladder::orbitElevator($raw);
        if ($lift === null) {
            return null;
        }
        $pos = array_values((array) ($raw['position'] ?? []));
        if ((int) ($pos[0] ?? -1) !== $lift['x'] || (int) ($pos[1] ?? -1) !== $lift['y']) {
            return self::act('move', ['x' => $lift['x'], 'y' => $lift['y']], "{$why}: walk to the elevator");
        }

        return self::act('ride', [], $why);
    }

    /** @return array{verb: string, args: array<string,mixed>, why: string} */
    private static function act(string $verb, array $args, string $why): array
    {
        return ['verb' => $verb, 'args' => $args, 'why' => $why];
    }
}
