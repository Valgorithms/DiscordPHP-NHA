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
use NHA\Brain\Referee;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NHA\Brain\Referee
 */
final class RefereeTest extends TestCase
{
    /**
     * Agent #142285's real milestone record, read from `GET /agent/142285` on
     * 2026-09-21. Every reason string below is the referee's own wording.
     *
     * @return array<string,mixed>
     */
    private function profile(): array
    {
        $reject = static fn(int $t, string $sig, string $why): array => [
            'tick' => $t, 'kind' => 'reject', 'data' => ['sig' => $sig, 'reason' => $why],
        ];

        return [
            'discoveries' => [
                ['name' => 'Nickel Ore Ingot', 'points' => 12],
                ['name' => 'Silicon Ore Alloy', 'points' => 12],
                ['name' => 'Medicinal Potion', 'points' => 12],
                ['name' => 'Healing Salve', 'points' => 12],
            ],
            'milestones' => [
                $reject(1610131, 'herb,wire', "The combination of 'herb' and 'wire' does not suggest a plausible crafted item. The properties 'organic' and 'medicinal' from the herb do not combine well with 'metal', 'conductivity', and 'shaped'"),
                $reject(1608441, 'composite,healing_salve', "The 'composite' has a 'shaped' tag, but 'healing_salve' does not have any tags. There is no clear combination that would result in a new item."),
                $reject(1607865, 'carbon,healing_salve', "The ingredients lack sufficient shared properties. Carbon is flammable and has energy, while healing salve has no defined properties."),
                $reject(1607703, 'c_regolith,healing_salve', 'Regolith is a loose soil, and healing salve is a medicinal ointment. There is no clear synergy.'),
                $reject(1607283, 'brine,healing_salve', 'Brine is a solvent, but healing salve has no properties that would interact with it.'),
                ['tick' => 1606915, 'kind' => 'invent', 'data' => ['item' => 'healing_salve', 'name' => 'Healing Salve', 'guild' => true, 'points' => 12]],
                $reject(1606615, 'herb,silicon', "The combination of 'herb' and 'silicon' does not suggest a plausible crafting outcome."),
                $reject(1606014, 'herb,ore', 'The combination of ore and herb does not logically form a coherent item.'),
                $reject(1605352, 'herb,nickel', 'The combination of a herb and nickel does not intuitively suggest a coherent crafted item.'),
                $reject(1604912, 'herb,metal', "The combination of 'herb' and 'metal' does not intuitively form a coherent item."),
                $reject(1601984, 'glass,herb', 'The combination of herb and glass does not seem to create a coherent item.'),
                $reject(1597255, 'composite,herb', "The ingredients are too disparate. 'herb' suggests organic and medicinal properties, while 'composite' implies artificial and hard materials."),
                $reject(1596149, 'carbon,herb', 'Herb is organic and medicinal, while carbon is flammable and energetic.'),
                $reject(1595983, 'c_regolith,herb', 'Herb is organic and medicinal, while c_regolith is carbon and dusty.'),
                ['tick' => 1599063, 'kind' => 'invent', 'data' => ['item' => 'medicinal_potion', 'name' => 'Medicinal Potion', 'guild' => true, 'points' => 12]],
                $reject(1481668, 'ore,wire', 'Ore is a raw material, and wire is a processed material.'),
                $reject(1481133, 'ore,water', 'Ore is a solid material, while water is a liquid.'),
                $reject(1479360, 'ore,salt', 'The combination of ore and salt does not seem to form a coherent new item.'),
                $reject(1477538, 'metal,ore', "'ore' and 'metal' are too general."),
                $reject(1475237, 'ice,ore', 'The combination of ice and ore does not suggest a clear crafting outcome.'),
                $reject(1474218, 'glass,ore', "The tags 'ore' and 'insulator'/'transparent' do not combine naturally."),
                ['tick' => 1479976, 'kind' => 'invent', 'data' => ['item' => 'silicon_ore_alloy', 'name' => 'Silicon Ore Alloy', 'guild' => true, 'points' => 12]],
                ['tick' => 1478213, 'kind' => 'invent', 'data' => ['item' => 'nickel_ore_ingot', 'name' => 'Nickel Ore Ingot', 'guild' => true, 'points' => 12]],
                ['tick' => 1389250, 'kind' => 'build', 'data' => ['shape' => 'pyramid', 'builder_points' => 3]],
            ],
        ];
    }

    /**
     * The single most expensive lesson in the record: a crafted item carries no
     * tags, so it can never be an ingredient. The agent invented
     * `medicinal_potion`, tried it against composite/carbon/c_regolith/brine
     * and was refused four times — then invented `healing_salve` and tried the
     * SAME four partners for the same four refusals. Eight filings, 400
     * credits, one rule learned twice.
     */
    public function testACraftedItemIsNeverAnIngredientAgain(): void
    {
        $lore = Referee::lore($this->profile());

        foreach (['healing_salve', 'medicinal_potion', 'silicon_ore_alloy', 'nickel_ore_ingot'] as $item) {
            self::assertContains($item, $lore['tagless'], "{$item} is a crafted output");
        }
        foreach (['composite', 'carbon', 'c_regolith', 'brine'] as $partner) {
            self::assertFalse(
                Referee::worthFiling([$partner, 'healing_salve'], $lore),
                "{$partner}+healing_salve was already refused, on the record, for a reason that cannot change",
            );
        }
        self::assertStringContainsString(
            'carries no tags',
            (string) Referee::refusalNote(['brine', 'medicinal_potion'], $lore),
        );
    }

