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

/**
 * Builds the model's *user* turn — a compact, deterministic digest of one
 * {@see AgentObservation} plus the carry-over context ({@see AutoPlayer} feeds
 * last turn's decision, its outcome, the recent-history window, the combine
 * bookkeeping and any forced loop-break objective).
 *
 * The *system* turn (strategy) is {@see Playbook::systemPrompt()}; the two are
 * assembled in {@see AgentBrain::decide()}. Split out of {@see AgentBrain} so the
 * ~300 lines of string assembly here stay clear of the decision/parse logic.
 *
 * Pure formatting — no game transport, no side effects. The one collaborator is
 * {@see Ladder::suggestion()}, whose pick is surfaced as the "SUGGESTED next
 * action" line to anchor a weak model.
 *
 * @since 3.1.30
 */
final class PromptBuilder
{
    /**
     * Compact, deterministic digest of an observation for the model's user turn.
     * Surfaces every field the {@see Playbook} decision ladder needs — score,
     * holdings, economy/social boards, threats, the expansion board — while
     * staying terse.
     *
     * @param array{verb: string, args: array, reason: string, tick: ?int}|null $lastDecision The previous turn, if known.
     */
    public static function build(AgentObservation $observation, ?array $lastDecision = null): string
    {
        // The live payload nests `stdClass`; normalise the whole thing to arrays
        // once so every field access below is uniform.
        $raw = json_decode(json_encode($observation->jsonSerialize()), true);
        $raw = is_array($raw) ? $raw : [];
        // See the world the LADDER sees. {@see AutoPlayer} enriches its copy of
        // the observation with bookkeeping the engine does not publish (which
        // colonies are done, the learned fuel goal, the Guild's verdicts); the
        // model was shown the bare observation, so its "SUGGESTED next action"
        // was computed from a blinder state than the ladder's own decisions —
        // and happily suggested flying to a colony finished weeks ago.
        foreach ((array) ($lastDecision['hints'] ?? []) as $k => $v) {
            $raw[(string) $k] = $v;
        }
        $skip = array_values(array_map('strval', (array) ($lastDecision['depart_skip'] ?? [])));
        $colonyDone = array_values(array_map('strval', (array) ($lastDecision['colony_done'] ?? [])));
        $stance = (string) ($lastDecision['stance'] ?? 'homestead');
        $get = static fn(string $key, $default = null) => $raw[$key] ?? $default;
        $arr = static fn(string $key): array => (array) ($raw[$key] ?? []);
        $count = static fn(string $key): int => count((array) ($raw[$key] ?? []));

        $pos = $observation->getPosition();
        $posText = $pos ? "({$pos['x']}, {$pos['y']})" : 'unknown';
        $hp = $observation->getHp();
        $hpMax = $observation->getMaxHp();
        $tick = $get('tick');
        $downedUntil = (int) ($get('downed_until') ?? 0);
        $inventory = $arr('inventory');

        $lines = [];
        $lines[] = sprintf('You are NHA agent #%d at tick %s.', $observation->agentId, $tick ?? '?');
        $lines[] = sprintf(
            'Position: %s. HP: %s/%s.%s Altitude: %s. In space: %s.',
            $posText,
            $hp === null ? '?' : (string) $hp,
            (string) $hpMax,
            $downedUntil > (int) ($tick ?? 0) ? " DOWNED until tick {$downedUntil}." : '',
            (string) ($get('altitude') ?? 0),
            $get('in_space') ? 'yes' : 'no',
        );
        $lines[] = sprintf(
            'Score: inventor_points %s, credits %s.',
            (string) ($get('inventor_points') ?? 0),
            (string) ($inventory['credits'] ?? 0),
        );

        $expansion = $get('expansion');
        if (is_array($expansion)) {
            $lines[] = sprintf(
                'Era: %s (%s).',
                (string) ($expansion['era'] ?? '?'),
                (string) ($expansion['location'] ?? '?'),
            );
            $atBody = $expansion['at_body'] ?? null;
            $windows = (array) ($expansion['windows'] ?? []);
            $open = [];
            foreach ($windows as $body => $w) {
                $open[] = is_array($w) && ($w['open'] ?? false)
                    ? "{$body} OPEN"
                    : "{$body} in " . (is_array($w) ? (string) ($w['opens_in'] ?? '?') : '?');
            }
            // `how` was cut at 400 characters — and the engine's gear recipes
            // ("PACK BEFORE YOU FLY: heat_shield (superalloy+composite), …")
            // start well past that, so the one paragraph on how to become able
            // to go somewhere was always the part the model never saw.
            $lines[] = 'Expansion: at_body=' . ($atBody ?? 'none')
                . ($open === [] ? '' : '; transit windows: ' . implode(', ', $open))
                . (isset($expansion['how']) ? "\n  how: " . mb_substr((string) $expansion['how'], 0, 1800) : '');

            $lines = array_merge($lines, self::destinationLines(
                $raw,
                $colonyDone,
                (bool) ($lastDecision['gate_refuses_cargo'] ?? false),
                array_values(array_map('strval', (array) ($lastDecision['unfounded'] ?? []))),
                array_values(array_diff($skip, $colonyDone)),
            ));
        }

        // How to make the gear standing between the agent and anywhere new —
        // the codex's own words, including one level of sub-recipe (a
        // thermal_core needs a battery, and a battery has a recipe too).
        $recipes = array_filter((array) ($lastDecision['recipes'] ?? []), 'is_string');
        if ($recipes !== []) {
            $lines[] = 'Recipes for gear you are missing (from the world codex — combine the inputs to make them):';
            foreach ($recipes as $item => $needs) {
                $lines[] = "  {$item} = " . mb_substr($needs, 0, 220);
            }
        }

        if ($inventory) {
            $lines[] = 'Inventory: ' . self::pairs($inventory, 14) . '.';
        }

        // Holdings that gate finalize / deploy / space / combat decisions.
        $holdings = [];
        if ($lp = $count('loose_parts')) {
            $holdings[] = "loose_parts x{$lp} (finalize-able)";
        }
        if ($veh = $arr('vehicles')) {
            $holdings[] = 'vehicles: ' . implode(', ', array_map(
                static fn($v): string => is_array($v) ? (string) ($v['name'] ?? 'vehicle') : 'vehicle',
                array_slice($veh, 0, 4),
            ));
        }
        if ($w = array_filter($arr('weapons'))) {
            $holdings[] = 'weapons: ' . self::pairs($w, 4);
        }
        if ($m = array_filter($arr('medicines'))) {
            $holdings[] = 'medicines: ' . self::pairs($m, 4);
        }
        foreach (['buff', 'toxin'] as $k) {
            if (is_array($get($k))) {
                $holdings[] = $k . ' active';
            }
        }
        if ($holdings) {
            $lines[] = 'Holdings: ' . implode('; ', $holdings) . '.';
        }

        $lines[] = 'Nearby deposits: ' . self::rows(
            $arr('nearby_deposits'),
            6,
            static fn(array $d): string => sprintf('%s@(%s,%s) x%s', $d['resource'] ?? '?', $d['x'] ?? '?', $d['y'] ?? '?', $d['amount'] ?? '?'),
        );

        $lines[] = 'Nearby agents: ' . self::rows(
            $arr('nearby_agents'),
            8,
            static fn(array $a): string => sprintf(
                '#%s %s@(%s,%s) hp%s d%s%s',
                $a['id'] ?? '?',
                $a['name'] ?? '?',
                $a['x'] ?? '?',
                $a['y'] ?? '?',
                $a['hp'] ?? '?',
                $a['dist'] ?? '?',
                ($a['wanted'] ?? false) ? ' WANTED' : '',
            ),
        );

        $lines[] = 'Nearby plants: ' . ($count('nearby_plants') ?: '0')
            . '. Structures: ' . ($count('nearby_structures') ?: '0')
            . '. Loot piles: ' . ($count('loot') ?: '0')
            . '. Artifacts: ' . ($count('artifacts') ?: '0')
            . '. Asteroids: ' . ($count('asteroids') ?: '0') . '.';

        if ($elevators = $arr('elevators')) {
            $e = (array) $elevators[0];
            $lines[] = sprintf(
                'Nearest elevator: (%s,%s) height %s, dist %s%s.',
                $e['x'] ?? '?',
                $e['y'] ?? '?',
                $e['height'] ?? '?',
                $e['dist'] ?? '?',
                count($elevators) > 1 ? ' (+' . (count($elevators) - 1) . ' more)' : '',
            );
        }

        $econ = array_filter([
            $count('contracts') ? 'open contracts ' . $count('contracts') : null,
            $count('trade_offers') ? 'trade offers ' . $count('trade_offers') : null,
            $count('bounties') ? 'bounties ' . $count('bounties') : null,
            ($ot = (int) ($get('orders_total') ?? 0)) ? "your open orders {$ot}" : null,
        ]);
        if ($econ) {
            $lines[] = 'Economy/social: ' . implode(', ', $econ) . '.';
        }

        // Threat awareness.
        $threat = array_filter([
            $count('alerts') ? $count('alerts') . ' recent alert(s) where you were the victim' : null,
            $get('last_robbed_by') ? 'last robbed by #' . $get('last_robbed_by') : null,
        ]);
        if ($threat) {
            $lines[] = 'Threats: ' . implode('; ', $threat) . '.';
        }

        if ($messages = array_slice($arr('messages'), 0, 3)) {
            $chat = array_map(
                static fn($m): string => is_array($m)
                    ? sprintf('[%s] %s', $m['sender_name'] ?? $m['from'] ?? '?', mb_substr((string) ($m['text'] ?? ''), 0, 120))
                    : '',
                $messages,
            );
            $lines[] = 'Recent chat: ' . implode(' | ', array_filter($chat));
        }

        if ($notices = $arr('system_notices')) {
            $first = is_array($notices[0] ?? null) ? ($notices[0]['text'] ?? '') : ($notices[0] ?? '');
            $lines[] = 'System notice: ' . mb_substr((string) $first, 0, 400);
        }

        if ($lastDecision !== null && ($lastDecision['verb'] ?? '') !== '') {
            $lastVerb = (string) $lastDecision['verb'];
            $lastArgs = $lastDecision['args'] ?? [];
            $harvested = in_array($lastVerb, ['mine', 'chop', 'gather'], true);

            // Outcome of last turn's queued intent, if AutoPlayer polled it.
            $outcome = is_array($lastDecision['outcome'] ?? null) ? $lastDecision['outcome'] : null;
            $status = $outcome !== null ? (string) ($outcome['status'] ?? '') : '';
            $result = $outcome !== null ? trim((string) ($outcome['result'] ?? '')) : '';

            if ($status === 'rejected') {
                $tail = 'It was REJECTED' . ($result !== '' ? ": \"{$result}\"" : '')
                    . ". Do NOT try that again — pick a DIFFERENT verb or different args.";
            } elseif ($status === 'applied') {
                $craftTail = $lastVerb === 'combine'
                    ? ' A combine only SCORES if it invented something new; if inventor_points did not rise, that set is'
                        . ' spent — never submit it again.'
                    : " Move on — do not repeat {$lastVerb} unless it is still clearly the right call.";
                $tail = 'It APPLIED' . ($result !== '' ? ": \"{$result}\"" : '') . '.'
                    . ($harvested
                        ? ' You just harvested — this turn do NOT harvest again; craft, build, or move on.'
                        : $craftTail);
            } else {
                // Still pending / unknown — the pre-outcome guidance.
                $tail = 'It is queued (result not in yet). '
                    . ($harvested
                        ? 'You just harvested — this turn do NOT harvest again; craft, build, or move on.'
                        : "Do not repeat {$lastVerb} unless it is still clearly the right call (e.g. still moving toward a target).");
            }

            // Only the verb, args and the server's own outcome are echoed back —
            // never the previous turn's free-text `reason`. A model paraphrase
            // like "I have 9 wood" that landed in one turn's reason would
            // otherwise be replayed verbatim every subsequent turn and read as
            // fact, even after the real inventory (below) has moved on.
            // "You chose combine" was a lie on every overridden turn: the
            // model had proposed `finalize`, a gate swapped it, and the prompt
            // credited the swap to the model — which then proposed finalize
            // again, never knowing it had been stopped.
            $proposed = is_array($lastDecision['proposed'] ?? null) ? $lastDecision['proposed'] : null;
            $did = $lastVerb . ($lastArgs === [] ? '' : ' ' . json_encode($lastArgs, JSON_UNESCAPED_SLASHES));
            $lines[] = $proposed !== null && ($proposed['verb'] ?? '') !== ''
                ? sprintf(
                    'Last turn: you proposed %s%s, but %s replaced it with %s. %s',
                    (string) $proposed['verb'],
                    (array) ($proposed['args'] ?? []) === [] ? '' : ' ' . json_encode($proposed['args'], JSON_UNESCAPED_SLASHES),
                    ($lastDecision['source'] ?? '') === 'loop' ? 'the loop breaker' : 'a safety check',
                    $did,
                    $tail,
                )
                : sprintf('Last turn: you chose %s. %s', $did, $tail);
        }

        // The engine's recent refusals, in its own words, across turns — not
        // just last turn's. A single applied turn in between used to erase a
        // rejection from view, which is how the same refusal was re-earned.
        $rejections = array_filter((array) ($lastDecision['rejections'] ?? []), 'is_array');
        if ($rejections !== []) {
            $lines[] = 'Recent REJECTIONS (the engine\'s own reasons — do not repeat one unless the reason no longer applies):';
            foreach ($rejections as $r) {
                $lines[] = sprintf('  %s, %d ticks ago: "%s"', (string) ($r['verb'] ?? '?'), (int) ($r['ago'] ?? 0), (string) ($r['result'] ?? ''));
            }
        }

        // Our own gates' refusals. They never reach the engine, so the feed
        // above never carries them; without this block a blocked proposal was
        // indistinguishable from one that had never been made.
        $vetoes = array_filter((array) ($lastDecision['vetoes'] ?? []), 'is_array');
        if ($vetoes !== []) {
            $lines[] = 'BLOCKED before reaching the game (your proposal was replaced — do not propose it again unless the reason no longer applies):';
            foreach ($vetoes as $v) {
                $args = (array) ($v['args'] ?? []);
                $count = (int) ($v['count'] ?? 1);
                $lines[] = sprintf(
                    '  %s%s, %d ticks ago%s: "%s"',
                    (string) ($v['verb'] ?? '?'),
                    $args === [] ? '' : ' ' . json_encode($args, JSON_UNESCAPED_SLASHES),
                    (int) ($v['ago'] ?? 0),
                    $count > 1 ? " (blocked {$count} times)" : '',
                    (string) ($v['why'] ?? ''),
                );
            }
        }

        // The orbital hold ran past its own estimate: the window it was waiting
        // for came and went with the agent still here. Say so plainly — this is
        // the stall the code has no rung for, and the reason the model is being
        // asked at all.
        if (($overrun = (int) ($lastDecision['hold_overrun'] ?? 0)) > 0) {
            $lines[] = "HOLD FAILED: you have held in orbit for {$overrun} ticks and the window you were waiting for has "
                . 'passed without a departure. Waiting longer will not fix it. Use the Destinations list above to find '
                . 'what is actually blocking you, and act on that instead.';
        }

        // Loop guard fired: the loop runner has detected repetition and forced a
        // new objective for this turn. Tell the model plainly.
        $forcedObjective = (string) ($lastDecision['forced_objective'] ?? '');
        if ($forcedObjective !== '') {
            $lines[] = sprintf(
                'LOOP DETECTED (%s). You are stuck. This turn your objective is **%s** — do that and nothing resembling the repeated action.',
                (string) ($lastDecision['loop'] ?? 'repetition'),
                $forcedObjective,
            );
        }

        // ESCALATION. The objective rotation has cycled without freeing the
        // agent, which means the rotation is part of the rut. Hand the model
        // the world's own objective board (`GET /expansion`) and let it choose
        // the course — the point being that a stall nobody has written code for
        // can still resolve itself.
        $brief = (string) ($lastDecision['brief'] ?? '');
        if ($brief !== '') {
            $lines[] = 'STUCK — CHOOSE A NEW COURSE. ' . $brief;
            $board = (string) ($lastDecision['objectives'] ?? '');
            if ($board !== '') {
                $lines[] = $board;
            }
        }

        // Rolling history + a deterministic suggestion. A small local model
        // loops badly on the 7-rung ladder alone; showing it what it already
        // did and one concrete recommended move keeps it productive.
        $recent = is_array($lastDecision['recent'] ?? null) ? $lastDecision['recent'] : [];
        $tried = [];

        // The full set of combine signatures already submitted this run (survives
        // the 8-turn recent window), fed in by AutoPlayer from durable state.
        foreach (is_array($lastDecision['tried_combines'] ?? null) ? $lastDecision['tried_combines'] : [] as $sig) {
            if (is_string($sig) && $sig !== '') {
                $tried[$sig] = true;
            }
        }

        if ($recent !== []) {
            $hist = [];
            foreach ($recent as $r) {
                $v = (string) ($r['verb'] ?? '');
                if ($v === '') {
                    continue;
                }
                $a = (array) ($r['args'] ?? []);
                if ($v === 'combine') {
                    $ing = array_keys((array) ($a['ingredients'] ?? []));
                    sort($ing);
                    if ($ing !== []) {
                        $tried[implode('+', $ing)] = true;
                    }
                }
                $hist[] = $v . ($a === [] ? '' : self::compactArgs($a));
            }
            if ($hist !== []) {
                $lines[] = 'Recent turns (oldest→newest): ' . implode(' → ', array_slice($hist, -8));
            }
        }

        if ($tried !== []) {
            $lines[] = 'combine sets already submitted this session (do NOT resubmit — a repeat mints nothing): '
                . implode(', ', array_slice(array_keys($tried), -40)) . '.';
        }

        $dead = array_values(array_filter(
            is_array($lastDecision['dead_combines'] ?? null) ? $lastDecision['dead_combines'] : [],
            'is_string',
        ));
        if ($dead !== []) {
            $lines[] = 'combine sets the Inventors\' Guild has REJECTED — proven to make nothing, NEVER pick these again: '
                . implode(', ', array_slice($dead, -40)) . '.';
        }

        // Combine sets the whole world has ALREADY invented (from /rules) that
        // you could make right now with what you hold — these mint no points, so
        // skip them; anything else is potentially novel.
        $knownCombines = array_values(array_filter(
            is_array($lastDecision['known_combines'] ?? null) ? $lastDecision['known_combines'] : [],
            'is_string',
        ));
        $invNames = array_keys(array_filter(
            (array) ($raw['inventory'] ?? []),
            static fn($qty, $k): bool => $k !== 'credits' && is_numeric($qty) && $qty > 0,
            ARRAY_FILTER_USE_BOTH,
        ));
        $makeableKnown = array_values(array_filter(
            $knownCombines,
            static fn(string $sig): bool => array_diff(explode('+', $sig), $invNames) === [],
        ));
        if ($makeableKnown !== []) {
            $lines[] = 'Already-invented combine sets you could make now (mint nothing — do NOT pick these): '
                . implode(', ', array_slice($makeableKnown, 0, 20)) . '.';
        }

        if ($invNames !== []) {
            $lines[] = 'You may ONLY combine/build from what you hold (qty ≥ 1): ' . implode(', ', $invNames)
                . '. Anything else — iron, chip, composite, … at qty 0 — will be rejected.';
        }
        if (($raw['inventory']['composite_material'] ?? 0) > 0 && ($raw['inventory']['composite'] ?? 0) === 0) {
            $lines[] = 'NOTE: you hold `composite_material`, NOT `composite`. A `construct` tower needs `composite` '
                . '(aluminum + carbon) — you cannot build one yet, so do not keep trying.';
        }

        // The strategist's plan, just ahead of the ladder's suggestion: the
        // goal first, then the tactical default. Without it the model was
        // asked for one verb with no idea what the verb was for.
        if (is_array($lastDecision['plan'] ?? null)) {
            $lines = array_merge($lines, self::planLines($lastDecision['plan'], (int) ($tick ?? 0)));
        }

        // With the ladder's real stance and skip list. Without them this ran as
        // `homestead` with an empty skip list — a different agent than the one
        // actually deciding — so the line the model is told to follow "unless
        // you clearly see something better" could point at a finished colony.
        if ($suggestion = Ladder::suggestion($raw, $tried, array_fill_keys($knownCombines, true), true, $stance, $skip)) {
            $lines[] = sprintf(
                'SUGGESTED next action: %s%s — %s. Do this unless you clearly see something better.',
                $suggestion['verb'],
                $suggestion['args'] === [] ? '' : ' ' . json_encode($suggestion['args'], JSON_UNESCAPED_SLASHES),
                $suggestion['why'],
            );
        }

        $lines[] = (string) ($lastDecision['closing'] ?? 'Choose one action. Reply with JSON only.');

        return implode("\n", $lines);
    }

