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
use NHA\Brain\PromptBuilder;
use NHA\Parts\AgentObservation;
use PHPUnit\Framework\TestCase;

/**
 * The difference between a destination that is WAITING (its window is shut)
 * and one that is BLOCKED (its gear will never appear), and everything that
 * hangs off telling them apart.
 *
 * Every fixture here is the live shape of agent #142285 on 2026-09-25, which
 * had spent days in Earth orbit "holding for a transfer window to open" for
 * Triton — the last body with colony work — whose window was never the
 * obstacle. It needs a thermal_core, and nothing was ever going to make one.
 *
 * @covers \NHA\Brain\Bodies
 * @covers \NHA\Brain\Ladder
 */
final class ReachabilityTest extends TestCase
{
    private const DONE = ['deimos', 'phobos', 'mars', 'venus', 'enceladus', 'titan'];

    /**
     * @param array<string,int> $inv
     *
     * @return array<string,mixed>
     */
    private function world(array $inv = [], bool $inSpace = true, int $alt = 500): array
    {
        $d = static fn(array $gear, bool $landing, float $twr, int $fix): array => [
            'needs_in_hold' => $gear, 'needs_landing_gear_on_ship' => $landing,
            'min_thrust_to_weight' => $twr, 'course_correction_fuel' => $fix,
            'ready' => false, 'blockers' => [],
        ];

        return [
            'tick' => 1_829_360, 'position' => [77, 140], 'in_space' => $inSpace, 'altitude' => $alt,
            'vehicles' => [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true]],
            'loose_parts' => [], 'nearby_deposits' => [],
            'inventory' => $inv + ['credits' => 418017, 'cryo_fuel' => 580, 'heat_shield' => 1, 'acid_skin' => 1,
                'c_regolith' => 3505412, 'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5],
            'expansion' => [
                'at_body' => null, 'location' => 'earth',
                'windows' => [
                    'deimos' => ['open' => false, 'dv_need' => 50, 'opens_in' => 537],
                    'phobos' => ['open' => false, 'dv_need' => 55, 'opens_in' => 537],
                    'mars' => ['open' => false, 'dv_need' => 100, 'opens_in' => 537],
                    'venus' => ['open' => false, 'dv_need' => 130, 'opens_in' => 329],
                    'enceladus' => ['open' => true, 'dv_need' => 190, 'opens_in' => 0],
                    'titan' => ['open' => true, 'dv_need' => 220, 'opens_in' => 0],
                    'triton' => ['open' => false, 'dv_need' => 320, 'opens_in' => 457],
                ],
                'preflight' => ['destinations' => [
                    'deimos' => $d([], true, 0.5, 4),
                    'phobos' => $d([], true, 0.5, 4),
                    'mars' => $d(['heat_shield'], true, 0.7, 4),
                    'venus' => $d(['heat_shield', 'acid_skin'], false, 0.9, 2),
                    'enceladus' => $d(['thermal_core'], true, 0.4, 8),
                    'titan' => $d(['thermal_core', 'heat_shield'], true, 0.8, 9),
                    'triton' => $d(['thermal_core'], true, 0.4, 13),
                ]],
                'gates' => ['linked_from_here' => ['deimos', 'enceladus', 'mars', 'phobos', 'titan', 'venus']],
            ],
        ];
    }

    /**
     * Gear with a crafting rung is a detour; gear without one is a wall. Venus
     * was once wrongly written off for needing acid_skin — which HAS a rung —
     * so that line must keep counting as reachable even when none is in hand.
     */
    public function testGearNoRungMakesIsAWallButCraftableGearIsNot(): void
    {
        $bare = $this->world(['heat_shield' => 0, 'acid_skin' => 0]);

        self::assertTrue(Bodies::reachable($bare, 'venus'), 'acid_skin has a rung — a detour, not a wall');
        self::assertTrue(Bodies::reachable($bare, 'mars'));
        self::assertFalse(Bodies::reachable($bare, 'triton'), 'no rung makes a thermal_core');
        self::assertSame(['thermal_core'], Bodies::missingGear($bare, 'titan'), 'heat_shield is craftable, so only the core is missing');

        self::assertTrue(Bodies::reachable($this->world(['thermal_core' => 1]), 'triton'), 'and one in hand opens it');
    }

    /** Warp gates are read from the observation, not assumed. */
    public function testGatesAreReadFromTheObservation(): void
    {
        self::assertTrue(Bodies::gateLinked($this->world(), 'deimos'));
        self::assertFalse(Bodies::gateLinked($this->world(), 'triton'), 'the one body no gate reaches');
    }

