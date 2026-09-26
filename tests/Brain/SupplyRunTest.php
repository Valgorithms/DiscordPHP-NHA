<?php

declare(strict_types=1);

use NHA\Brain\SupplyRun;

class SupplyRunTest extends NHAUnitTestCase
{
    private const NEED = ['item' => 'thermal_core', 'resource' => 'mars_ice', 'body' => 'mars'];

    /** Packed for Mars: spare batteries, copper, the heat shield, eight crates. */
    private const PACKED = ['credits' => 5000, 'battery' => 2, 'copper' => 10, 'heat_shield' => 1, 'warp_container' => 8];

    /**
     * Earth, with the live shape: Mars gate-linked, Triton needing a
     * thermal_core, a tall elevator two cells east.
     *
     * @param array<string,mixed> $over
     * @param array<string,int>   $inv  Replaces the inventory outright.
     */
    private function world(array $over = [], array $inv = self::PACKED): array
    {
        $raw = array_replace_recursive([
            'tick' => 100, 'position' => [10, 10], 'in_space' => false, 'altitude' => 0,
            'elevators' => [['x' => 12, 'y' => 10, 'height' => 860]],
            'expansion' => [
                'location' => 'earth',
                'gates' => ['linked_from_here' => ['deimos', 'mars']],
                'preflight' => ['destinations' => [
                    'mars' => ['needs_in_hold' => ['heat_shield'], 'needs_landing_gear_on_ship' => true],
                    'triton' => ['needs_in_hold' => ['thermal_core'], 'needs_landing_gear_on_ship' => true],
                ]],
            ],
        ], $over);
        $raw['inventory'] = $inv;

        return $raw;
    }

    /** @param array<string,mixed> $over */
    private function onMars(array $inv, array $over = []): array
    {
        return $this->world(array_replace_recursive([
            'in_space' => false,
            'expansion' => ['location' => 'on_mars', 'at_body' => 'mars', 'gates' => ['linked_from_here' => ['earth']]],
        ], $over), $inv);
    }

    /**
     * @covers \NHA\Brain\SupplyRun::need
     */
    public function testTheRunIsForGearAnUnfoundedBodyNeeds(): void
    {
        self::assertSame(self::NEED, SupplyRun::need($this->world(), ['deimos', 'mars'], ['triton']));
        self::assertNull(SupplyRun::need($this->world(), ['deimos', 'mars'], []), 'a founded colony takes credits from home');
        self::assertNull(SupplyRun::need($this->world(), ['triton'], ['triton']), 'nor a finished one');
        self::assertNull(SupplyRun::need($this->world([], ['thermal_core' => 1]), [], ['triton']), 'already held');
        self::assertNull(SupplyRun::need($this->world(['expansion' => ['preflight' => ['destinations' => ['triton' => ['needs_in_hold' => ['acid_skin']]]]]]), [], ['triton']), 'nothing this class can make on site');
    }

    /**
     * Packing, in order, and crafted in batches: the batteries, then the
     * crates, each input bought or made before the thing that needs it.
     *
     * @covers \NHA\Brain\SupplyRun::step
     * @covers \NHA\Brain\SupplyRun::make
     */
    public function testPackingBuysAndCraftsWhatTheTripNeeds(): void
    {
        $base = ['credits' => 5000, 'metal' => 50, 'salt' => 50, 'silicon' => 50, 'copper' => 10, 'heat_shield' => 1];

        $s = SupplyRun::step($this->world([], $base), self::NEED);
        self::assertSame(['combine', ['ingredients' => ['metal' => 1, 'salt' => 1, 'silicon' => 1], 'n' => 2]], [$s['verb'], $s['args']], 'two batteries');

        $s = SupplyRun::step($this->world([], $base + ['battery' => 2]), self::NEED);
        self::assertSame(['buy', ['resource' => 'superalloy', 'n' => 8]], [$s['verb'], $s['args']], 'the crates\' first input');

        $s = SupplyRun::step($this->world([], $base + ['battery' => 2, 'superalloy' => 8]), self::NEED);
        self::assertSame(['combine', ['ingredients' => ['metal' => 1, 'silicon' => 1], 'n' => 8]], [$s['verb'], $s['args']], 'eight chips at once');

        $s = SupplyRun::step($this->world([], $base + ['battery' => 2, 'superalloy' => 8, 'chip' => 8, 'acid_skin' => 1]), self::NEED);
        self::assertSame(['buy', ['resource' => 'sulfur', 'n' => 7]], [$s['verb'], $s['args']], 'down the acid_skin chain for the seven it lacks');

        $s = SupplyRun::step($this->world([], $base + ['battery' => 2, 'superalloy' => 8, 'chip' => 8, 'acid_skin' => 8]), self::NEED);
        self::assertSame(['combine', ['ingredients' => ['superalloy' => 1, 'chip' => 1, 'acid_skin' => 1], 'n' => 8]], [$s['verb'], $s['args']], 'the crates');
    }

