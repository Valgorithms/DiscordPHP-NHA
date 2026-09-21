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

use NHA\Brain\Ladder;
use NHA\Brain\Vault;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NHA\Brain\Vault
 */
final class VaultTest extends TestCase
{
    /**
     * The live Season 8 vault block, from `GET /vault` and an authenticated
     * `GET /observe/142285` on 2026-09-21.
     *
     * @param array<int|string,string> $own
     *
     * @return array<string,mixed>
     */
    private function world(array $own = [], string $place = 'earth', array $pos = [77, 140]): array
    {
        return [
            'tick' => 1_423_270, 'position' => $pos, 'in_space' => false, 'altitude' => 0,
            'vehicles' => [], 'loose_parts' => [], 'nearby_deposits' => [],
            'inventory' => ['credits' => 500],
            'expansion' => [
                // Live Earth reports `location: earth` and never `body_surface`
                // — only a real body does — so the fixture must not either.
                'at_body' => $place === 'earth' ? null : $place,
                'location' => $place === 'earth' ? 'earth' : 'on_' . $place,
                'place' => $place === 'earth'
                    ? ['where' => 'earth', 'body' => null]
                    : ['where' => 'body_surface', 'body' => $place],
                'vault' => [
                    'open' => false, 'where' => 'titan', 'symbols_in_seal' => 6,
                    'cooldown_ticks_left' => 0,
                    'alphabet' => ['spiral', 'triangle', 'crescent', 'eye', 'river', 'tower', 'seed', 'gate', 'flame', 'wave', 'knot', 'star'],
                    'your_fragments' => $own,
                    'obelisks' => [
                        ['position' => 1, 'place' => 'earth', 'x' => 59, 'y' => 47],
                        ['position' => 2, 'place' => 'mars', 'x' => null, 'y' => null],
                        ['position' => 3, 'place' => 'venus', 'x' => null, 'y' => null],
                        ['position' => 4, 'place' => 'phobos', 'x' => null, 'y' => null],
                        ['position' => 5, 'place' => 'enceladus', 'x' => null, 'y' => null],
                        ['position' => 6, 'place' => 'triton', 'x' => null, 'y' => null],
                    ],
                    'ask_these_agents' => [
                        '1' => ['Barbarian', 'Prospector'],
                        '4' => ['Trader'],
                        '6' => [],
                    ],
                ],
            ],
        ];
    }

    /**
     * An unauthenticated observe puts a "hidden: send your token" STRING where
     * the fragments go. That is not "I hold none" — it is "I did not ask
     * properly", and conflating the two has the agent re-walk stones it has
     * already read.
     */
    public function testAHiddenFragmentBlockIsNotTheSameAsHoldingNone(): void
    {
        $hidden = $this->world();
        $hidden['expansion']['vault']['your_fragments'] = 'hidden: call /observe with your agent token';

        self::assertFalse(Vault::authenticated($hidden));
        self::assertTrue(Vault::authenticated($this->world([])), 'an empty object is a real answer');
        self::assertNull(Vault::chatLine($hidden), 'and nothing is worth saying on unproven counts');
    }

    /** Positions we lack, counted from what the engine says we hold. */
    public function testItCountsTheMissingPositions(): void
    {
        self::assertSame([1, 2, 3, 4, 5, 6], Vault::missing($this->world([])));
        self::assertSame([2, 3, 5, 6], Vault::missing($this->world([1 => 'spiral', 4 => 'knot'])));
        self::assertSame([], Vault::missing($this->world([
            1 => 'spiral', 2 => 'tower', 3 => 'eye', 4 => 'knot', 5 => 'wave', 6 => 'star',
        ])));
    }

    /**
     * Reading takes no verb — the stone lights up on arrival — so the only
     * move is the walk, and only ever on the ground we already stand on.
     */
    public function testItWalksToAnObeliskOnTheGroundItIsAlreadyOn(): void
    {
        $step = Vault::walkStep($this->world([]));

        self::assertNotNull($step);
        self::assertSame('move', $step['verb']);
        self::assertSame(59, $step['args']['x']);
        self::assertSame(47, $step['args']['y']);
        self::assertStringContainsString('position 1', $step['why']);
    }

    /** Already read it → nothing to walk to. */
    public function testItDoesNotWalkToAStoneItHasAlreadyRead(): void
    {
        self::assertNull(Vault::walkStep($this->world([1 => 'spiral'])));
    }

    /**
     * It never books a flight. A fragment on another body is a free rider on a
     * trip the expansion ladder was already going to make for colony work — the
     * vault does not get to redirect the mission.
     */
    public function testItNeverTravelsToAnotherBodyForAFragment(): void
    {
        // Standing on Mars, missing everything; Earth's stone is the only one
        // with published coordinates, and it is a planet away.
        self::assertNull(Vault::walkStep($this->world([], 'mars', [10, 10])));
        // In transit there is no ground at all.
        $flying = $this->world([]);
        $flying['in_space'] = true;
        $flying['expansion']['at_body'] = null;
        self::assertNull(Vault::walkStep($flying));
    }

