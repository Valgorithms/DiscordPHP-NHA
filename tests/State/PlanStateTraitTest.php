<?php

declare(strict_types=1);

use NHA\StateStore;

class PlanStateTraitTest extends NHAUnitTestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nha-plan-' . uniqid() . '/state.json';
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

    private function planned(): StateStore
    {
        $store = new StateStore($this->path);
        $store->setPlan(7, 'found triton', ['make a battery', 'make a thermal_core', 'fly to triton'], 'why', 1_000, 'home');

        return $store;
    }

    /**
     * @covers \NHA\State\PlanStateTrait
     */
    public function testAPlanStartsAtItsFirstStepAndSurvivesAReload(): void
    {
        $this->planned();
        $plan = (new StateStore($this->path))->plan(7);

        self::assertSame('found triton', $plan['goal']);
        self::assertSame(0, $plan['step']);
        self::assertSame(1_000, $plan['set_at']);
        self::assertSame('home', $plan['where']);
        self::assertNull((new StateStore($this->path))->plan(8));
    }

    /**
     * A model that marks every turn "done" must not race through the plan.
     *
     * @covers \NHA\State\PlanStateTrait
     */
    public function testStepsAdvanceNoFasterThanTheDebounceAndStopAtTheEnd(): void
    {
        $store = $this->planned();

        self::assertNull($store->advancePlan(7, 1_010), 'too soon after the plan was set');
        self::assertSame(1, $store->advancePlan(7, 1_020));
        self::assertNull($store->advancePlan(7, 1_021), 'too soon after the last step');
        self::assertSame(2, $store->advancePlan(7, 1_040));
        self::assertFalse($store->planFinished(7));
        self::assertSame(3, $store->advancePlan(7, 1_060));
        self::assertTrue($store->planFinished(7));
        self::assertNull($store->advancePlan(7, 1_080), 'nothing past the last step');
    }

    /**
     * @covers \NHA\State\PlanStateTrait::planDue
     */
    public function testAPlanIsDueWhenMissingFinishedStaleMovedOrStalled(): void
    {
        $empty = new StateStore($this->path . '.empty');
        self::assertTrue($empty->planDue(7, 1_000, false, 'home'), 'no plan yet');
        @unlink($this->path . '.empty');

        $store = $this->planned();
        self::assertFalse($store->planDue(7, 1_100, false, 'home'), 'fresh, here, working');
        self::assertTrue($store->planDue(7, 1_100, true, 'home'), 'stalled');
        self::assertTrue($store->planDue(7, 1_100, false, 'mars'), 'arrived somewhere else');
        self::assertTrue($store->planDue(7, 2_200, false, 'home'), 'stale');

        foreach ([1_020, 1_040, 1_060] as $t) {
            $store->advancePlan(7, $t);
        }
        self::assertTrue($store->planDue(7, 1_100, false, 'home'), 'finished');
    }

    /**
     * Whatever the trigger, the planner is not asked again right after it
     * was asked — answered or not.
     *
     * @covers \NHA\State\PlanStateTrait::planDue
     */
    public function testNoSecondAskInsideTheRetryGap(): void
    {
        $store = new StateStore($this->path);
        $store->recordPlanAttempt(7, 1_000);

        self::assertFalse($store->planDue(7, 1_149, true, 'home'), 'no plan and stalled, but just asked');
        self::assertTrue($store->planDue(7, 1_150, true, 'home'));
    }
}
