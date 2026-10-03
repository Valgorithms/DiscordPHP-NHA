<?php

declare(strict_types=1);

use NHA\Brain\GameData;
use NHA\Brain\ReachRun;

/**
 * The reach run, against the live situation of 2026-10-03: Triton at Δv 260
 * (transit 260), the agent's flyers far too heavy to make it, no helium3,
 * standing on the tall elevator.
 *
 * @covers \NHA\Brain\ReachRun
 */
class ReachRunTest extends NHAUnitTestCase
{
    /** A live flyer: the best the agent had, 229 on a full helium3 load. */
    private const HEAVY = ['name' => 'vehicle', 'flies' => true, 'orbital_engine' => true, 'fuel_cap' => 640, 'mass' => 985, 'thrust' => 2200, 'gear' => 1];

    /** What `finalize` makes of {@see ReachRun::LANDER}. */
    private const LANDER = ['name' => 'reach_lander', 'flies' => true, 'orbital_engine' => true, 'fuel_cap' => 600, 'mass' => 295, 'thrust' => 700, 'gear' => 1];

    /**
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    private function world(array $over = []): array
    {
        return array_replace_recursive([
            'tick' => 2_155_756, 'position' => [77, 140], 'in_space' => false, 'altitude' => 0,
            'inventory' => ['credits' => 687_424, 'metal' => 138, 'crystal' => 14_629, 'ion_thruster' => 1, 'cryo_fuel' => 611, 'thermal_core' => 1],
            'vehicles' => [self::HEAVY],
            'loose_parts' => ['wing'],
            'elevators' => [['x' => 77, 'y' => 140, 'height' => 860], ['x' => 33, 'y' => 114, 'height' => 120]],
            'expansion' => [
                'location' => 'earth', 'at_body' => null, 'place' => ['where' => 'earth_ground'],
                'windows' => ['triton' => ['open' => false, 'dv_need' => 260, 'transit_ticks' => 260, 'opens_in' => 449]],
                'preflight' => ['destinations' => ['triton' => [
                    'needs_in_hold' => ['thermal_core'], 'needs_landing_gear_on_ship' => true, 'min_thrust_to_weight' => 0.4,
                    'course_correction_fuel' => 13, 'ready' => false,
                    'blockers' => ['Δv too low: your best ship makes 136 on cryo_fuel but Triton needs 260'],
                ]]],
            ],
        ], $over);
    }

    /** @return array{verb: string, args: array<string,mixed>, why: string}|null */
    private function turn(array $raw): ?array
    {
        return ReachRun::turn($raw, ['deimos', 'mars'], ['triton']);
    }

    /**
     * The engine's own numbers: a full load on the lander makes 273, burns
     * 571 leaving and keeps 29 for 13 corrections; the live heavy flyer cannot
     * make 260 on any load.
     *
     * @covers \NHA\Brain\GameData::leg
     * @covers \NHA\Brain\GameData::roundTripHelium3
     */
    public function testTheEngineArithmetic(): void
    {
        self::assertSame(['dv' => 273, 'loaded' => 600, 'cost' => 571, 'corrections' => 13, 'ok' => true], GameData::leg(295, 600, 1_000, 260, 260));
        self::assertFalse(GameData::leg(295, 600, 470, 260, 260)['ok'], 'Δv 266 but only 12 left for 13 corrections');
        self::assertSame(229, GameData::leg(985, 640, 640, 260, 260)['dv']);
        self::assertNull(GameData::roundTripHelium3(985, 640, 260, 260));
        self::assertSame(1_058, GameData::roundTripHelium3(295, 600, 260, 260));
    }

    /** The lander {@see ReachRun::LANDER} is a ship `depart` takes to Triton. */
    public function testTheLanderFlies(): void
    {
        $l = GameData::assess(ReachRun::LANDER);

        self::assertSame([295, 600, 700, 1], [$l['mass'], $l['fuel_cap'], $l['thrust'], $l['gear']]);
        self::assertTrue($l['flies'] && $l['controllable'] && $l['orbital_engine']);
        self::assertGreaterThanOrEqual(0.4 * GameData::GRAVITY * $l['mass'], $l['thrust'], "Triton's thrust gate");
    }

