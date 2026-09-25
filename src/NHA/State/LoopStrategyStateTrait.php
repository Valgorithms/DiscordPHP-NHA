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

namespace NHA\State;

/**
 * {@see \NHA\StateStore} slice: the autoplay loop's strategic memory —
 * the forced-objective rotation and its cooldown (armed when
 * {@see \NHA\Brain\AutoPlayer::detectLoop()} catches the agent looping), the
 * persisted {@see \NHA\Brain\Stance}, and the inventor-points trend that tells
 * the fallback whether research is still paying.
 *
 * Relies on the host's `array $data` and `save()`.
 *
 * @since 3.1.32
 */
trait LoopStrategyStateTrait
{
    /** A forced objective sticks for this many world ticks after a loop break. */
    private const FORCED_OBJECTIVE_TTL_TICKS = 45;

    /** After a loop break, do not break again for this many ticks — let it play out. */
    private const LOOP_BREAK_COOLDOWN_TICKS = 24;

    /**
     * Objectives cycled through, in order, each time the agent is caught
     * looping. `expand` (progress the Solar Accord mission — reach a body, build
     * a colony/extractor/terraform, or head for the elevator) is tried first.
     */
    public const OBJECTIVE_ROTATION = ['expand', 'wealth', 'build', 'research'];

    /** Research counts as "paying" for this long after the last inventor-point gain. */
    private const RESEARCH_PAYING_WINDOW = 900;

    /**
     * Advances the agent's forced-objective cursor one step through
     * {@see self::OBJECTIVE_ROTATION} and returns the new objective. Called by
     * {@see \NHA\Brain\AutoPlayer} when it detects the agent is stuck in a loop,
     * so each successive loop break tries a *different* kind of goal.
     *
     * @since 3.1.12
     */
    public function bumpForcedObjective(int $agent_id, int $tick): string
    {
        $key = (string) $agent_id;
        $prev = $this->data['agent_forced_objective'][$key] ?? null;
        $idx = (is_array($prev) ? (int) ($prev['idx'] ?? -1) : -1);
        $idx = ($idx + 1) % count(self::OBJECTIVE_ROTATION);
        $objective = self::OBJECTIVE_ROTATION[$idx];

        $this->data['agent_forced_objective'][$key] = ['idx' => $idx, 'objective' => $objective, 'tick' => $tick];
        $this->save();

        return $objective;
    }

    /**
     * The objective {@see bumpForcedObjective()} WOULD return next, without
     * advancing the cursor or arming the cooldown — so the loop guard can name
     * the objective in the brain prompt and only commit it once the turn
     * actually applies it.
     *
     * @since 3.1.21
     */
    public function peekNextForcedObjective(int $agent_id): string
    {
        $prev = $this->data['agent_forced_objective'][(string) $agent_id] ?? null;
        $idx = (is_array($prev) ? (int) ($prev['idx'] ?? -1) : -1);

        return self::OBJECTIVE_ROTATION[($idx + 1) % count(self::OBJECTIVE_ROTATION)];
    }

    /**
     * The objective forced by the most recent loop break, or `null` once it has
     * aged out ({@see self::FORCED_OBJECTIVE_TTL_TICKS} ticks) — after which the
     * agent is back on the normal ladder.
     *
     * @since 3.1.12
     */
    public function getForcedObjective(int $agent_id, int $tick): ?string
    {
        $entry = $this->data['agent_forced_objective'][(string) $agent_id] ?? null;
        if (! is_array($entry)) {
            return null;
        }
        if ($tick > 0 && ($tick - (int) ($entry['tick'] ?? 0)) > self::FORCED_OBJECTIVE_TTL_TICKS) {
            return null;
        }

        return ((string) ($entry['objective'] ?? '')) ?: null;
    }

