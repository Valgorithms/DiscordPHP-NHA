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
        self::assertSame(['goal' => 'g', 'steps' => ['s'], 'why' => 'w', 'review' => [], 'revised' => false], $got);
    }

    private const THERMAL = ['thermal_core' => 'a battery + mars_ice + a NON-magnetic metal (titanium/aluminum/copper)'];

    /**
     * The first live plan, verbatim: it combined `mars_ice` with none in the
     * hold and no step going to Mars for it, and every step was a command.
     *
     * @covers \NHA\Brain\Planner::critique
     */
    public function testTheFirstLivePlanIsCaughtOnBothCounts(): void
    {
        $problems = Planner::critique([
            'goal' => 'Craft a thermal_core to enable travel to the outer system bodies.',
            'steps' => ['mine{n=10,resource=copper}', 'combine{ingredients:{copper:1,salt:1}}',
                'combine{ingredients:{aluminum:1,copper:1,salt:1}}', 'combine{ingredients:{battery:1,mars_ice:1,aluminum:1}}'],
        ], ['inventory' => ['copper' => 827, 'mars_ice' => 0]], self::THERMAL);

        self::assertCount(2, $problems);
        self::assertStringStartsWith('mars_ice (needed for thermal_core): you hold none', $problems[0]);
        self::assertStringContainsString('add steps to fly to mars and mine it', $problems[0]);
        self::assertStringStartsWith('Steps 1, 2, 3, 4 are written as a command', $problems[1]);
    }

    /**
     * A plan that never names `mars_ice` still needs it if it makes a
     * thermal_core — the recipe says so.
     *
     * @covers \NHA\Brain\Planner::critique
     */
    public function testABodyResourceARecipeNeedsIsCaughtWhenThePlanNeverNamesIt(): void
    {
        $problems = Planner::critique(['goal' => 'make a thermal_core', 'steps' => ['hold 1 battery', 'hold 1 thermal_core']], [], self::THERMAL);

        self::assertCount(1, $problems);
        self::assertStringStartsWith('mars_ice (needed for thermal_core)', $problems[0]);
    }

    /**
     * @covers \NHA\Brain\Planner::critique
     */
    public function testAPlanThatFetchesItOrAnAgentThatHoldsItIsFine(): void
    {
        $fetches = ['goal' => 'make a thermal_core', 'steps' => ['be at mars', 'mine mars_ice until you hold 2', 'hold 1 thermal_core']];
        self::assertSame([], Planner::critique($fetches, [], self::THERMAL));

        $bare = ['goal' => 'make a thermal_core', 'steps' => ['hold 1 thermal_core']];
        self::assertSame([], Planner::critique($bare, ['inventory' => ['mars_ice' => 3]], self::THERMAL), 'already in the hold');

        $triton = ['goal' => 'fund the triton colony with nitrogen_ice', 'steps' => ['be at triton', 'fund the geyser_mast']];
        self::assertSame([], Planner::critique($triton, ['inventory' => ['nitrogen_ice' => 20]]), 'nitrogen_ice is not Venus\'s nitrogen');
    }

    /**
     * @covers \NHA\Brain\Planner::critique
     */
    public function testOnThatBodyTheAdviceIsToMineItHere(): void
    {
        $problems = Planner::critique(['goal' => 'make a thermal_core', 'steps' => ['hold 1 thermal_core']], ['expansion' => ['at_body' => 'mars']], self::THERMAL);

        self::assertStringContainsString('you are on mars now, so add a step to mine it', $problems[0]);
    }

    /**
     * "An electrolyte" is matched by physics tags, and `electrolyte` and
     * `salt` are both real items — a name check there would only raise false
     * alarms, so generic clauses are left alone.
     *
     * @covers \NHA\Brain\Planner::critique
     */
    public function testGenericRecipeClausesAreNotChecked(): void
    {
        $plan = ['goal' => 'make a battery', 'steps' => ['hold 1 battery']];

        self::assertSame([], Planner::critique($plan, [], ['battery' => '2 different metals (reactivity gap) + an electrolyte']));
    }

    /**
     * A flawed draft goes back once, with its problems listed, and the
     * revision is used.
     *
     * @covers \NHA\Brain\Planner::plan
     */
    public function testAFlawedDraftIsSentBackOnceWithItsProblems(): void
    {
        $replies = [
            '{"goal":"make a thermal_core","steps":["hold 1 thermal_core"],"why":"w"}',
            '{"goal":"make a thermal_core","steps":["be at mars","mine mars_ice","hold 1 thermal_core"],"why":"w"}',
        ];
        $sent = [];
        $planner = new Planner(new OllamaClient('http://x', 'm', function ($m, $u, $h, $payload) use (&$sent, &$replies) {
            $sent[] = json_decode((string) $payload, true);

            return resolve(json_encode(['message' => ['content' => array_shift($replies)], 'done' => true]));
        }));

        $got = null;
        $planner->plan(new AgentObservation(7, ['tick' => 1]), ['recipe_book' => self::THERMAL])->then(function (?array $p) use (&$got): void {
            $got = $p;
        });

        self::assertCount(2, $sent);
        $second = $sent[1]['messages'];
        self::assertSame('assistant', $second[2]['role'], 'the draft is shown back');
        self::assertStringStartsWith("Your plan has problems:\n- mars_ice (needed for thermal_core)", $second[3]['content']);
        self::assertSame(['be at mars', 'mine mars_ice', 'hold 1 thermal_core'], $got['steps']);
        self::assertTrue($got['revised']);
        self::assertCount(1, $got['review']);
    }

    /**
     * @covers \NHA\Brain\Planner::plan
     */
    public function testAnUnusableRevisionKeepsTheDraft(): void
    {
        $replies = ['{"goal":"make a thermal_core","steps":["hold 1 thermal_core"],"why":"w"}', 'no idea'];
        $planner = new Planner(new OllamaClient('http://x', 'm', function () use (&$replies) {
            return resolve(json_encode(['message' => ['content' => array_shift($replies)], 'done' => true]));
        }));

        $got = null;
        $planner->plan(new AgentObservation(7, ['tick' => 1]), ['recipe_book' => self::THERMAL])->then(function (?array $p) use (&$got): void {
            $got = $p;
        });

        self::assertSame(['hold 1 thermal_core'], $got['steps']);
        self::assertFalse($got['revised']);
        self::assertCount(1, $got['review'], 'the problems are still reported');
    }

    /**
     * Live: under the schema the model closed and reopened its quotes inside
     * one string, and three steps arrived as one.
     *
     * @covers \NHA\Brain\Planner::parsePlan
     */
    public function testStepsMergedIntoOneStringAreSplitBack(): void
    {
        $plan = Planner::parsePlan(json_encode([
            'goal' => 'g',
            'steps' => ['depart mars', 'land_body on mars","mine mars_ice on mars", "combine battery + mars_ice + aluminum to make thermal_core'],
        ]));

        self::assertSame(['depart mars', 'land_body on mars', 'mine mars_ice on mars', 'combine battery + mars_ice + aluminum to make thermal_core'], $plan['steps']);
    }
}
