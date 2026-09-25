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

use NHA\Parts\AgentObservation;
use React\Promise\PromiseInterface;

/**
 * The strategist: asks the model, now and then, for ONE goal and the few
 * ordered steps that reach it — a plan the turn-by-turn {@see AgentBrain} then
 * works through, one verb at a time.
 *
 * The turn model was called once per turn for one verb, with nothing carried
 * between turns but the last verb. 81% of its turns were `mine` or `move`,
 * because the only way forward was a chain that no rule encodes and that no
 * one-verb caller with no memory can hold: fly to Mars, mine `mars_ice`, make a
 * battery and a `thermal_core`, fly to Triton, found the colony.
 *
 * It never acts. {@see AutoPlayer} stores what it returns
 * ({@see \NHA\State\PlanStateTrait}), shows it in every turn prompt, and asks
 * again when the plan is finished, stale or not working. Every action still
 * passes the same gates, so a bad plan costs turns, not the hold.
 *
 * @since 3.16.0
 */
final class Planner
{
    /** The most steps a plan may have; more is a to-do list, not a plan. */
    public const MAX_STEPS = 6;

    /** What the model must return — enforced by the server when it supports schemas. */
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'goal' => ['type' => 'string'],
            'steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => self::MAX_STEPS],
            'why' => ['type' => 'string'],
        ],
        'required' => ['goal', 'steps', 'why'],
    ];

    /**
     * @param OllamaClient $ollama The same local model the turns use; the
     *                             plan call is simply rarer and longer.
     */
    public function __construct(private readonly OllamaClient $ollama) {}

    /**
     * Asks for a plan.
     *
     * @param array<string,mixed>|null $context The turn context {@see AutoPlayer} builds for
     *                                          {@see AgentBrain::decide()}, plus `objectives`
     *                                          (the world's board) and the current `plan`, if any.
     *
     * @return PromiseInterface<array{goal: string, steps: list<string>, why: string}|null>
     *                                                                                      null when the reply is unusable.
     */
    public function plan(AgentObservation $observation, ?array $context = null): PromiseInterface
    {
        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => self::brief($observation, $context)],
        ];

        return $this->ollama->chat($messages, self::SCHEMA, 0.3)
            ->then(static fn(string $content): ?array => self::parsePlan($content));
    }

    /**
     * The situation, as the turn model sees it, plus the world's objective
     * board — with the closing line asking for a plan instead of an action.
     *
     * @param array<string,mixed>|null $context
     */
    public static function brief(AgentObservation $observation, ?array $context = null): string
    {
        $context = ($context ?? []) + ['closing' => 'Set the plan. Reply with JSON only.'];
        $digest = PromptBuilder::build($observation, $context);
        $board = trim((string) ($context['objectives'] ?? ''));

        return $board === '' ? $digest : $digest . "\n\nThe world's objective board:\n" . $board;
    }

    /**
     * Parses and validates a planner reply.
     *
     * Tolerates a Markdown fence and surrounding prose. Steps that are not
     * non-empty strings are dropped, each is cut to 160 characters, and at most
     * {@see MAX_STEPS} are kept; a plan with no goal or no steps is null.
     *
     * @return array{goal: string, steps: list<string>, why: string}|null
     */
    public static function parsePlan(string $content): ?array
    {
        $json = trim($content);
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $json) ?? $json;
        }
        if (! str_starts_with(ltrim($json), '{') && preg_match('/\{.*\}/s', $json, $m)) {
            $json = $m[0];
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        $goal = is_string($data['goal'] ?? null) ? trim($data['goal']) : '';
        $steps = [];
        foreach ((array) ($data['steps'] ?? []) as $step) {
            if (is_string($step) && ($step = trim($step)) !== '') {
                $steps[] = mb_substr($step, 0, 160);
            }
        }
        if ($goal === '' || $steps === []) {
            return null;
        }

        return [
            'goal' => mb_substr($goal, 0, 200),
            'steps' => array_slice($steps, 0, self::MAX_STEPS),
            'why' => is_string($data['why'] ?? null) ? mb_substr(trim($data['why']), 0, 240) : '',
        ];
    }

    /** The strategist's instructions. */
    public static function systemPrompt(): string
    {
        $max = self::MAX_STEPS;

        return <<<PROMPT
            You are the STRATEGIST for an agent in NHA, a persistent multi-agent world. You do not choose this turn's
            action. A separate player picks one action per turn and reads your plan every turn; your job is to give it
            a goal worth reaching and the order to reach it in.

            THE GAME, IN BRIEF
            - Score comes from inventing (new combine sets), building, and the Expansion: reaching other bodies,
              founding and funding their colonies. A finished colony is done — there is nothing left to fund there.
            - Getting somewhere new usually needs gear first. The Destinations list says, per body, what it needs in
              hold and whether you can reach it; the recipes say how that gear is made, from what.
            - Money is rarely the obstacle for a rich agent. Missing gear, closed windows and unreachable bodies are.

            SET ONE GOAL
            - The most valuable thing the agent can actually get done next, within about an hour of play.
            - Prefer the goal the rest of the board is waiting on: an unfounded colony, gear that opens a new body.
            - Never a goal the BLOCKED or REJECTIONS lines rule out, and never one needing an item or recipe that
              is not in the observation, the Destinations list, or the recipes.

            THEN 2–{$max} STEPS, IN ORDER
            - Each step is a milestone the player can check from its own observation: an item held
              ("hold 1 thermal_core"), a place reached ("be at mars"), a thing done ("found the triton colony").
            - Each step names what to make or where to go, not a vague intention ("get ready", "explore").
            - Include the sub-steps a recipe needs: if a thermal_core needs a battery, making the battery is a step.
            - Name items exactly as the recipes, Destinations and Inventory spell them (`mars_ice` is not `ice`),
              and satisfy every clause of a recipe: "2 different metals + an electrolyte" is three inputs, not two.

            IF A PLAN IS ALREADY SHOWN
            - Keep it, starting from its current step, if it still fits the situation.
            - Revise it when a step is blocked or rejected, the situation changed, or it has stopped making progress.

            OUTPUT — ONE JSON object and nothing else:
            {"goal":"<one sentence>","steps":["<step 1>","<step 2>"],"why":"<one sentence: why this goal now>"}
            PROMPT;
    }
}