    /**
     * Whether a loop break happened too recently to break again — the forced
     * objective (and the brain turns after it) need a few ticks to actually
     * change the situation before the loop detector is allowed to fire once more.
     * Without this the loop-break moves themselves keep the "no productive
     * action" window full and it thrashes every turn.
     *
     * @since 3.1.14
     */
    public function loopBreakCooldownActive(int $agent_id, int $tick): bool
    {
        $entry = $this->data['agent_forced_objective'][(string) $agent_id] ?? null;
        if (! is_array($entry) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) ($entry['tick'] ?? 0)) < self::LOOP_BREAK_COOLDOWN_TICKS;
    }

    /**
     * The agent's persisted strategic stance and the tick it last changed
     * (`{stance, tick}`). Defaults to `expansionist` (the mission stance) at tick 0.
     *
     * @return array{stance: string, tick: int}
     *
     * @since 3.1.23
     */
    public function getStance(int $agent_id): array
    {
        $e = $this->data['agent_stance'][(string) $agent_id] ?? null;

        return [
            'stance' => is_array($e) ? (string) ($e['stance'] ?? 'expansionist') : 'expansionist',
            'tick' => is_array($e) ? (int) ($e['tick'] ?? 0) : 0,
        ];
    }

    /** A rejected `depart` is not retried for this many world ticks. */
    private const DEPART_RETRY_COOLDOWN_TICKS = 12;

    /**
     * Records a rejected `depart`. Always arms a short retry cooldown so an
     * observe/intent window race does not spam the same failing `depart` every
     * tick. When `$permanent` (a thrust-to-weight / capability rejection rather
     * than a closed window), the destination is also parked in the unreachable
     * set for the rest of the run — the engine only reports a TWR shortfall on
     * rejection, so this is the only way to learn the ship cannot make that hop.
     *
     * @since 3.2.34
     */
    public function recordDepartRejection(int $agent_id, string $dest, int $tick, bool $permanent): void
    {
        $key = (string) $agent_id;
        $this->data['agent_depart_block'][$key] = ['dest' => $dest, 'tick' => $tick];
        if ($permanent && $dest !== '') {
            $u = array_values(array_filter((array) ($this->data['agent_depart_unreachable'][$key] ?? []), 'is_string'));
            if (! in_array($dest, $u, true)) {
                $u[] = $dest;
            }
            $this->data['agent_depart_unreachable'][$key] = array_slice($u, -8);
        }
        $this->save();
    }

    /** True while the last rejected `depart` is still on its retry cooldown. */
    public function departRetryCooldownActive(int $agent_id, int $tick): bool
    {
        $e = $this->data['agent_depart_block'][(string) $agent_id] ?? null;
        if (! is_array($e) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) ($e['tick'] ?? 0)) < self::DEPART_RETRY_COOLDOWN_TICKS;
    }

    /**
     * Destinations a `depart` was permanently (TWR / capability) rejected for
     * this run — {@see \NHA\Brain\Ladder::departTarget()} skips them.
     *
     * @return list<string>
     */
    public function departUnreachable(int $agent_id): array
    {
        return array_values(array_filter((array) ($this->data['agent_depart_unreachable'][(string) $agent_id] ?? []), 'is_string'));
    }

    /**
     * Forgets every `depart` verdict for an agent — the retry cooldown and the
     * unreachable set. Called when a new hull is `finalize`d so the fresh ship
     * is not pre-judged by the dead end it replaced.
     *
     * @since 3.2.36
     */
    public function clearDepartRejections(int $agent_id): void
    {
        $key = (string) $agent_id;
        if (! isset($this->data['agent_depart_block'][$key]) && ! isset($this->data['agent_depart_unreachable'][$key])) {
            return;
        }
        unset($this->data['agent_depart_block'][$key], $this->data['agent_depart_unreachable'][$key]);
        $this->save();
    }

    /**
     * A co-op call for help on one body is not repeated for this many ticks
     * (~1h at 2s/tick). The ask is aimed at the other LLM agents playing the
     * world, who read world chat — so it has to be rare enough not to be
     * noise, and repeatable enough that an agent coming online later still
     * hears it.
     */
    private const COLONY_CALL_COOLDOWN_TICKS = 1800;

    /**
     * Records that this agent has broadcast a co-op call for help finishing
     * `$body`'s colony.
     *
     * @since 3.5.0
     */
    public function recordColonyCall(int $agent_id, string $body, int $tick): void
    {
        $this->data['agent_colony_call'][(string) $agent_id][$body] = $tick;
        $this->save();
    }

    /**
     * The vault call is the mechanic, not chatter — but it is still chatter if
     * it repeats. Long, because six symbols do not arrive in a hurry and the
     * agents who hold them are on their own launch windows.
     *
     * @since 3.12.0
     */
    private const VAULT_CALL_COOLDOWN_TICKS = 400;

    /** Records a vault seal call. */
    public function recordVaultCall(int $agent_id, int $tick): void
    {
        $this->data['agent_vault_call'][(string) $agent_id] = $tick;
        $this->save();
    }

    /**
     * Whether a vault call is still on cooldown. The `$said` fingerprint is
     * what was last offered and asked for: when that CHANGES — a new symbol
     * read, or a gap closed by someone answering — the news is worth repeating
     * immediately, because it is new news.
     */
    public function vaultCallCooldownActive(int $agent_id, int $tick, string $said = ''): bool
    {
        $key = (string) $agent_id;
        $last = $this->data['agent_vault_call'][$key] ?? null;
        if (! is_numeric($last) || $tick <= 0) {
            return false;
        }
        if ($said !== '' && ($this->data['agent_vault_said'][$key] ?? null) !== $said) {
            return false;
        }

        return ($tick - (int) $last) < self::VAULT_CALL_COOLDOWN_TICKS;
    }

    /** Remembers the exact line said, so only NEW news jumps the cooldown. */
    public function recordVaultSaid(int $agent_id, string $said): void
    {
        $this->data['agent_vault_said'][(string) $agent_id] = $said;
        $this->save();
    }

    /** True while this agent's last call for help on `$body` is still fresh. */
    public function colonyCallCooldownActive(int $agent_id, string $body, int $tick): bool
    {
        $last = $this->data['agent_colony_call'][(string) $agent_id][$body] ?? null;
        if (! is_numeric($last) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) $last) < self::COLONY_CALL_COOLDOWN_TICKS;
    }

    /** A `ride` is not auto-repeated for this many world ticks. */
    private const RIDE_COOLDOWN_TICKS = 12;

    /**
     * Records a `ride` (the no-fuel elevator toggle). {@see \NHA\Brain\Ladder}'s
     * station-keep rung (v3.2.35) rides down once altitude decays under the
     * 300 depart floor, trusting a following on-ground rung to ride straight
     * back up — a rare bounce every ~150 ticks by that design's decay
     * estimate. Live near Earth, orbital decay turned out to cross that floor
     * within a single autoplay turn, so the pair fired on almost every turn:
     * ride down, ride up, ride down, forever, with zero turns actually spent
     * holding (topping fuel/shield/acid_skin) or ever sitting in the depart
     * band long enough for an opening window to find it there. This cooldown
     * forces a few real hold turns between bounces.
     *
     * @since 3.4.10
     */
    public function recordRide(int $agent_id, int $tick): void
    {
        $this->data['agent_last_ride'][(string) $agent_id] = $tick;
        $this->save();
    }

    /** True while the last recorded `ride` is still on its cooldown. */
    public function rideCooldownActive(int $agent_id, int $tick): bool
    {
        $last = $this->data['agent_last_ride'][(string) $agent_id] ?? null;
        if (! is_numeric($last) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) $last) < self::RIDE_COOLDOWN_TICKS;
    }

    /**
     * "This agent has funded its full colony share on `<body>` and is on the
     * way home." A latch, because the observation's `expansion.at_body` /
     * `location` glitch to empty for the odd tick — without it the return
     * state machine goes dormant on those ticks and the agent drifts back into
     * mine / ride churn. Cleared once it is actually home ({@see clearGoingHome()}).
     *
     * @since 3.4.8
     */
    public function setGoingHome(int $agent_id, string $body): void
    {
        $key = (string) $agent_id;
        if (($this->data['agent_going_home'][$key] ?? null) === $body) {
            return;
        }
        $this->data['agent_going_home'][$key] = $body;
        $this->save();
    }

    /** The body an agent is heading home FROM, or `null`. @since 3.4.8 */
    public function goingHome(int $agent_id): ?string
    {
        $b = $this->data['agent_going_home'][(string) $agent_id] ?? null;

        return is_string($b) && $b !== '' ? $b : null;
    }

    /** Drops the heading-home latch (the agent is back at Earth / in transit to it). @since 3.4.8 */
    public function clearGoingHome(int $agent_id): void
    {
        $key = (string) $agent_id;
        if (! isset($this->data['agent_going_home'][$key])) {
            return;
        }
        unset($this->data['agent_going_home'][$key]);
        $this->save();
    }

    /**
     * Bodies this agent has already funded its full colony share on (or whose
     * colony is complete) — so `depart` stops re-offering the SAME body every
     * cycle and the mission actually spreads across deimos / phobos / mars /
     * venus instead of camping on the first one reached. Pure state; the
     * skip-list semantics live in the caller ({@see \NHA\Brain\Ladder::departTarget()}).
     *
     * @since 3.4.9
     */
    public function recordColonyDone(int $agent_id, string $body): void
    {
        $key = (string) $agent_id;
        $done = array_values(array_filter((array) ($this->data['agent_colony_done'][$key] ?? []), 'is_string'));
        if (in_array($body, $done, true)) {
            return;
        }
        $done[] = $body;
        $this->data['agent_colony_done'][$key] = $done;
        $this->save();
    }

    /** @return list<string> @since 3.4.9 */
    public function colonyDoneBodies(int $agent_id): array
    {
        return array_values(array_filter((array) ($this->data['agent_colony_done'][(string) $agent_id] ?? []), 'is_string'));
    }

    /** Forgets a body is colony-done (e.g. a new module opened up there). @since 3.4.9 */
    public function clearColonyDone(int $agent_id, string $body): void
    {
        $key = (string) $agent_id;
        $done = array_values(array_filter((array) ($this->data['agent_colony_done'][$key] ?? []), static fn($b): bool => $b !== $body));
        if ($done === (array) ($this->data['agent_colony_done'][$key] ?? [])) {
            return;
        }
        $this->data['agent_colony_done'][$key] = $done;
        $this->save();
    }

    /**
     * Records the agent's stance. `$tick` is only stamped when the stance
     * actually changes, so it marks the last *switch* for the dwell timer.
     *
     * @since 3.1.23
     */
    public function setStance(int $agent_id, string $stance, int $tick): void
    {
        $key = (string) $agent_id;
        $prev = $this->getStance($agent_id);
        if ($prev['stance'] === $stance) {
            return;
        }

        $this->data['agent_stance'][$key] = ['stance' => $stance, 'tick' => $tick];
        $this->save();
    }

    /**
     * Records the agent's current lifetime `inventor_points` and reports whether
     * research is paying *right now* — the score rose this turn, or rose within
     * the last {@see self::RESEARCH_PAYING_WINDOW} seconds.
     *
     * Inventor points never drop (a rejected invention only refunds ingredients),
     * so an absolute `> 0` test stays true forever once an agent has invented
     * anything. The autoplay fallback needs the recent trend instead: keep
     * speculating while discoveries are still landing, fall to infrastructure
     * once they dry up. The first sighting only sets the baseline and returns
     * `false` (no trend yet).
     *
     * @since 3.1.9
     */
    public function noteInventorPoints(int $agent_id, int $points): bool
    {
        $key = (string) $agent_id;
        $prev = $this->data['agent_inventor_points'][$key] ?? null;
        $now = time();

        if (! is_array($prev)) {
            $this->data['agent_inventor_points'][$key] = ['value' => $points, 'rose_at' => 0];
            $this->save();

            return false;
        }

        $roseNow = $points > (int) ($prev['value'] ?? 0);
        $roseAt = $roseNow ? $now : (int) ($prev['rose_at'] ?? 0);

        if ($roseNow || $points !== (int) ($prev['value'] ?? 0)) {
            $this->data['agent_inventor_points'][$key] = ['value' => $points, 'rose_at' => $roseAt];
            $this->save();
        }

        return $roseNow || ($roseAt > 0 && ($now - $roseAt) < self::RESEARCH_PAYING_WINDOW);
    }

    /**
     * Remembers how much fuel the ship actually needs for a transfer, solved
     * from the engine's Δv rejection by
     * {@see \NHA\Brain\Ladder::fuelTargetFromRejection()}.
     *
     * Stored rather than recomputed every turn because the derivation needs
     * the fuel load AT THE MOMENT OF THE REJECTION: buying more fuel changes
     * `L` and would skew the mass that falls out of the curve. Capture once,
     * then spend against it.
     *
     * @since 3.5.5
     */
    public function recordFuelGoal(int $agent_id, int $units): void
    {
        $this->data['agent_fuel_goal'][(string) $agent_id] = $units;
        $this->save();
    }

    /**
     * How long a warp gate's refusal of our body cargo is trusted before the
     * agent may probe it again. One refused `depart` per window of this is a
     * cheap way to notice the haul has shrunk; one per turn was the stall.
     *
     * @since 3.14.0
     */
    private const GATE_CARGO_REFUSAL_TICKS = 600;

    /**
     * Records that a warp gate refused this agent's body cargo.
     *
     * Learned from the engine's own rejection text rather than predicted from a
     * list of "exotic" resources: the rulebook names fourteen today, Season 8
     * added six of them, and a table here would be the next thing to go stale.
     *
     * @since 3.14.0
     */
    public function recordGateCargoRefusal(int $agent_id, int $tick): void
    {
        $this->data['agent_gate_cargo_refused'][(string) $agent_id] = $tick;
        $this->save();
    }

    /** The tick of the newest recorded gate cargo refusal, or 0. */
    public function gateCargoRefusedAt(int $agent_id): int
    {
        return (int) ($this->data['agent_gate_cargo_refused'][(string) $agent_id] ?? 0);
    }

    /** Whether a gate has refused our cargo recently enough to believe it still would. */
    public function gateRefusesCargo(int $agent_id, int $tick): bool
    {
        $last = $this->data['agent_gate_cargo_refused'][(string) $agent_id] ?? null;
        if (! is_numeric($last) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) $last) < self::GATE_CARGO_REFUSAL_TICKS;
    }

    /**
     * When the current orbital hold began, and how long it EXPECTED to wait —
     * the soonest window among the destinations actually worth reaching, read
     * off the live observation at the moment the hold started. Recorded once
     * per hold, so a window that opens and shuts without a departure shows up
     * as a hold that has overrun its own estimate.
     *
     * @return array{tick:int,expect:int}
     *
     * @since 3.14.0
     */
    public function holdStarted(int $agent_id, int $tick, int $expect): array
    {
        $key = (string) $agent_id;
        $h = $this->data['agent_hold_started'][$key] ?? null;
        if (! is_array($h)) {
            $h = ['tick' => $tick, 'expect' => max(0, $expect)];
            $this->data['agent_hold_started'][$key] = $h;
            $this->save();
        }

        return ['tick' => (int) $h['tick'], 'expect' => (int) $h['expect']];
    }

    /** The agent is not holding — forget the hold that was. */
    public function clearHoldStarted(int $agent_id): void
    {
        if (isset($this->data['agent_hold_started'][(string) $agent_id])) {
            unset($this->data['agent_hold_started'][(string) $agent_id]);
            $this->save();
        }
    }

    /**
     * Turns spent holding in the depart band with the trip home unaffordable.
     *
     * The return leg has no ladder rung and no way to earn: in orbit the agent
     * cannot mine, cannot reach the depot, and cannot ride down without leaving
     * the band. Live, agent #142285 sat in Venus orbit on 82 units of the ~440
     * its hull needs for a 130 Δv return, holding on a window that was never
     * the binding constraint. Counting the holds is what tells a legitimate
     * wait-for-the-window from being stranded, and the engine documents the
     * escape: `distress` is an emergency recall to Earth orbit.
     *
     * @since 3.10.0
     */
    public function countHomeHold(int $agent_id): int
    {
        $key = (string) $agent_id;
        $n = (int) ($this->data['agent_home_holds'][$key] ?? 0) + 1;
        $this->data['agent_home_holds'][$key] = $n;
        $this->save();

        return $n;
    }

    /** A turn that moved the trip home along means the agent is not stranded. */
    public function clearHomeHolds(int $agent_id): void
    {
        if (isset($this->data['agent_home_holds'][(string) $agent_id])) {
            unset($this->data['agent_home_holds'][(string) $agent_id]);
            $this->save();
        }
    }

    /** The stored Δv-derived fuel goal, or 0 when none has been learned yet. */
    public function fuelGoal(int $agent_id): int
    {
        return (int) ($this->data['agent_fuel_goal'][(string) $agent_id] ?? 0);
    }

    /**
     * Rebuild generations that ended with the hull still unable to reach
     * anywhere. A `finalize` clears every depart verdict so the new hull gets a
     * fair trial — which is right, but on its own it is an unbounded cycle:
     * finalize → clear → depart → rejected → stranded → rebuild → finalize.
     * Counting the generations puts a floor under it.
     *
     * @since 3.8.0
     */
    public function recordRebuildGeneration(int $agent_id): int
    {
        $key = (string) $agent_id;
        $n = (int) ($this->data['agent_rebuild_gen'][$key] ?? 0) + 1;
        $this->data['agent_rebuild_gen'][$key] = $n;
        $this->save();

        return $n;
    }

    /** How many rebuilds in a row have failed to produce a usable hull. */
    public function rebuildGenerations(int $agent_id): int
    {
        return (int) ($this->data['agent_rebuild_gen'][(string) $agent_id] ?? 0);
    }

    /** A hull that actually departed proves the rebuild worked — start counting over. */
    public function clearRebuildGenerations(int $agent_id): void
    {
        unset($this->data['agent_rebuild_gen'][(string) $agent_id]);
        $this->save();
    }

    /** Ticks between two `invest` pushes at the same board. @since 3.8.0 */
    private const INVEST_COOLDOWN_TICKS = 200;

    /**
     * Rate-limits the endgame `invest`. Any decision that can be REFUSED and
     * still leave the observation unchanged is a loop waiting to happen — the
     * engine may want materials rather than money for the line we are aiming
     * at — and this one sits after the loop-break override, so it cannot rely
     * on that to save it.
     *
     * @since 3.8.0
     */
    public function recordInvest(int $agent_id, string $body, int $tick): void
    {
        $this->data['agent_last_invest'][(string) $agent_id][$body] = $tick;
        $this->save();
    }

    /** True while the last `invest` at this board is still on its cooldown. */
    public function investCooldownActive(int $agent_id, string $body, int $tick): bool
    {
        $last = $this->data['agent_last_invest'][(string) $agent_id][$body] ?? null;
        if (! is_numeric($last) || $tick <= 0) {
            return false;
        }

        return ($tick - (int) $last) < self::INVEST_COOLDOWN_TICKS;
    }

    /** Ticks between two `GET /expansion` objective-board refreshes. @since 3.9.0 */
    private const OBJECTIVE_REFRESH_TICKS = 300;

    /**
     * Whether the world objective board is due a refresh.
     *
     * It is a whole-world payload, so it is polled on a cadence rather than
     * every turn — but it must be polled at all. The brain used to decide
     * where to fly from a set it wrote itself and never revisited, and the
     * live board showed Mars at 1 of 5 modules with four lines open while that
     * set said Mars was finished.
     *
     * @since 3.9.0
     */
    public function objectivesDue(int $agent_id, int $tick): bool
    {
        $last = $this->data['agent_objectives_at'][(string) $agent_id] ?? null;

        return ! is_numeric($last) || ($tick - (int) $last) >= self::OBJECTIVE_REFRESH_TICKS;
    }

    /** Stores the digest of the last objective board, and when it was read. @since 3.9.0 */
    public function recordObjectives(int $agent_id, int $tick, string $digest): void
    {
        $key = (string) $agent_id;
        $this->data['agent_objectives_at'][$key] = $tick;
        $this->data['agent_objectives'][$key] = $digest;
        $this->save();
    }

    /** The last objective-board digest, for the prompt. @since 3.9.0 */
    public function objectives(int $agent_id): string
    {
        return (string) ($this->data['agent_objectives'][(string) $agent_id] ?? '');
    }

    /**
     * Replaces the colony-done set with what the world actually says.
     *
     * Derived, not remembered: {@see \NHA\Brain\Objectives::bodiesWithNoWorkForUs()}
     * recomputes it from live `contrib` against the per-agent cap every refresh,
     * so a body can leave the set as well as enter it — a module opening up
     * puts it back on the map without anyone noticing by hand.
     *
     * @param list<string> $bodies
     *
     * @since 3.9.0
     */
    public function setColonyDone(int $agent_id, array $bodies): void
    {
        $this->data['agent_colony_done'][(string) $agent_id] = array_values(array_unique(array_filter($bodies, 'is_string')));
        $this->save();
    }

    /**
     * Loop breaks that have fired without the situation resolving.
     *
     * The rotation ({@see OBJECTIVE_ROTATION}) handles an ordinary rut. What it
     * cannot handle is a rut the rotation itself is part of — every objective
     * tried, none of them applicable, round and round. Counting the breaks is
     * what tells the difference, and past a full cycle the agent asks the model
     * to pick a course from the world's objective board instead of waiting for
     * someone to change the code.
     *
     * @since 3.9.0
     */
    public function countLoopBreak(int $agent_id): int
    {
        $key = (string) $agent_id;
        $n = (int) ($this->data['agent_loop_breaks'][$key] ?? 0) + 1;
        $this->data['agent_loop_breaks'][$key] = $n;
        $this->save();

        return $n;
    }

    /** How many loop breaks have fired without the agent getting clear. */
    public function loopBreaks(int $agent_id): int
    {
        return (int) ($this->data['agent_loop_breaks'][(string) $agent_id] ?? 0);
    }

    /** A turn that was not a loop break means the agent is moving again. */
    public function clearLoopBreaks(int $agent_id): void
    {
        if (isset($this->data['agent_loop_breaks'][(string) $agent_id])) {
            unset($this->data['agent_loop_breaks'][(string) $agent_id]);
            $this->save();
        }
    }

    /** Depot-buyable lines an open colony board still wants. @since 3.9.0 */
    public function recordBoardWants(int $agent_id, array $wants): void
    {
        $this->data['agent_board_wants'][(string) $agent_id] = array_map('intval', $wants);
        $this->save();
    }

    /** @return array<string,int> */
    public function boardWants(int $agent_id): array
    {
        return array_map('intval', (array) ($this->data['agent_board_wants'][(string) $agent_id] ?? []));
    }
}
