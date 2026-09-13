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
 * Reads the world's own objective board (`GET /expansion`) and answers the only
 * question that actually drives strategy: *where can this agent still do
 * something useful, and can it be done with money or does it need boots on the
 * ground?*
 *
 * Until 3.9.0 the brain answered that from memory — a `colonyDoneBodies()` set
 * it wrote itself and never revisited. Memory drifts and the world does not:
 * the live board showed Mars sitting at 1 of 5 modules with four lines wide
 * open, while the agent's own flag said Mars was finished and skipped it as a
 * destination. One fetch replaces the whole guess.
 *
 * `/expansion` is the endpoint behind the site's Colonies tab. It carries every
 * body, every module, `need` / `have` / `remaining`, each funder's `contrib`,
 * and the Accord conditions — everything needed to rank the next move.
 *
 * @since 3.9.0
 */
final class Objectives
{
    /**
     * Lines the depot sells, so a module short only of these can be funded with
     * credits from anywhere via `invest` — no trip. Everything else (regolith,
     * Martian ice, perchlorate, nitrogen, acid skin, cloud acid, graphite, CO2)
     * must be mined on the body itself, which is what makes a forward base
     * worth establishing rather than just wiring money.
     *
     * Derived from {@see GameData::DEPOT_UNIT_COST} rather than listed here, so
     * it cannot drift from the price table the buys already use.
     */
    public static function isBuyable(string $resource): bool
    {
        return isset(GameData::DEPOT_UNIT_COST[$resource]);
    }

    /**
     * Every open module across every body, richest context first.
     *
     * @param array<string,mixed> $expansion a `GET /expansion` payload
     *
     * @return list<array{body: string, module: string, label: string, remaining: array<string,int>, mine: array<string,int>, funders: int, buyable: bool, complete_pct: float}>
     */
    public static function openModules(array $expansion, int $agent_id): array
    {
        $out = [];
        foreach ((array) ($expansion['bodies'] ?? []) as $body => $entry) {
            $colony = (array) (((array) $entry)['colony'] ?? []);
            foreach ((array) ($colony['modules'] ?? []) as $module) {
                $module = (array) $module;
                if (! empty($module['complete'])) {
                    continue;
                }
                $remaining = array_map('intval', (array) ($module['remaining'] ?? []));
                $remaining = array_filter($remaining, static fn(int $n): bool => $n > 0);
                if ($remaining === []) {
                    continue;
                }
                $need = array_map('intval', (array) ($module['need'] ?? []));
                $have = array_map('intval', (array) ($module['have'] ?? []));
                $needSum = array_sum($need);
                $buyable = true;
                foreach (array_keys($remaining) as $res) {
                    $buyable = $buyable && self::isBuyable((string) $res);
                }
                $out[] = [
                    'body' => (string) $body,
                    'module' => (string) ($module['module'] ?? ''),
                    'label' => (string) ($module['label'] ?? ''),
                    'remaining' => $remaining,
                    'mine' => array_map('intval', (array) (((array) ($module['contrib'] ?? []))[(string) $agent_id] ?? [])),
                    'funders' => (int) ($module['funders'] ?? 0),
                    'buyable' => $buyable,
                    'complete_pct' => $needSum > 0 ? round(array_sum($have) / $needSum * 100, 1) : 0.0,
                ];
            }
        }

        return $out;
    }

    /**
     * Bodies with open work, in the order they are worth the agent's attention:
     * closest to finished first, because a FINISHED colony is what actually
     * pays — Mars and Venus Δv drop by 5 world-wide per completed moon base,
     * and finishing any colony unlocks the warp-gate blueprint.
     *
     * @param array<string,mixed> $expansion
     *
     * @return list<string>
     */
    public static function bodiesWithOpenWork(array $expansion, int $agent_id): array
    {
        $best = [];
        foreach (self::openModules($expansion, $agent_id) as $m) {
            $body = $m['body'];
            $best[$body] = max($best[$body] ?? 0.0, $m['complete_pct']);
        }
        arsort($best);

        return array_keys($best);
    }

    /**
     * The module to put CREDITS into right now: open, short only of lines the
     * depot sells, and closest to completion. Returns `null` when every open
     * module needs something that has to be mined on a surface — which is the
     * signal that a forward base, not a bank transfer, is the way forward.
     *
     * @param array<string,mixed> $expansion
     *
     * @return array{body: string, module: string, remaining: array<string,int>}|null
     */
    public static function fundableWithCredits(array $expansion, int $agent_id): ?array
    {
        $pick = null;
        foreach (self::openModules($expansion, $agent_id) as $m) {
            if (! $m['buyable']) {
                continue;
            }
            if ($pick === null || $m['complete_pct'] > $pick['complete_pct']) {
                $pick = $m;
            }
        }

        return $pick === null
            ? null
            : ['body' => $pick['body'], 'module' => $pick['module'], 'remaining' => $pick['remaining']];
    }

