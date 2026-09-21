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
 * {@see \NHA\StateStore} slice: the single-driver lease for the autoplay loop.
 * `bot.php`'s in-process loop and the standalone `autoplay.php` runner both use
 * {@see \NHA\Brain\AutoPlayer}; the lease stops them submitting two intents per
 * interval from one token.
 *
 * Relies on the host's `array $data`, `save()` and `string $path`.
 *
 * @since 3.1.32
 */
trait AutoplayLeaseTrait
{
    /**
     * Takes (or renews) the autoplay driver lease for `$holder`.
     *
     * Only one process should drive an agent's autoplay loop at a time. Each
     * loop passes a stable per-process holder id; the first to acquire the
     * lease drives, the others skip their turn until it expires (a crashed
     * driver frees it within the TTL).
     *
     * The acquire/release is done under an exclusive OS lock (see
     * {@see withLeaseLock()}), so two runners starting at the same instant
     * cannot both observe "no lease" and both claim it — it is a real
     * compare-and-swap, not just an atomic file write.
     *
     * @param string   $holder   A stable id for the calling loop, e.g. `"bot.php:1234"`.
     * @param int|null $interval The loop's turn interval in seconds. The lease TTL is
     *                           derived from it ({@see leaseTtlForInterval()}) so the
     *                           lease outlives the gap between turns; null → the 45s floor.
     *
     * @return bool True when `$holder` now holds the lease (it was free, expired,
     *              or already theirs); false when another holder's lease is live.
     */
    public function acquireAutoplayLease(string $holder, ?int $interval = null): bool
    {
        $ttl = self::leaseTtlForInterval($interval);

        return (bool) $this->withLeaseLock(function () use ($holder, $ttl): bool {
            $lease = $this->data['autoplay_lease'] ?? null;
            $now = time();

            // Always claim it, not just when contended: the driver lock is only
            // evidence of liveness if the LIVE driver is the one holding it.
            // Claiming it solely on the contended path left the first runner
            // without it, so the second found it free and read a perfectly
            // healthy driver as dead — the precise failure this guards against.
            $driverLockIsMine = $this->claimDriverLock($holder);

            if (is_array($lease)
                && (string) ($lease['holder'] ?? '') !== $holder
                && (int) ($lease['expires'] ?? 0) > $now
                && ! $driverLockIsMine
            ) {
                return false;
            }

            $this->data['autoplay_lease'] = ['holder' => $holder, 'expires' => $now + $ttl];
            $this->save();

            return true;
        });
    }

    /**
     * The lease TTL for a given autoplay interval: three intervals, floored at
     * 45 seconds. A fixed TTL shorter than the interval expires in the gap
     * between turns, so lease ownership ping-pongs between the two runners
     * (harmless flapping, but noisy). Deriving it from the interval keeps the
     * lease alive across the quiet stretch.
     */
    public static function leaseTtlForInterval(?int $interval): int
    {
        return max(45, 3 * max(0, (int) $interval));
    }

    /**
     * An exclusive OS lock held for as long as this process drives.
     *
     * @var resource|null
     */
    private $driver_lock = null;

    /**
     * The holder id the driver lock was taken for.
     *
     * The lock lives on the StateStore object, but it vouches for a PROCESS —
     * so it may only speak for the holder that claimed it. One store driving
     * two holder ids (a test, or `bot.php` and `autoplay.php` sharing an
     * instance) would otherwise have the second id inherit the first's lock and
     * declare the first dead.
     */
    private ?string $driver_lock_holder = null;

