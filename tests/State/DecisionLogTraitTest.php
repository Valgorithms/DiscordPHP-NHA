<?php

declare(strict_types=1);

use NHA\StateStore;

class DecisionLogTraitTest extends NHAUnitTestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nha-dlog-' . uniqid() . '/state.json';
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            rmdir($dir);
        }
    }

    /**
     * The same proposal blocked again is one lesson with a count, not a list
     * of copies crowding out a different one — whatever order its args came in.
     *
     * @covers \NHA\State\DecisionLogTrait
     */
    public function testARepeatedVetoIsCountedOnceNewestFirst(): void
    {
        $store = new StateStore($this->path);
        $store->recordVeto(7, 'finalize', [], 'not finalizing — no cockpit', 100);
        $store->recordVeto(7, 'combine', ['ingredients' => ['ice' => 1, 'ore' => 1]], 'spent', 110);
        $store->recordVeto(7, 'finalize', [], 'not finalizing — no cockpit (no control)', 120);
        $store->recordVeto(7, 'combine', ['ingredients' => ['ore' => 1, 'ice' => 1]], 'spent again', 130);

        $vetoes = (new StateStore($this->path))->recentVetoes(7, 140);

        self::assertSame(['combine', 'finalize'], array_column($vetoes, 'verb'), 'newest first, one row each');
        self::assertSame([2, 2], array_column($vetoes, 'count'));
        self::assertSame('spent again', $vetoes[0]['why'], 'the newest reason is kept');
        self::assertSame([10, 20], array_column($vetoes, 'ago'));
    }

    /**
     * A block is advice about the situation it happened in. Once that is long
     * gone (a cockpit built since, say) it should stop arguing.
     *
     * @covers \NHA\State\DecisionLogTrait
     */
    public function testAnOldVetoFallsOutOfView(): void
    {
        $store = new StateStore($this->path);
        $store->recordVeto(7, 'finalize', [], 'no cockpit', 1_000);

        self::assertCount(1, $store->recentVetoes(7, 1_599));
        self::assertSame([], $store->recentVetoes(7, 1_600));
        self::assertSame([], $store->recentVetoes(8, 1_001), 'per agent');
    }

    /**
     * @covers \NHA\State\DecisionLogTrait
     */
    public function testOnlyTheLatestFewDistinctVetoesAreKept(): void
    {
        $store = new StateStore($this->path);
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $i => $part) {
            $store->recordVeto(7, 'build', ['part' => $part], 'no', 100 + $i);
        }

        $kept = array_map(static fn(array $v): string => $v['args']['part'], $store->recentVetoes(7, 110, 10));
        self::assertSame(['h', 'g', 'f', 'e', 'd', 'c'], $kept);
    }

    /**
     * @covers \NHA\State\DecisionLogTrait
     */
    public function testTheSourceAndTheReplacedProposalAreReadBack(): void
    {
        $store = new StateStore($this->path);
        $store->recordDecision(7, ['verb' => 'combine', 'args' => [], 'reason' => 'r', 'tick' => 5,
            'source' => 'override', 'proposed' => ['verb' => 'finalize', 'args' => []]]);

        $last = (new StateStore($this->path))->getLastDecision(7);
        self::assertSame('override', $last['source']);
        self::assertSame(['verb' => 'finalize', 'args' => []], $last['proposed']);

        $store->recordDecision(7, ['verb' => 'mine', 'args' => [], 'reason' => '', 'tick' => 6]);
        self::assertArrayNotHasKey('proposed', $store->getLastDecision(7), 'an older record does not leak into a newer one');
    }
}