    /**
     * @covers \NHA\Brain\SupplyRun::make
     */
    public function testWhatCannotBeBoughtOrMadeStandsTheRunDown(): void
    {
        self::assertNull(SupplyRun::make('superalloy', 8, ['credits' => 0]), 'no credits');
        self::assertNull(SupplyRun::make('mars_ice', 3, ['credits' => 5000]), 'not a depot line, no home recipe');
    }

    /**
     * Packed: to the elevator, up to the band, shed the regolith, depart.
     *
     * @covers \NHA\Brain\SupplyRun::step
     */
    public function testPackedItFliesToTheBodyShedsTheCargoAndDeparts(): void
    {
        $withRegolith = self::PACKED + ['c_regolith' => 3_700_000];

        $s = SupplyRun::step($this->world([], $withRegolith), self::NEED);
        self::assertSame(['move', ['x' => 12, 'y' => 10]], [$s['verb'], $s['args']]);

        $s = SupplyRun::step($this->world(['position' => [12, 10]], $withRegolith), self::NEED);
        self::assertSame('ride', $s['verb']);

        $band = ['in_space' => true, 'altitude' => 600, 'position' => [12, 10]];
        $s = SupplyRun::step($this->world($band, $withRegolith), self::NEED);
        self::assertSame(['order', ['side' => 'sell', 'resource' => 'c_regolith', 'qty' => 3_700_000, 'price' => 1]], [$s['verb'], $s['args']], 'the gate refuses uncrated cargo');

        $s = SupplyRun::step($this->world($band, self::PACKED + ['c_regolith' => 70]), self::NEED);
        self::assertSame(['depart', ['dest' => 'mars']], [$s['verb'], $s['args']], 'seventy units of drip fit in the crates');

        $s = SupplyRun::step($this->world(['in_space' => true, 'altitude' => 250, 'position' => [12, 10]], self::PACKED), self::NEED);
        self::assertSame('ride', $s['verb'], 'below the band: reset at the elevator');
    }

    /**
     * The depot is on the ground: a run that is not packed comes down first.
     *
     * @covers \NHA\Brain\SupplyRun::step
     */
    public function testPackingHappensOnTheGround(): void
    {
        $s = SupplyRun::step($this->world(['in_space' => true, 'altitude' => 500, 'position' => [12, 10]], ['credits' => 5000]), self::NEED);

        self::assertSame('ride', $s['verb'], 'a ride from space goes down');
        self::assertStringContainsString('down to the depot', $s['why']);
    }