    /**
     * The plan block: the goal, each step marked done (✓), current (→) or to
     * come, and how to report the current step finished.
     *
     * @param array{goal?: string, steps?: list<string>, step?: int, set_at?: int} $plan
     *
     * @return list<string>
     *
     * @since 3.16.0
     */
    private static function planLines(array $plan, int $tick): array
    {
        $steps = array_values(array_filter((array) ($plan['steps'] ?? []), 'is_string'));
        $goal = (string) ($plan['goal'] ?? '');
        if ($goal === '' || $steps === []) {
            return [];
        }
        $at = (int) ($plan['step'] ?? 0);
        $age = $tick > 0 && isset($plan['set_at']) ? max(0, $tick - (int) $plan['set_at']) : null;

        $lines = ['YOUR PLAN' . ($age === null ? '' : " (set {$age} ticks ago)") . ": {$goal}"];
        foreach ($steps as $i => $step) {
            $lines[] = sprintf('  %s %d. %s', $i < $at ? '✓' : ($i === $at ? '→' : ' '), $i + 1, $step);
        }
        $lines[] = $at < count($steps)
            ? 'Work toward the → step. Steps starting "hold" or "be at" are ticked off automatically; for any other,'
                . ' add "step_done": true to your reply when your observation shows it complete.'
            : 'Every step is done; a new plan is on its way. Until then, keep the agent productive.';

        return $lines;
    }

