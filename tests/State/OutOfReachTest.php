<?php

declare(strict_types=1);

use NHA\StateStore;

class OutOfReachTest extends NHAUnitTestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nha-reach-' . uniqid() . '/state.json';
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
     * @covers \NHA\State\LoopStrategyStateTrait::recordOutOfReach
     * @covers \NHA\State\LoopStrategyStateTrait::outOfReachNeeds
     */
    public function testTheNeedIsKeptWithTheBody(): void
    {
        $store = new StateStore($this->path);
        $store->recordOutOfReach(7, 'triton', 320);
        $store->recordOutOfReach(7, 'triton');

        $reloaded = new StateStore($this->path);
        self::assertSame(['triton' => 320], $reloaded->outOfReachNeeds(7), 'an unknown need does not erase a known one');
        self::assertSame(['triton'], $reloaded->outOfReach(7));
    }

    /**
     * 3.21.0 wrote a bare list; it still reads, as bodies with an unknown need.
     *
     * @covers \NHA\State\LoopStrategyStateTrait::outOfReachNeeds
     */
    public function testTheOldListStillReads(): void
    {
        @mkdir(dirname($this->path), 0o777, true);
        file_put_contents($this->path, json_encode(['agent_out_of_reach' => ['7' => ['triton']]]));

        self::assertSame(['triton' => 0], (new StateStore($this->path))->outOfReachNeeds(7));
    }

    /**
     * @covers \NHA\State\LoopStrategyStateTrait::clearOutOfReach
     * @covers \NHA\State\CapabilityLedgerTrait::clearCapability
     */
    public function testBringingABodyBackClearsEveryPlaceItWasParked(): void
    {
        $store = new StateStore($this->path);
        $store->recordOutOfReach(7, 'triton', 320);
        $store->recordDepartRejection(7, 'triton', 100, true);
        $store->recordDepartRejection(7, 'venus', 101, true);
        $store->recordCapability(7, 'depart:triton', 'capability', 'Δv', 100);

        $store->clearOutOfReach(7, 'triton');
        $store->clearCapability(7, 'depart:triton');

        self::assertSame([], $store->outOfReach(7));
        self::assertSame(['venus'], $store->departUnreachable(7), 'only that body');
        self::assertSame([], $store->capabilityTargets(7, 'depart'));
    }
}
