<?php

declare(strict_types=1);

use NHA\Brain\Stance;

/**
 * One goal — the Solar Accord — so {@see Stance::rank()} has only two answers:
 * defend a live fight (`aggressive`), or drive the mission (`expansionist`).
 * `homestead` / `capitalist` are never ranked.
 */
class StanceTest extends NHAUnitTestCase
{
    /**
     * @covers \NHA\Brain\Stance
     */
    public function testRanksAggressiveOnARecentAttackWhenArmed(): void
    {
        $raw = [
            'tick' => 100, 'hp' => 90,
            'inventory' => ['kinetic_gun' => 1, 'slug' => 6],
            'alerts' => [['tick' => 90, 'kind' => 'attacked', 'by' => 7]],
        ];

        $this->assertSame(Stance::Aggressive, Stance::pick($raw, 'expansionist', 0));
    }

    /**
     * Being attacked while unarmed is not `aggressive` (nothing to fight back
     * with) — the mission stance still stands; the defend/flee rungs handle it.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testAnAttackWhileUnarmedDoesNotForceAggressive(): void
    {
        $raw = [
            'tick' => 100, 'hp' => 40, 'inventory' => ['metal' => 300, 'crystal' => 300],
            'alerts' => [['tick' => 95, 'kind' => 'attacked', 'by' => 7]],
        ];

        $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'expansionist', 0));
    }

    /**
     * A weak passer-by is NOT a reason to switch to aggressive — hunting does
     * not further the Accord.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testASoftTargetNearbyDoesNotRankAggressive(): void
    {
        $raw = [
            'tick' => 100, 'hp' => 100,
            'inventory' => ['kinetic_gun' => 1, 'slug' => 6, 'metal' => 300, 'crystal' => 300],
            'nearby_agents' => [['id' => 9, 'dist' => 8, 'hp' => 20]],
        ];

        $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'expansionist', 0));
    }

    /**
     * @covers \NHA\Brain\Stance
     */
    public function testRanksExpansionistInSpace(): void
    {
        $this->assertSame(
            Stance::Expansionist,
            // In space there is nothing to restock FROM, so a bare hold is
            // still the mission — see Stance::canResupply().
            Stance::pick(['tick' => 100, 'in_space' => true, 'altitude' => 450, 'inventory' => []], 'expansionist', 0),
        );
    }

