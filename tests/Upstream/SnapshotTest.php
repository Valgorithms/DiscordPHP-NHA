<?php

declare(strict_types=1);

use NHA\Upstream\Snapshot;

/**
 * The views keep the rules and drop the play: a drift must mean the server's
 * rules moved, not that the world was played.
 *
 * @covers \NHA\Upstream\Snapshot
 */
class SnapshotTest extends NHAUnitTestCase
{
    public function testUpdatesAreKeyedByIdOldestFirst(): void
    {
        $view = Snapshot::updates(['updates' => [
            ['id' => 17, 'tick' => 1533267, 'title' => 'The vault re-sealed', 'detail' => 'New seal.', 'verb' => 'unlock'],
            ['id' => 3, 'tick' => 900000, 'title' => 'Gates', 'detail' => '', 'verb' => null],
        ]]);

        self::assertSame([3, 17], array_keys($view));
        self::assertSame(['tick' => 900000, 'title' => 'Gates', 'detail' => '', 'verb' => ''], $view[3]);
    }

    /**
     * A texture is not a contract, and FastAPI's generated titles only
     * restate their keys. A property that is itself called `title` is a
     * property, though.
     */
    public function testTheContractDropsAssetsAndGeneratedTitlesButNotATitleProperty(): void
    {
        $view = Snapshot::openapi([
            'info' => ['version' => '3.0'],
            'paths' => [
                '/tex/saturn_ring.png' => ['get' => ['summary' => 'Saturn Ring Texture']],
                '/vault' => [
                    'get' => ['summary' => 'Vault Ep', 'description' => 'The door.', 'parameters' => [
                        ['name' => 'agent', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'title' => 'Agent']],
                    ], 'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'title' => 'VaultOut', 'properties' => ['title' => ['type' => 'string', 'title' => 'Title']],
                    ]]]]]],
                    'parameters' => [['name' => 'shared']],
                ],
            ],
            'components' => ['schemas' => ['UpdateOut' => [
                'title' => 'UpdateOut',
                'properties' => ['title' => ['type' => 'string', 'title' => 'Title']],
                'required' => ['title'],
            ]]],
        ]);

        self::assertSame(['GET /vault'], array_keys($view['operations']));
        self::assertSame("Vault Ep\n\nThe door.", $view['operations']['GET /vault']['doc']);
        self::assertSame(['required' => false, 'schema' => ['type' => 'integer']], $view['operations']['GET /vault']['params']['query:agent']);
        self::assertSame(['properties' => ['title' => ['type' => 'string']], 'type' => 'object'], $view['operations']['GET /vault']['returns'], 'an inline schema keeps its `title` property');
        self::assertSame(['title' => ['type' => 'string']], $view['schemas']['UpdateOut']['properties']);
        self::assertSame(['title'], $view['schemas']['UpdateOut']['required']);
    }

    public function testTheCodexKeepsRulesAndDropsPlay(): void
    {
        $rules = [
            'note' => 'combine matches by physics tags.',
            'resources' => ['copper' => ['metal' => 1, 'conductivity' => 9]],
            'recipes' => [['item' => 'battery', 'needs' => '2 metals + an electrolyte', 'props' => ['energy' => 8], 'discovered' => ['by' => 'a']]],
            'pending' => 0,
            'dynamic' => [['sig' => 'ice,metal', 'item_key' => 'ice_armor']],
        ];
        $played = $rules;
        $played['recipes'][0]['discovered'] = ['by' => 'b'];
        $played['pending'] = 3;
        $played['dynamic'][] = ['sig' => 'salt,oil', 'item_key' => 'brine_cake'];

        self::assertSame(Snapshot::rules($rules), Snapshot::rules($played), 'discoveries and inventions are play');
        self::assertSame(['needs' => '2 metals + an electrolyte', 'props' => ['energy' => 8]], Snapshot::rules($rules)['recipes']['battery']);

        $changed = $rules;
        $changed['resources']['copper']['conductivity'] = 8;
        self::assertNotSame(Snapshot::fingerprint(Snapshot::rules($rules)), Snapshot::fingerprint(Snapshot::rules($changed)));
    }

    public function testColoniesKeepTheBillAndDropTheProgress(): void
    {
        $expansion = static fn(array $have, int $titanium): array => ['era' => 'expansion', 'bodies' => ['triton' => [
            'colony' => ['label' => 'Geyser Watch', 'cap_pct_per_agent' => 60, 'min_funders_per_module' => 2, 'colony_exists' => $have !== [], 'modules' => [
                ['module' => 'geyser_mast', 'label' => 'Geyser Mast', 'need' => ['titanium' => $titanium], 'have' => $have, 'contrib' => $have === [] ? [] : ['142285' => $have], 'complete' => false],
            ]],
        ], 'mars' => [
            'colony' => ['label' => 'Ares', 'modules' => []],
            'terraform' => ['exists' => true, 'index' => ['water' => 1], 'stages' => [
                ['stage' => 'warm', 'label' => 'Warm the Poles', 'need' => ['co2' => 600], 'sustain' => 50, 'sustain_done' => 12, 'have' => ['co2' => 1], 'funders' => 6, 'complete' => true],
            ]],
        ]]];

        self::assertSame(Snapshot::colonies($expansion([], 200)), Snapshot::colonies($expansion(['titanium' => 40], 200)), 'deliveries are play');
        self::assertNotSame(Snapshot::colonies($expansion([], 200)), Snapshot::colonies($expansion([], 150)), 'the bill is a rule');
        self::assertSame(['label' => 'Warm the Poles', 'need' => ['co2' => 600], 'sustain' => 50], Snapshot::colonies($expansion([], 200))['bodies']['mars']['terraform']['warm']);
    }

    public function testAFingerprintIgnoresKeyOrder(): void
    {
        self::assertSame(Snapshot::fingerprint(['a' => 1, 'b' => ['y' => 2, 'x' => 3]]), Snapshot::fingerprint(['b' => ['x' => 3, 'y' => 2], 'a' => 1]));
        self::assertNotSame(Snapshot::fingerprint([1, 2]), Snapshot::fingerprint([2, 1]), 'a list keeps its order');
    }
}