    /**
     * Takes the driver lock, or reports that someone else still holds it.
     *
     * The TTL alone assumes a driver gets the chance to hand its lease back,
     * and on Windows it never does: `autoplay.php` releases on SIGINT/SIGTERM,
     * but Windows has no signals to deliver — Ctrl+C and `Stop-Process -Force`
     * both hard-terminate. So every restart there orphaned the lease and the
     * next runner sat out the full TTL logging "another driver holds the lease"
     * at a process that no longer existed.
     *
     * Liveness is asked of the OS rather than guessed from the holder's PID: an
     * advisory lock is released by the kernel when its owner dies, however it
     * dies, so taking it is proof the previous driver is gone. A PID check
     * would be a guess — PIDs are recycled, and being wrong in that direction
     * means TWO drivers submitting two intents per interval from one token,
     * which is the exact thing this lease exists to prevent.
     *
     * Failing to take the lock is the safe answer: the caller falls back to
     * waiting out the TTL, which is what it did before.
     *
     * @return bool true when this process now holds the driver lock
     *
     * @since 3.12.1
     */
    private function claimDriverLock(string $holder): bool
    {
        if (is_resource($this->driver_lock)) {
            // Ours only if we took it for THIS holder; another id in this same
            // process is a different driver, and its lease must be respected.
            return $this->driver_lock_holder === $holder;
        }
        // The state directory may not exist yet on a first run. Without this,
        // the first driver's `fopen` fails (no lock, no claim), `save()` then
        // CREATES the directory, and the second driver finds the lock free and
        // reads a perfectly healthy first driver as dead — two drivers, which
        // is the one outcome this lease exists to prevent.
        if (! is_dir($dir = dirname($this->path))) {
            @mkdir($dir, 0o777, true);
        }
        $lock = @fopen($this->path . '.driver.lock', 'c');
        if ($lock === false) {
            return false;
        }
        // Non-blocking: a live driver holds this, and we must not wait on it.
        if (! @flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);

            return false;
        }
        // Held for the rest of this process's life — never unlocked here. That
        // is the whole mechanism: the kernel drops it when we die.
        $this->driver_lock = $lock;
        $this->driver_lock_holder = $holder;

        return true;
    }

    /** Drops the lease if `$holder` currently holds it (call on clean shutdown). */
    public function releaseAutoplayLease(string $holder): void
    {
        $this->withLeaseLock(function () use ($holder): void {
            if ((string) ($this->data['autoplay_lease']['holder'] ?? '') === $holder) {
                unset($this->data['autoplay_lease']);
                $this->save();
            }
        });
        if (is_resource($this->driver_lock) && $this->driver_lock_holder === $holder) {
            @flock($this->driver_lock, LOCK_UN);
            @fclose($this->driver_lock);
            $this->driver_lock = null;
            $this->driver_lock_holder = null;
        }
    }

    /** The id of the process currently holding a live autoplay lease, or null. */
    public function autoplayLeaseHolder(): ?string
    {
        $lease = $this->data['autoplay_lease'] ?? null;

        return (is_array($lease) && (int) ($lease['expires'] ?? 0) > time())
            ? (string) $lease['holder']
            : null;
    }

    /**
     * Runs `$fn` while holding an exclusive OS lock on a sibling `.lease.lock`
     * file, with `$this->data` first re-read from disk so `$fn` sees the lease
     * exactly as other processes last left it — and, on {@see save()}, does not
     * clobber unrelated keys another process wrote in the meantime.
     *
     * The re-read is only adopted when it decodes to a non-empty array. An empty
     * or truncated state file (full disk, interrupted first write, a hand-edit)
     * would otherwise become `[]`, and the next {@see save()} would persist a
     * file holding nothing but the lease — dropping the agent token, which the
     * NHA server issues exactly once. A stale in-memory read is the safe failure.
     *
     * Degrades to running `$fn` unlocked (the old best-effort read-modify-write)
     * when the lock file can't be opened: a rare doubled interval beats a loop
     * that never drives.
     *
     * @template T
     *
     * @param callable():T $fn
     *
     * @return T
     */
    private function withLeaseLock(callable $fn): mixed
    {
        $lock = @fopen($this->path . '.lease.lock', 'c');
        if ($lock === false) {
            return $fn();
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                return $fn();
            }

            if (is_file($this->path)) {
                $fresh = json_decode((string) file_get_contents($this->path), true);
                if (is_array($fresh) && $fresh !== []) {
                    $this->data = $fresh;
                }
            }

            return $fn();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}
