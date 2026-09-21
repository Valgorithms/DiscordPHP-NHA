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

namespace NHA\Tests\State;

use NHA\StateStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NHA\State\AutoplayLeaseTrait
 */
final class AutoplayLeaseTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nha-lease-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        foreach (['', '.lease.lock', '.driver.lock'] as $suffix) {
            if (is_file($this->path . $suffix)) {
                @unlink($this->path . $suffix);
            }
        }
    }

    /** One driver at a time — that is the whole point of the lease. */
    public function testASecondLiveDriverIsRefused(): void
    {
        $a = new StateStore($this->path);
        self::assertTrue($a->acquireAutoplayLease('autoplay.php:111', 15));

        // `$a` is still running and holding the OS driver lock, so `$b` must
        // NOT get in: two drivers means two intents per interval on one token.
        $b = new StateStore($this->path);
        self::assertFalse($b->acquireAutoplayLease('autoplay.php:222', 15));
    }

    /** The holder renews its own lease without ever refusing itself. */
    public function testAHolderKeepsRenewingItsOwnLease(): void
    {
        $a = new StateStore($this->path);

        self::assertTrue($a->acquireAutoplayLease('autoplay.php:111', 15));
        self::assertTrue($a->acquireAutoplayLease('autoplay.php:111', 15));
        self::assertTrue($a->acquireAutoplayLease('autoplay.php:111', 15));
    }

    /**
     * A clean stop hands the lease straight back, so the next runner drives on
     * its very next tick rather than waiting out the TTL.
     */
    public function testACleanReleaseLetsTheNextDriverInImmediately(): void
    {
        $a = new StateStore($this->path);
        self::assertTrue($a->acquireAutoplayLease('autoplay.php:111', 15));
        $a->releaseAutoplayLease('autoplay.php:111');

        $b = new StateStore($this->path);
        self::assertTrue($b->acquireAutoplayLease('autoplay.php:222', 15));
    }

    /**
     * And a driver that never got to release — a `Stop-Process -Force`, a
     * crash, a Windows Ctrl+C before the handler existed — does not hold the
     * next one hostage for the full TTL.
     *
     * The lease record is left behind deliberately here, unexpired, exactly as
     * a killed process leaves it. Liveness is the OS lock, not the TTL and not
     * a PID guess.
     */
    public function testAKilledDriverDoesNotBlockTheNextOneForTheWholeTtl(): void
    {
        $dead = new StateStore($this->path);
        self::assertTrue($dead->acquireAutoplayLease('autoplay.php:75980', 15));

        // The lease it wrote is still live by the clock…
        $raw = (array) json_decode((string) file_get_contents($this->path), true);
        self::assertGreaterThan(time(), (int) $raw['autoplay_lease']['expires']);

        // …but the process is gone. Dropping every reference releases the OS
        // lock the same way process death does.
        unset($dead);
        gc_collect_cycles();

        $next = new StateStore($this->path);
        self::assertTrue(
            $next->acquireAutoplayLease('autoplay.php:52368', 15),
            'a lease held by a dead process is not a reason to sit out 45 seconds',
        );
    }

    /** TTL still scales with the turn interval, floored at 45s. */
    public function testTheTtlOutlivesTheGapBetweenTurns(): void
    {
        self::assertSame(45, StateStore::leaseTtlForInterval(null));
        self::assertSame(45, StateStore::leaseTtlForInterval(15));
        self::assertSame(90, StateStore::leaseTtlForInterval(30));
    }
}