    public function testWithNoShipThatCanMakeItTheLanderIsBuilt(): void
    {
        $plan = ReachRun::plan($this->world(), ['deimos', 'mars'], ['triton']);
        self::assertSame(['body' => 'triton', 'dv' => 260, 'transit' => 260, 'build' => true, 'helium3' => 1_058, 'ready' => false], $plan);

        self::assertSame(['verb' => 'build', 'args' => ['part' => 'jet', 'with' => 'ion_thruster']], array_intersect_key($this->turn($this->world()), ['verb' => 1, 'args' => 1]));

        $noThruster = $this->world();
        $noThruster['inventory']['ion_thruster'] = 0;
        self::assertSame(['resource' => 'ion_thruster', 'n' => 1], $this->turn($noThruster)['args'], 'bought at the depot');

        $parts = $this->world(['loose_parts' => ['wing', 'jet', 'cockpit', 'landing_gear', 'fuel_tank', 'fuel_tank']]);
        self::assertSame(['part' => 'fuel_tank'], $this->turn($parts)['args'], 'the third tank');

        $all = $this->world(['loose_parts' => ['wing', 'jet', 'cockpit', 'landing_gear', 'fuel_tank', 'fuel_tank', 'fuel_tank']]);
        self::assertSame(['verb' => 'finalize', 'args' => ['name' => 'reach_lander']], array_intersect_key($this->turn($all), ['verb' => 1, 'args' => 1]));
    }

    public function testInOrbitTheDepotIsReachedByTheElevator(): void
    {
        $raw = $this->world(['in_space' => true, 'altitude' => 586]);
        $raw['inventory']['ion_thruster'] = 0;

        self::assertSame('ride', $this->turn($raw)['verb'], 'down, to buy');
        self::assertSame('build', $this->turn($this->world(['in_space' => true, 'altitude' => 586]))['verb'], 'a part needs no depot');
    }

    /**
     * The Moon is reached from the top of the sky, and decay takes two
     * altitude a tick: the ride and the landing go out together.
     */
    public function testWithTheLanderBuiltTheRunGoesToTheMoonForHelium3(): void
    {
        $raw = $this->world(['vehicles' => [self::HEAVY, self::LANDER]]);
        $move = $this->turn($raw);

        self::assertSame('ride', $move['verb']);
        self::assertSame(['verb' => 'land_moon', 'args' => []], $move['then']);
        self::assertStringContainsString('helium3 0/1058', $move['why']);

        self::assertSame(['verb' => 'move', 'args' => ['x' => 77, 'y' => 140]], array_intersect_key($this->turn($this->world(['vehicles' => [self::LANDER], 'position' => [70, 130]])), ['verb' => 1, 'args' => 1]), 'walk to it first');
        self::assertArrayNotHasKey('then', (array) $this->turn($this->world(['vehicles' => [self::LANDER], 'in_space' => true, 'altitude' => 586])), 'from orbit, down first');
    }

    public function testOnTheMoonItMinesThenLeavesWithAMargin(): void
    {
        $moon = static fn(int $he): array => ['vehicles' => [self::LANDER], 'in_space' => true, 'altitude' => 590,
            'inventory' => ['helium3' => $he], 'expansion' => ['place' => ['where' => 'moon_surface']]];

        self::assertSame(['verb' => 'mine', 'args' => ['n' => 6]], array_intersect_key($this->turn($this->world($moon(500))), ['verb' => 1, 'args' => 1]));
        self::assertSame('mine', $this->turn($this->world($moon(1_058 + ReachRun::MARGIN - 1)))['verb'], 'past the target, into the margin');
        self::assertSame('ride', $this->turn($this->world($moon(1_058 + ReachRun::MARGIN)))['verb'], 'then down the elevator');
    }

    public function testFuelledTheTripIsLeftToOrdinaryFlight(): void
    {
        $raw = $this->world(['vehicles' => [self::HEAVY, self::LANDER], 'inventory' => ['helium3' => 1_100]]);

        self::assertTrue(ReachRun::plan($raw, ['deimos', 'mars'], ['triton'])['ready']);
        self::assertNull($this->turn($raw));

        // 700 gets there (the departure burns 571) but leaves 116 for a way
        // home that needs 480: not ready, back to the Moon.
        $oneWay = $this->world(['vehicles' => [self::LANDER], 'inventory' => ['helium3' => 700]]);
        self::assertTrue(GameData::leg(295, 600, 700, 260, 260)['ok']);
        self::assertFalse(ReachRun::plan($oneWay, ['deimos', 'mars'], ['triton'])['ready']);
        self::assertSame('ride', $this->turn($oneWay)['verb']);
        self::assertNull($this->turn($this->world(['expansion' => ['transit' => ['to' => 'triton', 'eta_in' => 200]]])), 'nor in transit');
    }

    public function testNoRunForAFinishedFoundedOrUnreachableBody(): void
    {
        self::assertNull(ReachRun::plan($this->world(), ['triton'], ['triton']), 'finished');
        self::assertNull(ReachRun::plan($this->world(), [], []), 'founded: credits from home');
        self::assertNull(ReachRun::plan($this->world(['expansion' => ['windows' => ['triton' => ['dv_need' => 320]]]]), [], ['triton']), 'past the ceiling');
    }
}