    /**
     * Every non-combat state with the shelves STOCKED is the mission — geared
     * or bare of kit, rich or broke, a fat credit pile or a covered contract.
     * The expansionist ladder arms, stockpiles and banks a glut as tactics;
     * none of that is a separate stance. (An empty cupboard now is — see the
     * resupply-chain test below.)
     *
     * @covers \NHA\Brain\Stance
     */
    public function testEveryNonCombatStateRanksExpansionist(): void
    {
        $stocked = ['metal' => 300, 'crystal' => 300];
        $states = [
            'geared on Earth' => ['tick' => 100, 'inventory' => $stocked + ['kinetic_gun' => 1, 'slug' => 6, 'stimpack' => 1]],
            'bare on Earth' => ['tick' => 100, 'inventory' => $stocked + ['wood' => 20]],
            'fat pile + glut' => ['tick' => 100, 'inventory' => $stocked + ['credits' => 5000, 'brine' => 140]],
            'fat pile + covered contract' => [
                'tick' => 100,
                'inventory' => $stocked + ['credits' => 5000, 'iron' => 20],
                'contracts' => [['id' => 1, 'want' => ['iron' => 10]]],
            ],
            'fat pile, nothing to trade' => ['tick' => 100, 'inventory' => $stocked + ['credits' => 5000, 'iron' => 20]],
        ];

        foreach ($states as $label => $raw) {
            $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'expansionist', 0), $label);
        }
    }

    /**
     * The resupply chain: an empty cupboard on Earth's ground takes over the
     * turn, hands to research once the shelves are full, and hands back to the
     * mission when the grants dry up. Each leg tests its own exit, and the
     * entry/exit marks differ so it cannot flap.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testTheResupplyChainRunsRestockThenResearchThenTheMission(): void
    {
        $ground = static fn(array $inv): array => ['tick' => 100, 'altitude' => 0, 'inventory' => $inv, 'vehicles' => []];

        // Blocked on materials → restocking is the turn.
        self::assertSame(Stance::Quartermaster, Stance::pick($ground(['metal' => 3, 'crystal' => 40]), 'expansionist', 0));

        // Part-way back up, still under the exit mark → it does NOT hand back
        // early. This is the hysteresis: one threshold for both would return a
        // cupboard that is bare again a single build later.
        self::assertSame(Stance::Quartermaster, Stance::pick($ground(['metal' => 120, 'crystal' => 120]), 'quartermaster', 0));

        // Shelves full → research next, not straight back to the mission.
        $full = ['metal' => 300, 'crystal' => 300];
        self::assertSame(Stance::Researcher, Stance::pick($ground($full), 'quartermaster', 0));

        // Research pays and there is stock to cut a combine from → stay.
        self::assertSame(Stance::Researcher, Stance::pick($ground($full), 'researcher', 0, true));

        // Grants dry up → fly.
        self::assertSame(Stance::Expansionist, Stance::pick($ground($full), 'researcher', 0, false));

        // A USABLE ship outranks a thin cupboard — never ground a ready hull
        // through an open window over a metal count.
        $withShip = $ground(['metal' => 3, 'crystal' => 3]);
        $withShip['vehicles'] = [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true, 'fuel_cap' => 400]];
        self::assertSame(Stance::Expansionist, Stance::pick($withShip, 'expansionist', 0, false, true));

        // …but OWNING orbital hulls is not the same as having one that can
        // reach anywhere still worth going. The agent holds 48 of them and
        // cannot fly one to the only body left; restocking must still engage.
        self::assertSame(Stance::Quartermaster, Stance::pick($withShip, 'expansionist', 0, false, false));

        // …and neither does running dry mid-mission, where there is nothing to
        // restock from.
        $atBody = ['tick' => 100, 'inventory' => ['metal' => 1], 'expansion' => ['at_body' => 'mars']];
        self::assertSame(Stance::Expansionist, Stance::pick($atBody, 'expansionist', 0));
    }

    /**
     * A stored `homestead` / `capitalist` (from before this change, or written
     * mid-dwell) is corrected to the mission stance on the next pick — the
     * mission pre-empts the dwell timer, it does not wait 40 ticks.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testAStaleNonMissionStanceIsCorrectedImmediately(): void
    {
        $raw = ['tick' => 105, 'inventory' => ['wood' => 20, 'metal' => 300, 'crystal' => 300]];

        $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'homestead', 100));
        $this->assertSame(Stance::Expansionist, Stance::pick($raw, 'capitalist', 100));
    }

    /**
     * Aggressive pre-empts the dwell timer (a fight will not wait), and the
     * moment the threat is stale the mission stance resumes.
     *
     * @covers \NHA\Brain\Stance
     */
    public function testAggressivePreemptsAndThenHandsBackToTheMission(): void
    {
        $armed = ['kinetic_gun' => 1, 'slug' => 6, 'metal' => 300, 'crystal' => 300];

        $underFire = ['tick' => 105, 'hp' => 90, 'inventory' => $armed, 'alerts' => [['tick' => 104, 'kind' => 'attacked', 'by' => 7]]];
        $this->assertSame(Stance::Aggressive, Stance::pick($underFire, 'expansionist', 100));

        // 40+ ticks on with no fresh hit → back to the mission.
        $clear = ['tick' => 200, 'hp' => 90, 'inventory' => $armed, 'alerts' => [['tick' => 104, 'kind' => 'attacked', 'by' => 7]]];
        $this->assertSame(Stance::Expansionist, Stance::pick($clear, 'aggressive', 105));
    }
}
