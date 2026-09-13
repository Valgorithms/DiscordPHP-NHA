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
 * The strategic stance an agent is playing right now. It is a soft steer, not a
 * script: it re-flavours the system prompt {@see Playbook::systemPrompt()} and
 * lightly reorders the deterministic ladder {@see Ladder::suggestion()}, but
 * the survive / defend / arm rungs and the anti-patterns always apply.
 *
 * There is exactly one goal — the **Solar Accord** (Mars terraformed, Venus
 * held, a Moon base) — and stances exist only to serve it:
 *
 *  - `expansionist` — the mission stance, and the answer for every non-combat
 *                     turn: gear a ship on Earth, fly, colonise, terraform.
 *                     Arming, stockpiling and banking a surplus are tactics the
 *                     expansionist ladder already does in service of the flight.
 *  - `quartermaster` — the cupboard is bare and every build is blocked on it.
 *                     Restocking IS the turn. Live, the agent spent a whole
 *                     evening alternating sell-a-lot / buy-a-little-metal /
 *                     build-one-part / broke-again, finishing a part an hour.
 *  - `researcher`   — restocked, and holding raws deep enough to cut a fresh
 *                     `combine` from. Catch up on the research the resupply
 *                     just paid for — the drive recipe is undocumented and
 *                     inventor points are a real way through — then fly.
 *  - `aggressive`   — you are being attacked and can fight back. Survival is a
 *                     precondition for the mission, so this pre-empts it; then
 *                     it hands straight back to `expansionist`.
 *  - `homestead` / `capitalist` — legacy. "Dig in, do not fly" and "credits are
 *                     the game" are not mission strategies, so {@see rank()}
 *                     never chooses them; the cases remain only for a stored
 *                     value mid-dwell and the ladder's contract tactic.
 *
 * @since 3.1.23
 */
enum Stance: string
{
    case Homestead = 'homestead';
    case Aggressive = 'aggressive';
    case Capitalist = 'capitalist';
    case Expansionist = 'expansionist';
    case Quartermaster = 'quartermaster';
    case Researcher = 'researcher';

    /**
     * The lines every build actually consumes: a flyer part costs 8-10 `metal`
     * and 1-2 `crystal`, so these two decide whether the agent can do anything
     * at all on the ground.
     */
    public const RESTOCK_LINES = ['metal', 'crystal'];

    /**
     * Drop below this on any {@see RESTOCK_LINES} line and restocking becomes
     * the whole turn — a handful of parts' worth, low enough that the agent is
     * genuinely blocked rather than merely thrifty.
     */
    public const RESTOCK_ENTER = 60;

    /**
     * …and it stays in `quartermaster` until EVERY line is back to here. The
     * gap between the two marks is the hysteresis: leaving at the entry
     * threshold hands back a cupboard that is bare again one build later,
     * which is the sell / buy / build / broke cycle this stance exists to end.
     * Matches {@see Ladder::MINE_RESEEK_FLOOR}, the mining baseline.
     */
    public const RESTOCK_EXIT = 250;

    /** Do not flip stance more often than this (world ticks) unless combat forces it. */
    public const MIN_DWELL_TICKS = 40;

    /**
     * Picks the stance for this turn from the observation, holding the current
     * one unless a switch is clearly warranted (hysteresis). `aggressive` (a
     * fight) and `expansionist` (progress toward the Accord) both pre-empt the
     * dwell timer — neither the fight nor the mission waits.
     *
     * @param array<string,mixed> $raw          The normalised observation.
     * @param string              $current      The stance in force (its `value`).
     * @param int                 $lastSwitchAt World tick the stance last changed.
     */
    public static function pick(array $raw, string $current, int $lastSwitchAt, bool $researchPaying = false): self
    {
        $now = (int) ($raw['tick'] ?? 0);
        $currentStance = self::tryFrom($current) ?? self::Expansionist;
        $want = self::rank($raw, $currentStance, $researchPaying);

        if ($want === $currentStance) {
            return $currentStance;
        }
        // The resupply chain (quartermaster -> researcher -> the mission) is a
        // sequence, not a preference: each leg ends when its own work is done,
        // so none of them waits on the dwell timer either.
        if ($want === self::Aggressive || $want === self::Expansionist
            || $want === self::Quartermaster || $want === self::Researcher
            || ($now - $lastSwitchAt) >= self::MIN_DWELL_TICKS
        ) {
            return $want;
        }

        return $currentStance;
    }

