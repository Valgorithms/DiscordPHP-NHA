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
     * Every draft is checked by {@see critique()}. A draft with problems goes
     * back to the model once, with the problems listed, and the revision is
     * used if it parses. Otherwise the draft stands. The result says which it
     * was: `review` holds the problems found, `revised` whether a revision
     * replaced the draft.
     *
     * @param array<string,mixed>|null $context Also read: `recipe_book`, the whole codex as
     *                                          item → needs, for the review.
     *
     * @return PromiseInterface<array{goal: string, steps: list<string>, why: string, review: list<string>, revised: bool}|null>
     *                                                                                                                           null when the reply is unusable.
     */
    public function plan(AgentObservation $observation, ?array $context = null): PromiseInterface
    {
        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => self::brief($observation, $context)],
        ];
        $raw = json_decode(json_encode($observation->jsonSerialize()), true);
        $raw = is_array($raw) ? $raw : [];
        $book = array_filter((array) ($context['recipe_book'] ?? $context['recipes'] ?? []), 'is_string');

        return $this->ollama->chat($messages, self::SCHEMA, 0.3)->then(function (string $content) use ($messages, $raw, $book): PromiseInterface|array|null {
            $draft = self::parsePlan($content);
            if ($draft === null) {
                return null;
            }
            $problems = self::critique($draft, $raw, $book);
            if ($problems === []) {
                return $draft + ['review' => [], 'revised' => false];
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = ['role' => 'user', 'content' => "Your plan has problems:\n- " . implode("\n- ", $problems)
                . "\nFix them and give the whole plan again. Reply with JSON only."];
            $kept = $draft + ['review' => $problems, 'revised' => false];

            return $this->ollama->chat($messages, self::SCHEMA, 0.3)->then(
                static fn(string $again): array => ($revision = self::parsePlan($again)) !== null
                    ? $revision + ['review' => $problems, 'revised' => true]
                    : $kept,
                static fn(): array => $kept,
            );
        });
    }

    /**
     * What is wrong with a plan, as lines the planner can act on — or none.
     *
     * Deliberately narrow, because a false alarm costs a pointless revision
     * and a false fix costs a pointless trip:
     *  - a BODY resource ({@see GameData::BODY_MINE}) the plan needs, named in
     *    it or in the recipe of an item it names (one level down), that the
     *    agent does not hold and that no step gets. Generic clauses such as
     *    "an electrolyte" are not checked: the game matches ingredients by
     *    physics tags, and `electrolyte` and `salt` are both real items.
     *  - steps written as commands (`mine{n=10}`) rather than milestones.
     *
     * The first live plan failed both: it combined `mars_ice` in its last step
     * with none in the hold and no step going to Mars for it.
     *
     * @param array{goal: string, steps: list<string>} $plan
     * @param array<string,mixed>                      $raw        The observation.
     * @param array<string,string>                     $recipeBook Item → the codex's needs text.
     *
     * @return list<string>
     *
     * @since 3.17.0
     */
    public static function critique(array $plan, array $raw, array $recipeBook = []): array
    {
        $inv = (array) ($raw['inventory'] ?? []);
        $steps = array_map('mb_strtolower', $plan['steps']);
        $text = mb_strtolower($plan['goal']) . "\n" . implode("\n", $steps);
        $bodyResources = array_values(array_unique(array_merge(...array_values(GameData::BODY_MINE))));

        // Body resources the plan needs → the item needing each ('' = named outright).
        $needs = [];
        foreach ($recipeBook as $item => $needText) {
            if (! self::names($text, (string) $item)) {
                continue;
            }
            $chain = [(string) $item => mb_strtolower($needText)];
            foreach ($recipeBook as $sub => $subText) {
                if ($sub !== $item && self::names($chain[(string) $item], (string) $sub)) {
                    $chain[(string) $sub] = mb_strtolower($subText);
                }
            }
            foreach ($chain as $for => $need) {
                foreach ($bodyResources as $res) {
                    if (self::names($need, $res)) {
                        $needs[$res] ??= (string) $for;
                    }
                }
            }
        }
        foreach ($bodyResources as $res) {
            if (self::names($text, $res)) {
                $needs[$res] ??= '';
            }
        }

        $problems = [];
        $here = Ladder::atBody($raw);
        foreach ($needs as $res => $for) {
            if ((int) ($inv[$res] ?? 0) > 0) {
                continue;
            }
            $bodies = GameData::minedOn($res);
            foreach ($steps as $step) {
                $namesSource = self::names($step, $res)
                    || array_filter($bodies, static fn(string $b): bool => self::names($step, $b)) !== [];
                if ($namesSource && preg_match('/\b(mine|mining|get|gather|collect|haul|fetch|obtain|acquire|harvest|buy|trade|hold|stock)\b/u', $step)) {
                    continue 2;
                }
            }
            $where = implode(' or ', $bodies);
            $problems[] = sprintf(
                '%s%s: you hold none, the depot does not sell it, and no step gets it. `mine` on %s yields it — %s.',
                $res,
                $for === '' ? '' : " (needed for {$for})",
                $where,
                $here !== null && in_array($here, $bodies, true)
                    ? "you are on {$here} now, so add a step to mine it before the step that uses it"
                    : "add steps to fly to {$where} and mine it before the step that uses it",
            );
        }

        $commands = [];
        foreach ($steps as $i => $step) {
            if (preg_match('/^\s*[a-z_]+\s*\{/u', $step)) {
                $commands[] = $i + 1;
            }
        }
        if ($commands !== []) {
            $problems[] = (count($commands) > 1 ? 'Steps ' . implode(', ', $commands) . ' are' : "Step {$commands[0]} is")
                . ' written as a command. Write each step as a milestone the player can check in its observation'
                . ' ("hold 1 battery", "be at mars"), not as an action.';
        }

        return $problems;
    }

    /**
     * Whether a plan step is done, judged from the observation, or null when
     * the step is not in a form this can check. The model's
     * `"step_done": true` is then the only signal.
     *
     * Live, the model never once reported a step done in 162 turns, and the
     * plan sat on step 1 while that step (“hold 1 aluminum”, with 4 held) was
     * already true. Checkable forms, at the start of a step:
     *  - "hold N item" / "have N item", several joined by "and", "," or "+";
     *    "a", "an" and "one" count as 1;
     *  - "be at <body>" / "be on <body>", surface or orbit; "be at earth", "be home";
     *  - "be in orbit";
     *  - "be at (x, y)", within one cell.
     *
     * @param array<string,mixed> $raw        The observation.
     * @param list<string>        $knownItems Names that are items (inventory, codex, depot, body
     *                                        resources, parts): "hold 1 position" is no milestone.
     *
     * @since 3.19.0
     */
    public static function stepMet(string $step, array $raw, array $knownItems): ?bool
    {
        $s = mb_strtolower(trim($step));
        $s = preg_replace('/^(?:then\s+|to\s+|you\s+)+/u', '', $s) ?? $s;

        if (preg_match('/^be at\s*\(\s*(-?\d+)\s*,\s*(-?\d+)\s*\)/u', $s, $m) === 1) {
            $pos = array_values((array) ($raw['position'] ?? []));
            if (! isset($pos[0], $pos[1])) {
                return null;
            }

            return abs((int) $pos[0] - (int) $m[1]) <= 1 && abs((int) $pos[1] - (int) $m[2]) <= 1;
        }

        if (preg_match('/^be in (?:earth )?orbit\b/u', $s) === 1) {
            return (bool) ($raw['in_space'] ?? false) && Ladder::atBody($raw) === null && ! Ladder::inTransit($raw);
        }

        if (preg_match('/^be (?:at|on|in) (?:the )?([a-z_]+)\b/u', $s, $m) === 1 || preg_match('/^be (home)\b/u', $s, $m) === 1) {
            $body = $m[1] === 'home' ? 'earth' : $m[1];
            if (! in_array($body, array_merge(['earth', 'moon', 'luna'], array_keys(GameData::BODY_MINE), Bodies::names($raw)), true)) {
                return null;
            }
            if (Ladder::inTransit($raw)) {
                return false;
            }

            return $body === 'earth' ? Ladder::atBody($raw) === null : Ladder::atBody($raw) === $body;
        }

        if (preg_match('/^(?:hold|have)\s+(?:at least\s+)?(.+)$/u', $s, $m) === 1) {
            if (preg_match_all('/\b(\d+|an?|one)\s+([a-z][a-z0-9_]*)/u', $m[1], $pairs, PREG_SET_ORDER) < 1) {
                return null;
            }
            $inv = (array) ($raw['inventory'] ?? []);
            $known = array_flip(array_map('strval', $knownItems));
            foreach ($pairs as [, $qty, $item]) {
                $item = GameData::canonical($item);
                if (! isset($known[$item]) && isset($known[substr($item, 0, -1)]) && str_ends_with($item, 's')) {
                    $item = substr($item, 0, -1); // "3 chips"
                }
                if (! isset($known[$item])) {
                    return null;
                }
                if ((int) ($inv[$item] ?? 0) < (ctype_digit($qty) ? (int) $qty : 1)) {
                    return false;
                }
            }

            return true;
        }

        return null;
    }

    /** Whether `$text` names `$item` as a whole word — `mars` is not named by `mars_ice`. */
    private static function names(string $text, string $item): bool
    {
        return (bool) preg_match('/(?<![a-z0-9_])' . preg_quote(mb_strtolower($item), '/') . '(?![a-z0-9_])/u', $text);
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

        $mined = [];
        foreach (GameData::BODY_MINE as $body => $yields) {
            $mined[] = "{$body}: " . implode(', ', $yields);
        }
        $digest .= "\n\nBody resources (only `mine` on that body yields them; the depot sells none): " . implode('; ', $mined) . '.';

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
            // Under a schema the model sometimes closes and reopens its quotes
            // inside ONE string — live: `land_body on mars","mine mars_ice
            // on mars","combine …` came back as a single step. Split it back.
            foreach (is_string($step) ? preg_split('/"\s*,\s*"/u', $step) : [] as $part) {
                if (($part = trim($part, " \t\n\r\0\x0B\"")) !== '') {
                    $steps[] = mb_substr($part, 0, 160);
                }
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
            - Start a step with one of these forms whenever you can; they are ticked off automatically:
              "hold N item" (several joined by "and"), "be at <body>", "be in orbit", "be at (x, y)".
            - Each step names what to make or where to go, not a vague intention ("get ready", "explore").
            - Include the sub-steps a recipe needs: if a thermal_core needs a battery, making the battery is a step.
            - Name items exactly as the recipes, Destinations and Inventory spell them (`mars_ice` is not `ice`),
              and satisfy every clause of a recipe: "2 different metals + an electrolyte" is three inputs, not two.
            - A body resource you do not hold means a trip: fly to that body and `mine` it, as steps of their own,
              before the step that uses it. The Body resources line says which body yields what.

            IF A PLAN IS ALREADY SHOWN
            - Keep it, starting from its current step, if it still fits the situation.
            - Revise it when a step is blocked or rejected, the situation changed, or it has stopped making progress.

            OUTPUT — ONE JSON object and nothing else:
            {"goal":"<one sentence>","steps":["<step 1>","<step 2>"],"why":"<one sentence: why this goal now>"}
            PROMPT;
    }
}
