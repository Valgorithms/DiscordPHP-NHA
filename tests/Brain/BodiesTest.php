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

namespace NHA\Tests\Brain;

use NHA\Brain\Bodies;
use NHA\Brain\Ladder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NHA\Brain\Bodies
 */
final class BodiesTest extends TestCase
{
    /**
     * The live Season 8 shape, trimmed from a real `GET /observe/142285` on
     * 2026-09-21. Seven bodies, three of which no constant in this repo names.
     *
     * @return array<string,mixed>
     */
    private function season8(): array
    {
        return ['expansion' => [
            'windows' => [
                'deimos' => ['open' => false, 'dv_need' => 50, 'transit_ticks' => 90, 'opens_in' => 93],
                'phobos' => ['open' => false, 'dv_need' => 55, 'transit_ticks' => 92, 'opens_in' => 93],
                'mars' => ['open' => false, 'dv_need' => 100, 'transit_ticks' => 90, 'opens_in' => 93],
                'venus' => ['open' => true, 'dv_need' => 130, 'transit_ticks' => 40, 'opens_in' => 0],
                'enceladus' => ['open' => false, 'dv_need' => 190, 'transit_ticks' => 170, 'opens_in' => 373],
                'titan' => ['open' => true, 'dv_need' => 220, 'transit_ticks' => 180, 'opens_in' => 0],
                'triton' => ['open' => false, 'dv_need' => 320, 'transit_ticks' => 260, 'opens_in' => 473],
            ],
            'preflight' => ['destinations' => [
                'deimos' => ['needs_in_hold' => [], 'needs_landing_gear_on_ship' => true, 'min_thrust_to_weight' => 0.5, 'course_correction_fuel' => 4],
                'phobos' => ['needs_in_hold' => [], 'needs_landing_gear_on_ship' => true, 'min_thrust_to_weight' => 0.5, 'course_correction_fuel' => 4],
                'mars' => ['needs_in_hold' => ['heat_shield'], 'needs_landing_gear_on_ship' => true, 'min_thrust_to_weight' => 0.7, 'course_correction_fuel' => 4],
                'venus' => ['needs_in_hold' => ['heat_shield', 'acid_skin'], 'needs_landing_gear_on_ship' => false, 'min_thrust_to_weight' => 0.9, 'course_correction_fuel' => 2],
                'enceladus' => ['needs_in_hold' => ['thermal_core'], 'needs_landing_gear_on_ship' => true, 'min_thrust_to_weight' => 0.4, 'course_correction_fuel' => 8],
                'titan' => ['needs_in_hold' => ['thermal_core', 'heat_shield'], 'needs_landing_gear_on_ship' => true, 'min_thrust_to_weight' => 0.8, 'course_correction_fuel' => 9],
                'triton' => ['needs_in_hold' => ['thermal_core'], 'needs_landing_gear_on_ship' => true, 'min_thrust_to_weight' => 0.4, 'course_correction_fuel' => 13],
            ]],
        ]];
    }

    /**
     * The whole point: a body the repo has never heard of is still a place the
     * agent can go. Season 8 added three, and every hardcoded table missed all
     * three — including Triton, the only body with open colony work.
     */
    public function testItSeesBodiesNoConstantInThisRepoNames(): void
    {
        $names = Bodies::names($this->season8());

        self::assertSame(
            ['deimos', 'phobos', 'mars', 'venus', 'enceladus', 'titan', 'triton'],
            $names,
            'every destination the world offers, cheapest-Δv first',
        );
        foreach (['enceladus', 'titan', 'triton'] as $new) {
            self::assertNotContains($new, array_keys(Ladder::DEPART_ORDER), 'fixture is only meaningful while the constant lags');
            self::assertContains($new, $names);
        }
    }

    /** Gear requirements come from the engine, including for the new bodies. */
    public function testArrivalGearIsReadFromTheEngine(): void
    {
        $order = Bodies::departOrder($this->season8());

        self::assertSame(['thermal_core'], $order['enceladus']);
        self::assertSame(['thermal_core', 'heat_shield'], $order['titan'], 'Titan has atmosphere enough for a real entry burn');
        self::assertSame(['heat_shield', 'acid_skin'], $order['venus'], 'and the bodies we did know about are unchanged');
    }

    /**
     * Course-correction fuel was never modelled, because no constant carried
     * it. Being short of it does not get the `depart` refused — it strands the
     * agent on arrival, which is far more expensive.
     */
    public function testCourseCorrectionFuelIsCarriedPerBody(): void
    {
        $raw = $this->season8();

        self::assertSame(13, Bodies::correctionFuel($raw, 'triton'), '260 ticks of crossing');
        self::assertSame(4, Bodies::correctionFuel($raw, 'deimos'));
        self::assertSame(0, Bodies::correctionFuel($raw, 'nowhere'));
    }

    /**
     * A window quoted with no preflight row tells us nothing about what the
     * arrival consumes — and "nothing" must never read as "nothing required".
     * The gap may only ever ask for MORE gear than the engine would.
     */
    public function testAMissingPreflightRowDoesNotRelaxARequirement(): void
    {
        $order = Bodies::departOrder(['expansion' => ['windows' => [
            'mars' => ['open' => true, 'dv_need' => 100],
            'venus' => ['open' => true, 'dv_need' => 130],
        ]]]);

        self::assertSame(['heat_shield'], $order['mars'], 'silence is not permission to fly bare');
        self::assertSame(['heat_shield', 'acid_skin'], $order['venus']);
    }

    /** With no expansion block at all — a cached obs, an offline test — it still answers. */
    public function testWithNoObservationItFallsBackToTheKnownTable(): void
    {
        self::assertSame(['deimos', 'phobos', 'mars', 'venus'], Bodies::names([]));
        self::assertSame(['heat_shield'], Bodies::departOrder([])['mars']);
    }

    /** `earth` is the way home, never an outbound destination to pick. */
    public function testEarthIsNeverOfferedAsADestination(): void
    {
        self::assertNotContains('earth', Bodies::names(['expansion' => ['windows' => [
            'earth' => ['open' => true, 'dv_need' => 10],
            'mars' => ['open' => true, 'dv_need' => 100],
        ]]]));
    }

    /**
     * And the ladder actually flies there: with the gear in hold and the window
     * open, Titan is a legal target even though no constant names it.
     */
    public function testTheLadderWillDepartForABodyItHasNoConstantFor(): void
    {
        $raw = $this->season8();
        $raw += [
            'tick' => 1500, 'position' => [30, 110], 'in_space' => true, 'altitude' => 500,
            'vehicles' => [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true]],
            'inventory' => ['cryo_fuel' => 600, 'thermal_core' => 1, 'heat_shield' => 1, 'acid_skin' => 1],
        ];
        $raw['expansion']['at_body'] = null;

        // Venus (Δv 130) is open and fully equipped, so it wins on price; skip
        // it and the next open window is Titan, three bodies past the constant.
        self::assertSame('venus', Ladder::departTarget($raw));
        self::assertSame('titan', Ladder::departTarget($raw, ['venus']));
    }
}