    /**
     * One line per destination: its Δv, whether its window is open (or a warp
     * gate spans it), the arrival gear it consumes and whether the agent holds
     * each piece, whether it has work left for this agent, and the engine's own
     * blockers in the engine's own words.
     *
     * This is the block that lets the model reason about "I cannot get there"
     * rather than guess at it. It was shown `titan OPEN` and nothing else, so it
     * could not know Titan needs a thermal_core it lacked, nor that Deimos had
     * been finished for weeks — and proposed flying there for days.
     *
     * The "not in Earth orbit" blocker is dropped: it is about where the agent
     * stands right now, which the position line already says, and it appears
     * on every row, drowning the blockers that are about the destination.
     *
     * @param array<string,mixed> $raw
     * @param list<string>        $colonyDone
     * @param list<string>        $unfounded   bodies whose colony is published but not laid
     * @param list<string>        $unreachable bodies the engine refused for good (3.21.0: Triton,
     *                                         which needs more Δv than any ship can make, read
     *                                         "colony NOT FOUNDED — someone must land there" and
     *                                         the plan chased it for as long as that line stood)
     *
     * @return list<string>
     *
     * @since 3.14.0
     */
    private static function destinationLines(array $raw, array $colonyDone, bool $gateRefusesCargo, array $unfounded = [], array $unreachable = []): array
    {
        $bodies = Bodies::all($raw);
        if ($bodies === []) {
            return [];
        }
        $inv = (array) ($raw['inventory'] ?? []);
        $out = ['Destinations (depart from Earth orbit, altitude 300-600 — requirements are the engine\'s own):'];
        foreach ($bodies as $body => $b) {
            $gear = [];
            foreach ($b['needs_in_hold'] as $item) {
                $gear[] = ((int) ($inv[$item] ?? 0)) > 0 ? "{$item} (have)" : "{$item} (MISSING)";
            }
            $route = $b['open'] ? 'window OPEN' : "window opens in {$b['opens_in']} ticks";
            if (Bodies::gateLinked($raw, (string) $body)) {
                $route .= '; a warp gate joins it (ignores the window, but carries body cargo only in warp_container crates)';
            }
            $blockers = array_values(array_filter(
                $b['blockers'],
                static fn(string $x): bool => ! str_contains(strtolower($x), 'not in earth orbit'),
            ));
            $out[] = sprintf(
                '  %s: Δv %d; %s; arrival gear: %s; %s.%s',
                $body,
                $b['dv_need'],
                $route,
                $gear === [] ? 'none' : implode(', ', $gear),
                in_array((string) $body, $colonyDone, true)
                    ? 'your share there is DONE — nothing to fund'
                    : (in_array((string) $body, $unreachable, true)
                        ? 'UNREACHABLE — the engine refused every ship you have for good (Triton needs more Δv than any ship can make); do not plan a trip there'
                        : (in_array((string) $body, $unfounded, true)
                        ? "colony NOT FOUNDED — someone must land there and lay it with construct{shape:'colony',body:'{$body}'} before anyone can invest in it"
                        : 'has colony work for you')),
                $blockers === [] ? '' : ' Engine: ' . implode(' / ', $blockers),
            );
        }
        if ($gateRefusesCargo) {
            $out[] = '  A warp gate recently REFUSED your body cargo (see Recent REJECTIONS). While a window is shut a gated '
                . 'depart will be refused again — wait for the window and fly the long way, or change the haul.';
        }
        $out[] = '  Where you cannot reach a body with work left, you can still fund its colony from anywhere with '
            . 'invest{body,module,credits} once it is FOUNDED — credits buy the industrial lines; body resources must be mined on site.';

        return $out;
    }