    /** Whether any {@see RESTOCK_LINES} line has fallen under {@see RESTOCK_ENTER}. */
    public static function understocked(array $raw): bool
    {
        $inv = (array) ($raw['inventory'] ?? []);
        foreach (self::RESTOCK_LINES as $res) {
            if ((int) ($inv[$res] ?? 0) < self::RESTOCK_ENTER) {
                return true;
            }
        }

        return false;
    }

    /** Whether EVERY {@see RESTOCK_LINES} line is back to {@see RESTOCK_EXIT}. */
    public static function restocked(array $raw): bool
    {
        $inv = (array) ($raw['inventory'] ?? []);
        foreach (self::RESTOCK_LINES as $res) {
            if ((int) ($inv[$res] ?? 0) < self::RESTOCK_EXIT) {
                return false;
            }
        }

        return true;
    }

    /**
     * The stance the situation argues for, ignoring hysteresis. Only two
     * answers: defend a live fight, or — for everything else — drive the
     * mission. `homestead` / `capitalist` are never returned: holding ground
     * and day-trading do not move the agent toward the Solar Accord, and the
     * expansionist ladder already arms, stockpiles and banks a glut as tactics
     * in service of the flight.
     */
    private static function rank(array $raw, self $current = self::Expansionist, bool $researchPaying = false): self
    {
        $inv = (array) ($raw['inventory'] ?? []);
        $has = static fn(string $k): int => (int) ($inv[$k] ?? 0);
        $tick = (int) ($raw['tick'] ?? 0);
        $armed = ($has('kinetic_gun') > 0 && $has('slug') > 0) || ($has('energy_weapon') > 0 && $has('energy_cell') > 0);

        // A live fight, and the means to fight back — survival first, then the
        // mission resumes. (Picking a fight with a passer-by is NOT a stance:
        // it does not further the Accord and only invites a `wanted` tag.)
        foreach ((array) ($raw['alerts'] ?? []) as $a) {
            $a = (array) $a;
            if (in_array((string) ($a['kind'] ?? ''), ['attacked', 'robbed', 'hit'], true) && $tick - (int) ($a['tick'] ?? 0) <= 30 && $armed) {
                return self::Aggressive;
            }
        }

        // The resupply chain. Running dry is not a reason to keep flailing at
        // the mission one part an hour — restock properly, spend the surplus on
        // the research it enables, and only then fly:
        //
        //   quartermaster --restocked--> researcher --nothing left to learn--> expansionist
        //
        // Each leg tests its OWN exit, which is why the entry and exit marks
        // differ ({@see RESTOCK_ENTER} / {@see RESTOCK_EXIT}): one threshold
        // for both would hand back to the mission with a cupboard that is bare
        // again a single build later.
        if (self::Quartermaster === $current) {
            return self::restocked($raw) ? self::Researcher : self::Quartermaster;
        }
        if (self::Researcher === $current) {
            // Hold only while research is actually paying out AND there is
            // something to cut a combine from — otherwise the mission resumes.
            return $researchPaying && self::hasResearchStock($raw) ? self::Researcher : self::Expansionist;
        }
        if (self::understocked($raw) && self::canResupply($raw)) {
            return self::Quartermaster;
        }

        // Everything else is the mission.
        return self::Expansionist;
    }

    /**
     * Restocking is only a strategy where the depot and the deposits are:
     * Earth's ground. Running low mid-crossing, in orbit, or standing on Mars
     * is not a reason to abandon the mission — there is nothing to restock
     * FROM out there, and a stance that says "do not fly while short" would
     * strand the agent exactly where flying is the only way home.
     */
    private static function canResupply(array $raw): bool
    {
        $expansion = (array) ($raw['expansion'] ?? []);

        // A finished ship outranks a thin cupboard: if there is already an
        // orbital hull on the pad, the mission has a live path this turn and
        // restocking can wait until it does not. Otherwise a low metal count
        // would ground a ready ship through an open window.
        if (Ladder::hasOrbitalShip($raw)) {
            return false;
        }

        return ! (bool) ($raw['in_space'] ?? false)
            && 0 === (int) ($raw['altitude'] ?? 0)
            && null === ($expansion['transit'] ?? null)
            && null === ($expansion['at_body'] ?? null)
            && null === ($expansion['at_body_orbit'] ?? null);
    }