    /**
     * THE BUG. "No departable target right now" was treated as "wait for a
     * window" — true forever once nothing reachable had work left, so the
     * agent held in orbit for days and, holding, switched off the loop guard
     * that would otherwise have escalated to the model.
     */
    public function testNothingReachableWithWorkLeftIsNotAHold(): void
    {
        self::assertFalse(
            Ladder::isHoldingForWindow($this->world(), 'expansionist', self::DONE),
            'Triton needs gear nothing makes; the rest are finished — there is no window to wait for',
        );
    }

    /** …but a reachable body with work, behind a shut window, still is. */
    public function testAReachableBodyBehindAShutWindowIsStillAHold(): void
    {
        self::assertTrue(Ladder::isHoldingForWindow(
            $this->world(),
            'expansionist',
            ['phobos', 'mars', 'venus', 'enceladus', 'titan'],   // deimos still has work
        ));
    }

    /**
     * The hold check was passed the HULL-rejections list only, so a colony
     * finished weeks ago still counted as a reason to wait. That is a matter of
     * which list the caller passes; the method honours whichever it is given.
     */
    public function testAFinishedColonyIsNotAReasonToHold(): void
    {
        $gearedForAll = $this->world(['thermal_core' => 1]);
        // Shut the two open windows: with a core in hand those would be
        // departable RIGHT NOW, which is a departure, not a hold.
        $gearedForAll['expansion']['windows']['enceladus']['open'] = false;
        $gearedForAll['expansion']['windows']['enceladus']['opens_in'] = 300;
        $gearedForAll['expansion']['windows']['titan']['open'] = false;
        $gearedForAll['expansion']['windows']['titan']['opens_in'] = 300;

        self::assertTrue(Ladder::isHoldingForWindow($gearedForAll, 'expansionist', []), 'with nothing skipped, Triton is worth a wait');
        self::assertTrue(Ladder::isHoldingForWindow($gearedForAll, 'expansionist', self::DONE), 'and still is with the finished ones skipped');
        self::assertFalse(
            Ladder::isHoldingForWindow($this->world(), 'expansionist', self::DONE),
            'but not once Triton is out of reach too',
        );
    }

    /**
     * The model's `depart deimos`, window shut 537 ticks, after the gate had
     * already refused the agent's 3.5M units of body cargo. The engine would
     * refuse it again; nothing has changed.
     */
    public function testAGatedDepartIsRefusedOnceTheGateHasRefusedTheCargo(): void
    {
        self::assertNull(Ladder::departRefusal($this->world(), 'deimos', [], false), 'before any refusal the gate may carry it');
        self::assertStringContainsString(
            'fly it the long way',
            (string) Ladder::departRefusal($this->world(), 'deimos', [], true),
        );
    }

    /** No gate and a shut window → the engine refuses; say so before filing. */
    public function testAShutWindowWithNoGateIsRefused(): void
    {
        self::assertStringContainsString('no gate', (string) Ladder::departRefusal($this->world(['thermal_core' => 1]), 'triton', []));
    }

    /** Gear judged on what is IN HOLD — the engine does not accept "craftable". */
    public function testArrivalGearIsJudgedOnWhatIsActuallyInHold(): void
    {
        $open = $this->world(['heat_shield' => 0]);
        $open['expansion']['windows']['mars']['open'] = true;

        self::assertStringContainsString('heat_shield', (string) Ladder::departRefusal($open, 'mars', []));
        self::assertStringContainsString('thermal_core', (string) Ladder::departRefusal($this->world(), 'titan', []));
    }

    /**
     * The gate judges only what the ENGINE would refuse — never whether a trip
     * is worth it. An earlier cut refused a finished colony as "nothing left to
     * fund", which would veto the very plan the model can now see: fly to Mars
     * (finished) for the mars_ice a thermal_core needs, to reach Triton.
     */
    public function testAFinishedColonyIsStillALegalTripForItsResources(): void
    {
        $marsOpen = $this->world();
        $marsOpen['expansion']['windows']['mars']['open'] = true;

        self::assertNull(
            Ladder::departRefusal($marsOpen, 'mars', [], true),
            'mars is finished, but it is the only source of mars_ice — going there is strategy, not a refusal',
        );
    }