    /**
     * Compact `{"k":v,...}` rendering for the recent-turns line — short enough to
     * list eight of them without bloating the prompt.
     *
     * @param array<string,mixed> $args
     */
    private static function compactArgs(array $args): string
    {
        $flat = [];
        foreach ($args as $k => $v) {
            $flat[] = $k . '=' . (is_array($v) ? implode('/', array_map('strval', array_keys($v))) : (string) $v);
        }

        return '{' . implode(',', $flat) . '}';
    }

    /** @param array<string,mixed> $map */
    private static function pairs(array $map, int $limit): string
    {
        $out = [];
        foreach ($map as $k => $v) {
            if (count($out) >= $limit) {
                $out[] = '…';
                break;
            }
            $out[] = "{$k}:" . (is_scalar($v) ? (string) $v : json_encode($v));
        }

        return implode(', ', $out);
    }

    /**
     * @param array<int,mixed>        $rows
     * @param callable(array): string $format
     */
    private static function rows(array $rows, int $limit, callable $format): string
    {
        if ($rows === []) {
            return 'none';
        }

        $shown = array_slice($rows, 0, $limit);
        $parts = array_map(
            static fn($row): string => $format(is_array($row) ? $row : (array) $row),
            $shown,
        );
        $extra = count($rows) - count($shown);

        return implode('; ', $parts) . ($extra > 0 ? " (+{$extra} more)" : '');
    }
}
