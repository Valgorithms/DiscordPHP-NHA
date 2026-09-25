<?php

declare(strict_types=1);

use NHA\Brain\OllamaClient;
use NHA\Brain\Planner;
use NHA\Parts\AgentObservation;

use function React\Promise\resolve;

class PlannerTest extends NHAUnitTestCase
{
    /**
     * @covers \NHA\Brain\Planner::parsePlan
     */
    public function testAWellFormedPlanIsKept(): void
    {
        $plan = Planner::parsePlan("```json\n" . json_encode([
            'goal' => ' Found the Triton colony ',
            'steps' => ['make a battery', '', 42, 'make a thermal_core', 'fly to triton'],
            'why' => 'nobody has laid it',
        ]) . "\n```");

        self::assertSame('Found the Triton colony', $plan['goal']);
        self::assertSame(['make a battery', 'make a thermal_core', 'fly to triton'], $plan['steps'], 'blank and non-string steps are dropped');
        self::assertSame('nobody has laid it', $plan['why']);
    }

    /**
     * @covers \NHA\Brain\Planner::parsePlan
     */
    public function testAPlanWithoutAGoalOrStepsIsNoPlan(): void
    {
        self::assertNull(Planner::parsePlan('{"goal":"","steps":["x"]}'));
        self::assertNull(Planner::parsePlan('{"goal":"go","steps":[]}'));
        self::assertNull(Planner::parsePlan('not json at all'));
    }

    /**
     * @covers \NHA\Brain\Planner::parsePlan
     */
    public function testALongPlanIsCutToTheMaximum(): void
    {
        $plan = Planner::parsePlan(json_encode(['goal' => 'g', 'steps' => array_map(static fn(int $i): string => "step {$i}", range(1, 10))]));

        self::assertCount(Planner::MAX_STEPS, $plan['steps']);
        self::assertSame('', $plan['why']);
    }

    /**
     * The planner sees what the turn model sees, plus the world's objective
     * board, and is asked for a plan rather than an action — with the reply
     * shape pinned by a schema.
     *
     * @covers \NHA\Brain\Planner::plan
     */
    public function testThePlannerIsAskedForAPlanWithTheObjectiveBoard(): void
    {
        $sent = '';
        $planner = new Planner(new OllamaClient('http://x', 'm', function ($m, $u, $h, $payload) use (&$sent) {
            $sent = (string) $payload;

            return resolve(json_encode(['message' => ['content' => '{"goal":"g","steps":["s"],"why":"w"}'], 'done' => true]));
        }));

        $got = null;
        $planner->plan(new AgentObservation(7, ['tick' => 100, 'inventory' => ['credits' => 5]]), ['objectives' => 'triton: geyser_mast needs superalloy'])
            ->then(function (?array $p) use (&$got): void {
                $got = $p;
            });

        $body = json_decode($sent, true);
        $user = (string) $body['messages'][1]['content'];
        self::assertStringContainsString("The world's objective board:\ntriton: geyser_mast needs superalloy", $user);
        self::assertStringEndsWith('Set the plan. Reply with JSON only.', explode("\n\n", $user)[0]);
        self::assertStringNotContainsString('Choose one action', $user);
        self::assertSame(Planner::SCHEMA, $body['format'], 'the reply shape is enforced');
        self::assertSame(['goal' => 'g', 'steps' => ['s'], 'why' => 'w'], $got);
    }
}