    /**
     * Whether two raws sit deep enough to cut a speculative `combine` from
     * without eating the reserve — the same bar {@see Ladder} uses for its own
     * research rung. Without it the agent could sit in `researcher` holding
     * nothing to research with.
     */
    private static function hasResearchStock(array $raw): bool
    {
        $deep = 0;
        foreach ((array) ($raw['inventory'] ?? []) as $k => $qty) {
            if ('credits' !== $k && is_numeric($qty) && (int) $qty >= Ladder::RESEARCH_SURPLUS) {
                ++$deep;
            }
        }

        return $deep >= 2;
    }

    /** The stance-specific block spliced into the system prompt. */
    public function briefing(): string
    {
        return match ($this) {
            self::Homestead => 'STANCE: HOMESTEAD (bootstrap for the mission) — you are not geared for the Accord yet. '
                . 'Buy a weapon + ammo + a medicine, stockpile the metal / composite / credits a ship needs, then '
                . 'gear that ship. Towers are only a credit faucet while you save — the goal is the Solar Accord, so '
                . 'switch to gearing the moment you can afford to.',
            self::Aggressive => 'STANCE: AGGRESSIVE (defend, then resume) — you are under attack and armed. Keep ammo '
                . 'topped (`buy slug`/`energy_cell`), stay at weapon range, and `attack` the one who hit you. Break off '
                . 'and `heal` below ~35% HP. Do NOT hunt passers-by or chase a bounty — clear the threat and get back '
                . 'to the mission.',
            self::Capitalist => 'STANCE: CAPITALIST (fund the mission) — you are sitting on credits the Accord needs '
                . 'spent. `fulfill` a contract whose `want` you already cover, or `sell` a glut past its cap, then pour '
                . 'the cash into ship parts, `heat_shield` / `acid_skin` inputs, or an open colony / terraform / Station '
                . 'board. Do not day-trade for its own sake.',
            self::Quartermaster => 'STANCE: QUARTERMASTER (restock, then hand on) — the cupboard is bare and every '
                . 'build is blocked on it, so this turn is about SUPPLY, not the flight. `sell` a real lot of whatever '
                . 'glut you are sitting on (not a token 20), `mine`/`chop` the deposit under your feet until it is '
                . 'worked out, and `buy` metal and crystal in quantity. Do NOT gear, ride or depart while short — '
                . 'finishing a part an hour is how the last evening was lost. Fill up and the mission resumes on its own.',
            self::Researcher => 'STANCE: RESEARCHER (spend the surplus on what you do not know) — you are restocked '
                . 'and the raws are deep enough to gamble with. `combine` fresh, uninvented tag sets from the surplus '
                . 'while you have it: the drive recipe is undocumented, inventing it is the way through, and each '
                . 'grant pays inventor points. Argue the physics before you file — the Guild charges 50 credits per '
                . 'filing and refunds it only if granted. When the surplus thins or the grants dry up, fly.',
            self::Expansionist => 'STANCE: EXPANSIONIST — drive for the Solar Accord (Mars terraformed, Venus held, a '
                . 'Moon base). On a body: `land_body`/`land_moon`, then `construct{shape:colony|extractor|terraform}` '
                . 'and fund the board — this is the win. In Earth orbit with a fuelled ion-thruster ship and an open '
                . 'window: `depart` (a moon first — a Forward Base cheapens every later route). On the ground: gear a '
                . 'ship (`build` a spread of parts → `finalize`), craft `heat_shield` / `acid_skin` / `hydrogen`, then '
                . '`ride`/`launch` up. When you CANNOT progress the flight this turn, RESEARCH: `combine` fresh '
                . 'uninvented tag sets from a raw surplus — the drive recipe is undocumented and inventing it is the '
                . 'way through (and it pays inventor points). `invest` spare credits in any open board. Do NOT grind '
                . 'towers — harvest and research instead.',
        };
    }
}