    /**
     * The body worth standing on: it has open work that can ONLY be done from
     * the surface. This is the forward-base target — the one trip that unlocks
     * work no amount of credits can.
     *
     * @param array<string,mixed> $expansion
     * @param list<string>        $skip      bodies this hull cannot reach
     */
    public static function forwardBaseTarget(array $expansion, int $agent_id, array $skip = []): ?string
    {
        $pick = null;
        foreach (self::openModules($expansion, $agent_id) as $m) {
            if ($m['buyable'] || in_array($m['body'], $skip, true)) {
                continue;
            }
            if ($pick === null || $m['complete_pct'] > $pick['complete_pct']) {
                $pick = $m;
            }
        }

        return $pick === null ? null : $pick['body'];
    }

    /**
     * Bodies where this agent has NOTHING left to give — every module either
     * finished or already funded to its personal cap. The live replacement for
     * the remembered `colonyDoneBodies()` flag, which could only ever grow.
     *
     * @param array<string,mixed> $expansion
     *
     * @return list<string>
     */
    public static function bodiesWithNoWorkForUs(array $expansion, int $agent_id): array
    {
        $done = [];
        foreach ((array) ($expansion['bodies'] ?? []) as $body => $entry) {
            $colony = (array) (((array) $entry)['colony'] ?? []);
            if ((array) ($colony['modules'] ?? []) === []) {
                continue;
            }
            $cap = (int) ($colony['cap_pct_per_agent'] ?? 100);
            $anyRoom = false;
            foreach ((array) ($colony['modules'] ?? []) as $module) {
                $module = (array) $module;
                if (! empty($module['complete'])) {
                    continue;
                }
                foreach ((array) ($module['remaining'] ?? []) as $res => $short) {
                    if ((int) $short < 1) {
                        continue;
                    }
                    $ceiling = (int) floor((int) (((array) ($module['need'] ?? []))[$res] ?? 0) * $cap / 100);
                    $mine = (int) (((array) (((array) ($module['contrib'] ?? []))[(string) $agent_id] ?? []))[$res] ?? 0);
                    if ($mine < $ceiling) {
                        $anyRoom = true;
                        break 2;
                    }
                }
            }
            if (! $anyRoom) {
                $done[] = (string) $body;
            }
        }

        return $done;
    }

    /**
     * A compact, factual digest of the board for the model — used when the
     * deterministic ladder has run out of ideas and the agent is looping, so
     * the LLM is choosing from what the world actually says rather than from
     * whatever it last inferred.
     *
     * @param array<string,mixed> $expansion
     */
    public static function digest(array $expansion, int $agent_id, int $limit = 8): string
    {
        $modules = self::openModules($expansion, $agent_id);
        if ($modules === []) {
            return 'No open colony work anywhere.';
        }
        usort($modules, static fn(array $a, array $b): int => $b['complete_pct'] <=> $a['complete_pct']);

        $lines = [];
        foreach (array_slice($modules, 0, $limit) as $m) {
            $bill = [];
            foreach ($m['remaining'] as $res => $short) {
                $bill[] = "{$short} {$res}" . (self::isBuyable((string) $res) ? '' : '*');
            }
            $mine = $m['mine'] === [] ? 'nothing yet' : 'you gave ' . implode(', ', array_map(
                static fn($r, $n): string => "{$n} {$r}",
                array_keys($m['mine']),
                $m['mine'],
            ));
            $lines[] = sprintf(
                '- %s/%s (%s) %.0f%% done, needs %s; %d funders; %s',
                $m['body'],
                $m['module'],
                $m['buyable'] ? 'creditable' : 'surface work',
                $m['complete_pct'],
                implode(' + ', $bill),
                $m['funders'],
                $mine,
            );
        }
        $accord = (array) ($expansion['accord'] ?? []);

        return "WORLD OBJECTIVE BOARD (GET /expansion — the Colonies tab):\n"
            . implode("\n", $lines)
            . "\n(* = must be mined on that body; everything else the depot sells, so `invest{body,module,credits}` works from anywhere.)"
            . sprintf(
                "\nAccord: mars_terraformed=%s venus_held=%s moon_base=%s",
                var_export((bool) ($accord['mars_terraformed'] ?? false), true),
                var_export((bool) ($accord['venus_held'] ?? false), true),
                var_export((bool) ($accord['moon_base'] ?? false), true),
            );
    }
}