    /** A hull the engine already refused for a body stays refused. */
    public function testAHullTheEngineRefusedIsRefusedAgain(): void
    {
        $open = $this->world();
        $open['expansion']['windows']['venus']['open'] = true;

        self::assertStringContainsString('already refused this hull', (string) Ladder::departRefusal($open, 'venus', ['venus']));
    }

    /**
     * `departTarget()` was called bare — no skip list — directly under a
     * comment warning that a bare call "silently defaults to an empty skip
     * list". So the ladder itself would fly to a colony finished weeks ago the
     * moment its window opened.
     *
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testTheLadderNeverDepartsForAFinishedColony(): void
    {
        $deimosOpen = $this->world(['thermal_core' => 0]);
        $deimosOpen['expansion']['windows']['deimos']['open'] = true;

        $step = Ladder::suggestion($deimosOpen, [], [], false, 'expansionist', self::DONE);
        self::assertTrue($step === null || $step['verb'] !== 'depart', 'deimos is done — its open window is not an invitation');
    }

    /**
     * Nowhere reachable has work, and the agent is on the ground with a hull.
     * The on-ground branch used to read "no depart-capable ship" as "no ship"
     * and gear up a second flyer — to solve a problem no hull can solve.
     *
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testNowhereToGoDoesNotGearUpASecondHull(): void
    {
        $grounded = $this->world([], false, 0);
        $grounded['_colony_done'] = self::DONE;

        $step = Ladder::suggestion($grounded, [], [], false, 'expansionist', self::DONE);
        self::assertTrue(
            $step === null || ! in_array($step['verb'], ['build', 'finalize'], true),
            'Triton wants a thermal_core; a new hull does not supply one',
        );
    }

    /** And in orbit, the reason it gives for coming down is the true one. */
    public function testComingDownSaysWhyTruthfully(): void
    {
        $orbit = $this->world();
        $orbit['_colony_done'] = self::DONE;

        $step = Ladder::suggestion($orbit, [], [], false, 'expansionist', self::DONE);
        self::assertNotNull($step);
        self::assertStringNotContainsString('no ship', $step['why'], 'there IS a ship — there is nowhere to take it');
        self::assertStringContainsString('nowhere reachable', $step['why']);
    }

    /**
     * What the model is shown. It used to get "titan OPEN" and nothing else —
     * not the gear, not that a colony was done, not the gate, not the recipe.
     *
     * @covers \NHA\Brain\PromptBuilder::build
     */
    public function testTheModelIsShownWhatIsActuallyBlockingEachDestination(): void
    {
        $prompt = PromptBuilder::build(new AgentObservation(142285, $this->world()), [
            'stance' => 'expansionist',
            'depart_skip' => self::DONE,
            'colony_done' => self::DONE,
            'gate_refuses_cargo' => true,
            'recipes' => ['thermal_core' => 'a battery + mars_ice + a NON-magnetic metal', 'battery' => '2 different metals + an electrolyte'],
            'rejections' => [['verb' => 'depart', 'ago' => 117, 'result' => 'a warp gate carries body cargo only in stasis crates: … fly it the long way.']],
            'recent' => [],
        ]);

        self::assertMatchesRegularExpression('/triton: .*thermal_core \(MISSING\).*has colony work/u', $prompt, 'the one body with work, and exactly what blocks it');
        self::assertMatchesRegularExpression('/deimos: .*DONE — nothing to fund/u', $prompt, 'so it stops proposing finished colonies');
        self::assertStringContainsString('warp gate joins it', $prompt);
        self::assertStringContainsString('REFUSED your body cargo', $prompt);
        self::assertStringContainsString('thermal_core = a battery + mars_ice', $prompt, 'the recipe, in the codex\'s own words');
        self::assertStringContainsString('battery = 2 different metals', $prompt, 'and the sub-recipe it names');
        self::assertStringContainsString('Recent REJECTIONS', $prompt, 'refusals persist past a single turn');
        self::assertStringContainsString('invest{body,module,credits}', $prompt, 'and the way out that needs no travel at all');
        self::assertDoesNotMatchRegularExpression('/SUGGESTED next action: depart/u', $prompt, 'never steered toward a refused trip');
    }

    /** An overrun hold is named as a failure, not left as a silent wait. */
    public function testAHoldThatOverranItsEstimateIsNamedAsFailed(): void
    {
        $prompt = PromptBuilder::build(new AgentObservation(142285, $this->world()), ['hold_overrun' => 1900, 'recent' => []]);

        self::assertStringContainsString('HOLD FAILED', $prompt);
        self::assertStringContainsString('1900 ticks', $prompt);
    }
}
