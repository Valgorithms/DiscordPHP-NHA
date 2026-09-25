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
 * Turns one {@see AgentObservation} into a single game action by asking an LLM
 * (via {@see OllamaClient}) and parsing a strict JSON reply.
 *
 * This is deliberately a consumer-application concern: it assembles the prompt
 * (strategy in {@see Playbook}, the situation digest in {@see PromptBuilder}) and
 * validates the model's choice, but it does NOT submit intents or hold game
 * transport — {@see AutoPlayer} wires it to {@see \NHA\NHA}.
 *
 * The deterministic fallback the model is anchored against — and that
 * {@see AutoPlayer} drops to when a pick is refused — lives in {@see Ladder}.
 *
 * @link https://nha.recluse.lol/AGENTS.md
 * @link https://nha.recluse.lol/docs#/agent/submit_intent_intent_post
 *
 * @since 3.0.0
 */
final class AgentBrain
{
    /**
     * The verbs the brain may pick. Kept as an alias so existing
     * `AgentBrain::VERBS` callers keep working; the catalogue lives in
     * {@see Playbook::VERBS} alongside the strategy that references it.
     *
     * @var array<string, string>
     */
    public const VERBS = Playbook::VERBS;

    /** How long the last {@see decide()} waited on the model, in ms; null before the first reply. */
    private ?int $lastLatencyMs = null;

    /**
     * @param OllamaClient $ollama The LLM client prompted for each decision.
     */
    public function __construct(private readonly OllamaClient $ollama) {}

    /**
     * How long the most recent {@see decide()} call waited on the model, in
     * milliseconds — for the per-turn record {@see AutoPlayer::lastTurn()}
     * logs. Null until a reply has arrived.
     *
     * @since 3.15.0
     */
    public function lastLatencyMs(): ?int
    {
        return $this->lastLatencyMs;
    }

    /**
     * Asks the model for the next action.
     *
     * @param AgentObservation                                                  $observation  This tick's perception.
     * @param array{verb: string, args: array, reason: string, tick: ?int}|null $lastDecision The previous turn's decision, if known —
     *                                                                                        shown to the model so it does not loop on a failed verb.
     *
     * @return PromiseInterface<array{verb: string, args: array<string,mixed>, reason: string}|null>
     *                                                                                               `null` when the model chose `wait` or returned nothing usable.
     */
    public function decide(AgentObservation $observation, ?array $lastDecision = null, string $stance = 'homestead'): PromiseInterface
    {
        $messages = [
            ['role' => 'system', 'content' => Playbook::systemPrompt($stance)],
            ['role' => 'user', 'content' => $this->summarize($observation, $lastDecision)],
        ];

        $asked = hrtime(true);

        return $this->ollama->chat($messages)->then(function (string $content) use ($asked): ?array {
            $this->lastLatencyMs = intdiv(hrtime(true) - $asked, 1_000_000);

            return self::parseDecision($content);
        });
    }

    /**
     * Parses a model reply into a validated decision, or `null`.
     *
     * Tolerates ```json fences and leading prose; requires a known `verb`.
     *
     * @return array{verb: string, args: array<string,mixed>, reason: string, step_done?: true}|null
     */
    public static function parseDecision(string $content): ?array
    {
        $json = trim($content);

        // Strip a Markdown code fence if the model added one despite format=json.
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $json) ?? $json;
        }

        // Fall back to the first {...} block if there is surrounding prose.
        if (! str_starts_with(ltrim($json), '{') && preg_match('/\{.*\}/s', $json, $m)) {
            $json = $m[0];
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        $verb = is_string($data['verb'] ?? null) ? strtolower(trim($data['verb'])) : '';
        if ($verb === '' || $verb === 'wait' || $verb === 'noop' || ! isset(self::VERBS[$verb])) {
            return null;
        }

        $args = $data['args'] ?? [];
        $args = is_array($args) ? $args : (array) $args;

        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';

        // The model reports its plan step complete ({@see Planner}); only a
        // literal `true` counts, so "false", "yes" or a stray key advance nothing.
        return ['verb' => $verb, 'args' => $args, 'reason' => $reason]
            + (($data['step_done'] ?? null) === true ? ['step_done' => true] : []);
    }

    /**
     * The model's user turn — the situation digest. Delegates to
     * {@see PromptBuilder::build()}; kept here so `AgentBrain::summarize()`
     * callers (and tests) are unaffected by the split.
     *
     * @param array{verb: string, args: array, reason: string, tick: ?int}|null $lastDecision The previous turn, if known.
     */
    public function summarize(AgentObservation $observation, ?array $lastDecision = null): string
    {
        return PromptBuilder::build($observation, $lastDecision);
    }
}
