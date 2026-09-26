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
 * {@see \NHA\StateStore} slice: the strategist's plan per agent — one goal, a
 * few ordered steps, and which step the agent is on — set by
 * {@see \NHA\Brain\Planner} and advanced when the turn model reports a step
 * done.
 *
 * The turn model decides one verb at a time and carried nothing from one turn
 * to the next, so a chain no rule encodes (fly to Mars, mine mars_ice, make a
 * battery and a thermal_core, fly to Triton, found the colony) could not be
 * held by anything. This is where it is held.
 *
 * Relies on the host's `array $data` and `save()`.
 *
 * @since 3.16.0
 */
trait PlanStateTrait
{
    /**
     * A plan older than this is revised even if nothing went wrong. At roughly
     * half a tick a second, about 40 minutes.
     */
    private const PLAN_REFRESH_TICKS = 1200;

    /**
     * The least gap between two planner calls, whatever triggered them. Each
     * call costs a model round-trip the next turn may wait behind, and a stall
     * that one plan could not fix will not be fixed by a plan every turn.
     */
    private const PLAN_RETRY_TICKS = 150;

    /**
     * The least gap between two step advances. A model that marks every turn
     * "step done" would otherwise race through the plan in a minute.
     */
    private const PLAN_STEP_MIN_TICKS = 20;

    /**
     * How long a plan gets before a stall may replace it. Loop breaks fire on
     * roughly one turn in five in ordinary play, so without this a stall
     * replanned every {@see PLAN_RETRY_TICKS}: live, twice in 400 ticks, each
     * time the same goal, each time back to step 1.
     *
     * @since 3.17.0
     */
    private const PLAN_STALL_MIN_TICKS = 450;

    /**
     * Adopts a plan, starting at its first step — unless it is the plan
     * already being worked (same goal, same steps), whose progress is kept.
     * Asked to review its own plan, the model often hands it back unchanged,
     * and that must not send the agent back to step 1.
     *
     * @param list<string> $steps
     * @param string       $where Where the agent was when the plan was made ({@see planDue()}).
     */
    public function setPlan(int $agent_id, string $goal, array $steps, string $why, int $tick, string $where): void
    {
        $steps = array_values($steps);
        $prior = $this->plan($agent_id);
        $same = $prior !== null && $prior['goal'] === $goal && $prior['steps'] === $steps;

        $this->data['agent_plan'][(string) $agent_id] = [
            'goal' => $goal,
            'steps' => $steps,
            'why' => $why,
            'step' => $same ? $prior['step'] : 0,
            'set_at' => $tick,
            'stepped_at' => $same ? $prior['stepped_at'] : $tick,
            'where' => $where,
        ];
        $this->save();
    }

    /**
     * The agent's current plan, or null. `step` is the index of the step being
     * worked on; it equals `count(steps)` once the plan is finished.
     *
     * @return array{goal: string, steps: list<string>, why: string, step: int, set_at: int, stepped_at: int, where: string}|null
     */
    public function plan(int $agent_id): ?array
    {
        $p = $this->data['agent_plan'][(string) $agent_id] ?? null;
        if (! is_array($p) || ! is_string($p['goal'] ?? null) || ! is_array($p['steps'] ?? null)) {
            return null;
        }

        return [
            'goal' => (string) $p['goal'],
            'steps' => array_values(array_map('strval', $p['steps'])),
            'why' => (string) ($p['why'] ?? ''),
            'step' => (int) ($p['step'] ?? 0),
            'set_at' => (int) ($p['set_at'] ?? 0),
            'stepped_at' => (int) ($p['stepped_at'] ?? 0),
            'where' => (string) ($p['where'] ?? ''),
        ];
    }

    /** Whether every step of the agent's plan has been marked done. */
    public function planFinished(int $agent_id): bool
    {
        $p = $this->plan($agent_id);

        return $p !== null && $p['step'] >= count($p['steps']);
    }

    /**
     * Moves the agent's plan on to its next step.
     *
     * @param bool $verified The step was checked against the observation
     *                       ({@see \NHA\Brain\Planner::stepMet()}), not just
     *                       claimed by the model, so the debounce does not
     *                       apply: several steps already done can pass in one
     *                       turn.
     *
     * @return int|null The new step index, or null when there is no unfinished
     *                  plan or the last advance was too recent.
     */
    public function advancePlan(int $agent_id, int $tick, bool $verified = false): ?int
    {
        $p = $this->plan($agent_id);
        if ($p === null || $p['step'] >= count($p['steps'])
            || (! $verified && $tick - $p['stepped_at'] < self::PLAN_STEP_MIN_TICKS)
        ) {
            return null;
        }
        $key = (string) $agent_id;
        $this->data['agent_plan'][$key]['step'] = $p['step'] + 1;
        $this->data['agent_plan'][$key]['stepped_at'] = $tick;
        $this->save();

        return $p['step'] + 1;
    }

    /** Records that the planner was asked, whether or not it answered. */
    public function recordPlanAttempt(int $agent_id, int $tick): void
    {
        $this->data['agent_plan_attempt'][(string) $agent_id] = $tick;
        $this->save();
    }

    /**
     * Whether the planner should be asked this turn.
     *
     * Never within {@see PLAN_RETRY_TICKS} of the last attempt, answered or
     * not. Otherwise yes when there is no plan, it is finished, it is older
     * than {@see PLAN_REFRESH_TICKS}, the agent has reached or left a body
     * since it was made, or the caller reports a stall and the plan has had
     * {@see PLAN_STALL_MIN_TICKS} to work.
     *
     * @param bool   $stalled Loop breaks piling up, a hold that overran, or the same proposal blocked repeatedly.
     * @param string $where   Where the agent is now: a body name, or the home system.
     */
    public function planDue(int $agent_id, int $tick, bool $stalled, string $where): bool
    {
        $attempt = $this->data['agent_plan_attempt'][(string) $agent_id] ?? null;
        if (is_numeric($attempt) && $tick - (int) $attempt < self::PLAN_RETRY_TICKS) {
            return false;
        }
        $p = $this->plan($agent_id);
        if ($p === null || $p['step'] >= count($p['steps'])) {
            return true;
        }

        $age = $tick - $p['set_at'];

        return ($stalled && $age >= self::PLAN_STALL_MIN_TICKS)
            || $p['where'] !== $where
            || $age >= self::PLAN_REFRESH_TICKS;
    }
}