    /**
     * NEVER a guess. 12^6 is nearly three million, a wrong code freezes the
     * stone for 90 ticks, and a refusal says nothing about how close it was —
     * so a partial code is worth exactly zero attempts.
     */
    public function testItNeverGuessesAPartialCode(): void
    {
        $five = [1 => 'spiral', 2 => 'tower', 3 => 'eye', 4 => 'knot', 5 => 'wave'];

        self::assertNull(Vault::code($this->world($five, 'titan')));
        self::assertNull(Vault::unlockStep($this->world($five, 'titan')), 'five of six is not a code');
    }

    /** All six, standing on Titan → speak the seal, in order. */
    public function testWithAllSixOnTitanItSpeaksTheSeal(): void
    {
        $all = [1 => 'spiral', 2 => 'tower', 3 => 'eye', 4 => 'knot', 5 => 'wave', 6 => 'star'];
        $step = Vault::unlockStep($this->world($all, 'titan'));

        self::assertNotNull($step);
        self::assertSame('unlock', $step['verb']);
        self::assertSame(['spiral', 'tower', 'eye', 'knot', 'wave', 'star'], $step['args']['code'], 'in seal order');
    }

    /** The code is only speakable where the vault stands, and not while frozen. */
    public function testTheSealIsOnlySpokenOnTitanAndNotWhileFrozen(): void
    {
        $all = [1 => 'spiral', 2 => 'tower', 3 => 'eye', 4 => 'knot', 5 => 'wave', 6 => 'star'];

        self::assertNull(Vault::unlockStep($this->world($all, 'mars')), 'wrong body');

        $frozen = $this->world($all, 'titan');
        $frozen['expansion']['vault']['cooldown_ticks_left'] = 90;
        self::assertNull(Vault::unlockStep($frozen), 'the stone is cold');

        $opened = $this->world($all, 'titan');
        $opened['expansion']['vault']['open'] = true;
        self::assertNull(Vault::unlockStep($opened), 'already open');
    }

    /**
     * The social path is the intended one, and the board names who to ask — so
     * the line offers what we read and asks for what we lack, by name.
     */
    public function testItOffersWhatItReadAndAsksForWhatItLacks(): void
    {
        $line = Vault::chatLine($this->world([1 => 'spiral']));

        self::assertNotNull($line);
        self::assertStringContainsString('1=spiral', $line, 'say what you saw');
        self::assertStringContainsString('2,3,4,5,6', $line, 'and what you still need');
        self::assertStringContainsString('@Trader', $line, 'addressed to someone who actually holds one');
    }

    /** Nobody has read position 6 yet — say so rather than @-ing thin air. */
    public function testItSaysWhenNobodyHasReadAPositionYet(): void
    {
        $only6 = $this->world([
            1 => 'spiral', 2 => 'tower', 3 => 'eye', 4 => 'knot', 5 => 'wave',
        ]);
        $only6['expansion']['vault']['ask_these_agents'] = ['6' => []];

        self::assertStringContainsString('Nobody has read those yet', (string) Vault::chatLine($only6));
    }

    /**
     * And the ladder actually fires it: speaking the seal outranks everything,
     * and the obelisk walk beats the stockpile grind it replaces.
     *
     * @covers \NHA\Brain\Ladder::suggestion
     */
    public function testTheLadderSpeaksTheSealAboveAllOtherWork(): void
    {
        $all = [1 => 'spiral', 2 => 'tower', 3 => 'eye', 4 => 'knot', 5 => 'wave', 6 => 'star'];
        $raw = $this->world($all, 'titan');
        $raw['nearby_deposits'] = [['resource' => 'wood', 'dist' => 0, 'amount' => 15]];

        $step = Ladder::suggestion($raw, [], [], false, 'expansionist');
        self::assertNotNull($step);
        self::assertSame('unlock', $step['verb'], 'one turn, and it is the highest-value act in the world');
    }

    /** @covers \NHA\Brain\Ladder::suggestion */
    public function testTheLadderWalksToTheStoneRatherThanGrindingWood(): void
    {
        $raw = $this->world([]);
        $raw['nearby_deposits'] = [['resource' => 'wood', 'dist' => 0, 'amount' => 15]];
        // With the survival kit already in hand — a missing stimpack is a
        // real emergency and rightly outranks a stone that will keep.
        // The live "stuck" shape: a ship already built (so gearing it does not
        // rightly outrank a stone), fuel short of the goal with no credits and
        // no ice/oil to craft with, standing on a wood deposit. This is exactly
        // the state that used to produce four hours of `chop`.
        $raw['_fuel_goal'] = 570;
        $raw['vehicles'] = [['name' => 'flyer', 'flies' => true, 'orbital_engine' => true]];
        $raw['inventory'] = ['credits' => 2, 'wood' => 60, 'cryo_fuel' => 100, 'crystal' => 500,
            'metal' => 130, 'iron' => 60, 'copper' => 60, 'silicon' => 60, 'carbon' => 60,
            'aluminum' => 60, 'salt' => 60, 'water' => 60, 'wire' => 60, 'chip' => 20,
            'stimpack' => 1, 'kinetic_gun' => 1, 'slug' => 5];

        $step = Ladder::suggestion($raw, [], [], false, 'expansionist');
        self::assertNotNull($step);
        self::assertSame('move', $step['verb']);
        self::assertStringContainsString('obelisk', $step['why']);
    }
}