    /**
     * The referee names the tagless item mid-sentence — "…, while healing salve
     * has no defined properties" — and a greedy capture turns that into
     * `while_healing_salve`, which matches nothing and silently disables the
     * rule.
     */
    public function testTheTaglessItemIsReadWithoutTheGrammarAroundIt(): void
    {
        $lore = Referee::lore($this->profile());

        self::assertContains('healing_salve', $lore['tagless']);
        self::assertNotContains('while_healing_salve', $lore['tagless']);
        self::assertNotContains('but_healing_salve', $lore['tagless']);
    }

    /**
     * Nine filings against `herb` — wire, silicon, ore, nickel, metal, glass,
     * composite, carbon, c_regolith — and not one grant. That is not bad luck,
     * it is an answer.
     */
    public function testAnIngredientThatOnlyEverFailsIsWrittenOff(): void
    {
        $lore = Referee::lore($this->profile());

        self::assertContains('herb', $lore['exhausted']);
        self::assertFalse(Referee::worthFiling(['herb', 'titanium'], $lore), 'a fresh partner does not rescue it');
        self::assertStringContainsString('never granted', (string) Referee::refusalNote(['herb', 'titanium'], $lore));
    }

    /**
     * …but `ore` must survive the same test, and this is the case that makes
     * the whole class worth writing carefully.
     *
     * `ore` was refused six times (wire, water, salt, metal, ice, glass) — past
     * any threshold — while ALSO being the ingredient in both granted recipes,
     * `nickel_ore_ingot` and `silicon_ore_alloy`. The `invent` milestones record
     * only the OUTPUT, so matching on those alone writes off the single most
     * productive ingredient the agent has.
     */
    public function testTheMostProductiveIngredientIsNotWrittenOffForAlsoFailing(): void
    {
        $lore = Referee::lore($this->profile());

        self::assertNotContains('ore', $lore['exhausted'], 'ore produced BOTH granted recipes');
        self::assertTrue(Referee::worthFiling(['ore', 'titanium'], $lore));
        self::assertTrue(Referee::worthFiling(['ore', 'nickel'], $lore), 'and an untried ore pair is still worth a filing');
    }

    /**
     * The world codex vouches for an ingredient we have no verdict history on
     * — somebody made something with it, so it is usable.
     */
    public function testTheCodexVouchesForAnIngredientWeHaveNoHistoryOn(): void
    {
        $lore = Referee::lore($this->profile(), ['copper+tin' => true]);

        self::assertContains('copper', $lore['productive']);
        self::assertContains('tin', $lore['productive']);
    }

    /**
     * …but it must never overrule OUR OWN record, and this is the subtle one.
     *
     * The live codex holds 138 recipes, so very nearly every raw material
     * appears in it somewhere — including `herb`. Letting that count as
     * productive marks everything productive and silently disables the
     * exhaustion rule altogether, which is precisely the nine-filing herb sweep
     * it exists to stop. "Is this pair already invented" is the codex's
     * question, and the caller asks it separately against `$worldKnown`; "has
     * the referee been telling US no" is not something the codex can answer.
     */
    public function testTheCodexCannotRescueAnIngredientOurOwnRefereeKeepsRefusing(): void
    {
        $lore = Referee::lore($this->profile(), [
            'herb+moss' => true,        // somebody, somewhere, made this work
            'carbon+resin' => true,
        ]);

        self::assertContains('herb', $lore['exhausted'], 'nine refusals against us outrank one stranger’s recipe');
        self::assertFalse(Referee::worthFiling(['herb', 'titanium'], $lore));
        self::assertNotContains('ore', $lore['exhausted'], 'and ore is still safe on its own grants');
    }

    /** A pair the Guild has already ruled on is never re-filed. */
    public function testASignatureAlreadyRefusedIsNotFiledAgain(): void
    {
        $lore = Referee::lore($this->profile());

        self::assertFalse(Referee::worthFiling(['ice', 'ore'], $lore));
        self::assertFalse(Referee::worthFiling(['ore', 'ice'], $lore), 'order does not make it new');
    }

    /** With nothing learned yet, the referee IS the teacher — file away. */
    public function testWithNoRecordEveryPairIsStillWorthTrying(): void
    {
        self::assertTrue(Referee::worthFiling(['herb', 'wire'], []));
        self::assertTrue(Referee::worthFiling(['anything', 'else'], Referee::lore([])));
    }

    /**
     * And the ladder actually honours it: a surplus pair the referee has ruled
     * out is passed over for one it has not.
     *
     * @covers \NHA\Brain\Ladder::speculativeCombine
     */
    public function testTheLadderSkipsAPairTheRefereeHasRuledOut(): void
    {
        $lore = Referee::lore($this->profile());
        $raws = ['herb' => 400, 'healing_salve' => 400, 'titanium' => 400, 'copper' => 400];

        $blind = Ladder::speculativeCombine($raws, [], [], []);
        self::assertNotNull($blind);

        $learned = Ladder::speculativeCombine($raws, [], [], [], $lore);
        self::assertNotNull($learned, 'there is still a clean pair to try');

        $ingredients = array_keys((array) $learned['args']['ingredients']);
        self::assertNotContains('healing_salve', $ingredients, 'a crafted item is never an ingredient');
        self::assertNotContains('herb', $ingredients, 'and a written-off one is not either');
        self::assertSame(['copper', 'titanium'], $ingredients);
    }

    /** When every pair is ruled out, it proposes nothing rather than guessing. */
    public function testWithNothingLeftWorthFilingItProposesNoCombine(): void
    {
        $lore = Referee::lore($this->profile());

        self::assertNull(Ladder::speculativeCombine(
            ['herb' => 400, 'healing_salve' => 400, 'medicinal_potion' => 400],
            [],
            [],
            [],
            $lore,
        ));
    }
}
