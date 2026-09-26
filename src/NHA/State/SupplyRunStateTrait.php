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
 * {@see \NHA\StateStore} slice: when the supply run ({@see \NHA\Brain\SupplyRun})
 * was stood down, and why.
 *
 * The run keeps no state of its own; every move is read off the observation.
 * The one thing it needs remembered is a refusal it cannot fix by shedding
 * cargo — no landing gear, too little Δv — so it hands the turns back to
 * ordinary play for a while instead of resubmitting the same refused move.
 *
 * Relies on the host's `array $data` and `save()`.
 *
 * @since 3.20.0
 */
trait SupplyRunStateTrait
{
    /** How long a stood-down run stays down. About twenty minutes of play. */
    private const SUPPLY_PAUSE_TICKS = 600;

    /** Stands the run down, recording the game's reason. */
    public function pauseSupplyRun(int $agent_id, int $tick, string $reason): void
    {
        $this->data['agent_supply_pause'][(string) $agent_id] = ['tick' => $tick, 'reason' => mb_substr($reason, 0, 240)];
        $this->save();
    }

    /** Whether the run is stood down at `$tick`. */
    public function supplyRunPaused(int $agent_id, int $tick): bool
    {
        $p = $this->data['agent_supply_pause'][(string) $agent_id] ?? null;

        return is_array($p) && $tick - (int) ($p['tick'] ?? 0) < self::SUPPLY_PAUSE_TICKS;
    }

    /** Why the run was last stood down, or null. */
    public function supplyRunPauseReason(int $agent_id): ?string
    {
        $p = $this->data['agent_supply_pause'][(string) $agent_id] ?? null;

        return is_array($p) ? (string) ($p['reason'] ?? '') : null;
    }
}