    /**
     * On Mars: land, mine, make the core there, shed what the crates cannot
     * carry, and go home from the surface.
     *
     * @covers \NHA\Brain\SupplyRun::step
     */
    public function testOnTheBodyItMinesMakesTheCoreAndGoesHome(): void
    {
        $orbit = $this->world(['in_space' => true, 'expansion' => ['location' => 'orbit_mars', 'at_body_orbit' => 'mars']], self::PACKED);
        self::assertSame('land_body', SupplyRun::step($orbit, self::NEED)['verb']);

        $s = SupplyRun::step($this->onMars(self::PACKED), self::NEED);
        self::assertSame(['mine', ['n' => 6]], [$s['verb'], $s['args']]);

        $s = SupplyRun::step($this->onMars(self::PACKED + ['mars_ice' => 6]), self::NEED);
        self::assertSame(['combine', ['ingredients' => ['battery' => 1, 'mars_ice' => 1, 'copper' => 1]]], [$s['verb'], $s['args']]);

        $made = ['thermal_core' => 1, 'battery' => 1, 'copper' => 9, 'warp_container' => 5];
        $s = SupplyRun::step($this->onMars($made + ['c_regolith' => 500, 'mars_regolith' => 12, 'mars_ice' => 5]), self::NEED);
        self::assertSame(['order', ['side' => 'sell', 'resource' => 'c_regolith', 'qty' => 500, 'price' => 1]], [$s['verb'], $s['args']], 'the largest line first');

        $s = SupplyRun::step($this->onMars($made + ['c_regolith' => 70, 'mars_regolith' => 12, 'mars_ice' => 5]), self::NEED);
        self::assertSame(['depart', ['dest' => 'earth']], [$s['verb'], $s['args']], '87 units in five crates; the leftover ice rides along');
    }

    /**
     * A depart refused for uncrated cargo is answered by shedding, even when
     * the count said it would fit.
     *
     * @covers \NHA\Brain\SupplyRun::step
     */
    public function testACargoRefusalIsAnsweredByShedding(): void
    {
        $s = SupplyRun::step($this->onMars(['thermal_core' => 1, 'warp_container' => 5, 'c_regolith' => 40]), self::NEED, true);

        self::assertSame('order', $s['verb']);
    }

    /**
     * @covers \NHA\Brain\SupplyRun::step
     */
    public function testWithTheResourceInHandTheCoreIsMadeAtHome(): void
    {
        $s = SupplyRun::step($this->world([], ['credits' => 5000, 'mars_ice' => 2, 'battery' => 1, 'copper' => 5]), self::NEED);

        self::assertSame(['combine', ['ingredients' => ['battery' => 1, 'mars_ice' => 1, 'copper' => 1], 'n' => 1]], [$s['verb'], $s['args']], 'no trip needed');
    }

    /**
     * @covers \NHA\Brain\SupplyRun::step
     */
    public function testInTransitOrAtAnotherBodyTheTurnIsLeftAlone(): void
    {
        self::assertNull(SupplyRun::step($this->world(['expansion' => ['transit' => ['to' => 'mars']]]), self::NEED));
        self::assertNull(SupplyRun::step($this->world(['expansion' => ['at_body' => 'venus']]), self::NEED));
    }

    /**
     * Live: the run made its thermal_core on Mars and stopped right there,
     * because the gear was no longer missing. Until the agent is home the run
     * is not over — and every earlier test called the return leg directly,
     * never through need().
     *
     * @covers \NHA\Brain\SupplyRun::need
     * @covers \NHA\Brain\SupplyRun::step
     */
    public function testTheRunLastsUntilTheGearIsHome(): void
    {
        $madeOnMars = $this->onMars(['thermal_core' => 1, 'warp_container' => 5, 'c_regolith' => 70, 'mars_ice' => 5]);

        $need = SupplyRun::need($madeOnMars, ['deimos', 'mars'], ['triton']);
        self::assertSame(self::NEED, $need, 'still on Mars with it: still running');
        self::assertSame(['depart', ['dest' => 'earth']], [SupplyRun::step($madeOnMars, $need)['verb'], SupplyRun::step($madeOnMars, $need)['args']]);

        self::assertNull(SupplyRun::need($this->world([], ['thermal_core' => 1]), ['deimos', 'mars'], ['triton']), 'home with it: done');
    }

    /**
     * Ready for a fix: the gear is made even while no ship can reach the body,
     * because a balance change would open it and the core takes a trip to
     * Mars to make.
     *
     * @covers \NHA\Brain\SupplyRun::need
     */
    public function testTheGearIsMadeEvenWhileTheBodyIsOutOfReach(): void
    {
        self::assertSame(self::NEED, SupplyRun::need($this->world(), ['deimos', 'mars'], ['triton']));
    }
}
