<?php

declare(strict_types=1);

use NHA\Upstream\Drift;
use NHA\Upstream\Snapshot;

/**
 * Each change line says what the rule is now, not only that it moved.
 *
 * @covers \NHA\Upstream\Drift
 */
class DriftTest extends NHAUnitTestCase
{
    public function testNothingMovedIsNothing(): void
    {
        $views = ['updates' => [1 => ['tick' => 1, 'title' => 't', 'detail' => '', 'verb' => '']], 'source' => ['repo' => 'r', 'sha' => 'abc', 'date' => '', 'subject' => '']];

        self::assertSame([], Drift::compare($views, $views));
        self::assertSame([], Drift::compare($views, $views + ['rules' => ['note' => 'x']]), 'a source without a baseline is not compared');
    }

    public function testANewOperatorUpdateIsQuotedInFull(): void
    {
        $old = [16 => ['tick' => 1517244, 'title' => 'Gates', 'detail' => '', 'verb' => '']];
        $new = $old + [17 => ['tick' => 1533267, 'title' => 'THE VAULT HAS RE-SEALED ITSELF', 'detail' => "The door carries a NEW seal.\nEvery fragment was wiped.", 'verb' => 'unlock']];

        $lines = Drift::compare(['updates' => $old], ['updates' => $new])['updates'];

        self::assertSame(["New: **#17** (tick 1,533,267 · `unlock`) **THE VAULT HAS RE-SEALED ITSELF**\n  > The door carries a NEW seal.\n  > Every fragment was wiped."], $lines);

        $revised = $old;
        $revised[16]['detail'] = 'Gates now carry crates of 20.';
        self::assertSame(["Revised: **#16** (tick 1,517,244) **Gates**\n  > Gates now carry crates of 20."], Drift::compare(['updates' => $old], ['updates' => $revised])['updates'], 'an update edited in place');
    }

    public function testTheContractReportsNewEndpointsParametersAndRewording(): void
    {
        $doc = static fn(array $observeParams, string $intentDoc, array $extraPaths = []): array => Snapshot::openapi([
            'info' => ['version' => '3.0'],
            'paths' => [
                '/observe/{agent_id}' => ['get' => ['summary' => 'Observe', 'parameters' => $observeParams]],
                '/intent' => ['post' => ['summary' => 'Submit Intent', 'description' => $intentDoc]],
            ] + $extraPaths,
            'components' => ['schemas' => []],
        ]);
        $old = $doc([['name' => 'agent_id', 'in' => 'path', 'required' => true]], "Enqueue an action.\nApplied next tick.");
        $new = $doc(
            [['name' => 'agent_id', 'in' => 'path', 'required' => true], ['name' => 'token', 'in' => 'query']],
            "Enqueue an action.\nApplied next tick.\nRATE LIMIT — at most 40 pending.",
            ['/vault' => ['get' => ['summary' => 'Vault Ep', 'description' => 'The door on Titan.']]],
        );

        $lines = Drift::compare(['openapi' => $old], ['openapi' => $new])['openapi'];

        self::assertContains('`GET /observe/{agent_id}`: parameters +`query:token`', $lines);
        self::assertContains('New endpoint `GET /vault` — The door on Titan.', $lines);
        self::assertContains("`POST /intent` documentation reworded:\n  ```diff\n  + RATE LIMIT — at most 40 pending.\n  ```", $lines);
    }

    public function testAChangedBillShowsTheOldAndNewAmounts(): void
    {
        $bill = static fn(int $titanium): array => ['era' => 'expansion', 'bodies' => ['triton' => ['label' => 'Geyser Watch', 'modules' => [
            'geyser_mast' => ['label' => 'Geyser Mast', 'need' => ['superalloy' => 180, 'titanium' => $titanium]],
        ]]]];

        self::assertSame(
            ['`triton` module `geyser_mast`: need {"superalloy":180,"titanium":200} → {"superalloy":180,"titanium":150}'],
            Drift::compare(['colonies' => $bill(200)], ['colonies' => $bill(150)])['colonies'],
        );
    }

    public function testRecipeAndResourceChangesSayWhatTheyAreNow(): void
    {
        $old = ['note' => 'n', 'resources' => ['copper' => ['conductivity' => 9, 'metal' => 1]], 'recipes' => ['battery' => ['needs' => '2 metals', 'props' => ['energy' => 8]]]];
        $new = ['note' => 'n', 'resources' => ['copper' => ['conductivity' => 8, 'metal' => 1, 'soft' => 2]], 'recipes' => [
            'battery' => ['needs' => '2 metals + an electrolyte', 'props' => ['energy' => 8]],
            'fuse' => ['needs' => 'a conductor', 'props' => ['breaks' => 1]],
        ]];

        self::assertSame([
            'Resource `copper` tags: conductivity 9 → 8, +soft 2',
            'Recipe `battery`: needs "2 metals" → "2 metals + an electrolyte"',
            'New recipe `fuse`: needs "a conductor"; gives breaks 1',
        ], Drift::compare(['rules' => $old], ['rules' => $new])['rules']);
    }

    /**
     * `GameData` is transcribed from the engine's constants, so a new commit
     * names the ones its patch assigns.
     */
    public function testNewEngineCommitsNameTheConstantsTheyTouch(): void
    {
        $old = ['repo' => 'Recluse/nha-mmo', 'sha' => str_repeat('a', 40), 'date' => '2026-09-18T12:17:36Z', 'subject' => 'old'];
        $new = ['repo' => 'Recluse/nha-mmo', 'sha' => str_repeat('b', 40), 'date' => '2026-09-30T08:00:00Z', 'subject' => 'new'];
        $compare = [
            'commits' => [['sha' => str_repeat('c', 40), 'commit' => ['message' => "fix: Triton reachable\n\nLong body."]]],
            'files' => [
                ['filename' => 'engine/vehicles.py', 'additions' => 3, 'deletions' => 1, 'patch' => "@@ -1,3 +1,5 @@\n-DV_CEILING = 300\n+DV_CEILING = 330\n+PART: dict[str, int] = {\n+    x = 1\n+    if FOO == 2:\n     BODY_MINE = {}"],
                ['filename' => 'README.md', 'additions' => 1, 'deletions' => 0, 'patch' => '+Hello'],
            ],
        ];

        $lines = Drift::compare(['source' => $old], ['source' => $new], $compare)['source'];

        self::assertStringContainsString('https://github.com/Recluse/nha-mmo/compare/' . $old['sha'] . '...' . $new['sha'], $lines[0]);
        self::assertSame('Commit `ccccccc` fix: Triton reachable', $lines[1]);
        self::assertSame('File `engine/vehicles.py` (+3 −1), constants `DV_CEILING`, `PART`', $lines[2]);
        self::assertSame('File `README.md` (+1 −0)', $lines[3]);

        $lost = Drift::compare(['source' => $old], ['source' => $new], null)['source'];
        self::assertCount(2, $lost, 'a history GitHub cannot compare still reports the move');
    }
}
