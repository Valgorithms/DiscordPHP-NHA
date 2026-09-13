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

use NHA\Brain\Objectives;
use PHPUnit\Framework\TestCase;

/**
 * Reading the world's own objective board, so "where should I go next" stops
 * being answered from memory.
 *
 * The fixture is trimmed from a real `GET /expansion` response.
 *
 * @covers \NHA\Brain\Objectives
 */
final class ObjectivesTest extends TestCase
{
    private const ME = 142285;

    private function board(): array
    {
        return ['era' => 'expansion', 'accord' => ['mars_terraformed' => false], 'bodies' => [
            // Capped out: our contrib already sits at the 60% ceiling.
            'phobos' => ['colony' => ['cap_pct_per_agent' => 60, 'modules' => [
                ['module' => 'cracker', 'complete' => true, 'need' => ['metal' => 180], 'have' => ['metal' => 180], 'remaining' => []],
                ['module' => 'mass_driver', 'complete' => false,
                    'need' => ['superalloy' => 160, 'nickel' => 120], 'have' => ['superalloy' => 96, 'nickel' => 72],
                    'remaining' => ['superalloy' => 64, 'nickel' => 48], 'funders' => 4,
                    'contrib' => ['142285' => ['superalloy' => 96, 'nickel' => 72]]],
            ]]],
            // Wide open, and the lines can only be dug up on the surface.
            'mars' => ['colony' => ['cap_pct_per_agent' => 40, 'modules' => [
                ['module' => 'reactor', 'complete' => false,
                    'need' => ['mars_regolith' => 1450], 'have' => ['mars_regolith' => 1160],
                    'remaining' => ['mars_regolith' => 290], 'funders' => 4, 'contrib' => []],
            ]]],
        ]];
    }

    public function testItSeparatesWorkMoneyCanDoFromWorkOnlyBootsCanDo(): void
    {
        $fund = Objectives::fundableWithCredits($this->board(), self::ME);
        self::assertNotNull($fund);
        self::assertSame('phobos', $fund['body'], 'superalloy and nickel are both sold by the depot');

        // Martian regolith is not for sale at any price — that is a trip.
        self::assertSame('mars', Objectives::forwardBaseTarget($this->board(), self::ME));
        self::assertNull(Objectives::forwardBaseTarget($this->board(), self::ME, ['mars']), 'unreachable — no target');
    }

    public function testColonyDoneIsDerivedFromLiveContributionNotMemory(): void
    {
        // Capped on the only open phobos module; nothing given on mars yet.
        self::assertSame(['phobos'], Objectives::bodiesWithNoWorkForUs($this->board(), self::ME));

        // The same board read by an agent that has given nothing anywhere has
        // work everywhere — which is the point: it is recomputed, so a body
        // LEAVES the set as readily as it enters.
        self::assertSame([], Objectives::bodiesWithNoWorkForUs($this->board(), 999));
    }

    public function testTheDigestMarksWhatCannotBeBought(): void
    {
        $digest = Objectives::digest($this->board(), self::ME);

        self::assertStringContainsString('phobos/mass_driver (creditable)', $digest);
        self::assertStringContainsString('mars/reactor (surface work)', $digest);
        self::assertStringContainsString('290 mars_regolith*', $digest, 'starred — cannot be bought');
        self::assertStringContainsString('64 superalloy', $digest);
        self::assertStringNotContainsString('64 superalloy*', $digest, 'superalloy IS buyable');
    }

    public function testAnEmptyOrOffEraBoardIsSurvivable(): void
    {
        self::assertSame([], Objectives::openModules([], self::ME));
        self::assertSame([], Objectives::bodiesWithNoWorkForUs([], self::ME));
        self::assertNull(Objectives::fundableWithCredits([], self::ME));
        self::assertNull(Objectives::forwardBaseTarget([], self::ME));
        self::assertStringContainsString('No open colony work', Objectives::digest([], self::ME));
    }
}
