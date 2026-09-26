<?php

declare(strict_types=1);

use Discord\Http\Drivers\Guzzle;
use NHA\Http\Http;
use NHA\Upstream\Drift;
use NHA\Upstream\GitHub;
use NHA\Upstream\Snapshot;
use NHA\Upstream\Watcher;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Http\Browser;

/**
 * The baseline written by `--accept` must read back as exactly what was
 * accepted, or every check after it would report a drift that is only the
 * JSON round trip.
 *
 * @covers \NHA\Upstream\Watcher
 */
class WatcherTest extends NHAUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nha-upstream-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,upstream/}*.json', GLOB_BRACE) ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir . '/upstream');
        @rmdir($this->dir);
    }

    private function watcher(): Watcher
    {
        $loop = Loop::get();

        return new Watcher(new Http('', $loop, new NullLogger(), new Guzzle($loop)), new GitHub(new Browser(null, $loop)), $this->dir . '/upstream', $this->dir . '/openapi.json');
    }

    public function testAnAcceptedServerComparesClean(): void
    {
        // As the HTTP client hands them over: objects, with empty `{}` maps
        // and numeric ids, the two things a JSON round trip can change.
        $openapi = json_decode('{"info":{"version":"3.0"},"paths":{"/vault":{"get":{"summary":"Vault Ep","responses":{"200":{"content":{"application/json":{"schema":{}}}}}}}},"components":{"schemas":{}}}');
        $live = ['openapi' => $openapi, 'views' => [
            'updates' => Snapshot::updates(['updates' => [['id' => 1, 'tick' => 5, 'title' => 't', 'detail' => 'd', 'verb' => null]]]),
            'openapi' => Snapshot::openapi(json_decode((string) json_encode($openapi), true)),
            'rules' => Snapshot::rules(['note' => 'n', 'resources' => ['vacuum' => []], 'recipes' => [['item' => 'x', 'needs' => 'y', 'props' => []]]]),
            'colonies' => Snapshot::colonies(['era' => 'expansion', 'bodies' => ['triton' => ['colony' => ['modules' => [['module' => 'm', 'label' => 'M', 'need' => []]]]]]]),
            'source' => Snapshot::source(['sha' => 'abc', 'commit' => ['message' => "s\n\nbody", 'committer' => ['date' => 'd']]], 'Recluse/nha-mmo'),
        ]];
        $watcher = $this->watcher();
        self::assertSame(Snapshot::SOURCES, $watcher->missing());

        $watcher->accept($live, Snapshot::SOURCES);

        self::assertSame([], $watcher->missing());
        self::assertSame([], Drift::compare($watcher->baseline(), $live['views']));
        self::assertStringContainsString('"schema": {}', (string) file_get_contents($this->dir . '/openapi.json'), 'the contract is kept as served');
    }

    public function testOnlyTheNamedSourcesAreAccepted(): void
    {
        $watcher = $this->watcher();
        $watcher->accept(['openapi' => [], 'views' => ['rules' => ['note' => 'n', 'resources' => [], 'recipes' => []]]], ['rules']);

        self::assertSame(['updates', 'openapi', 'colonies', 'source'], $watcher->missing());

        $this->expectException(\InvalidArgumentException::class);
        $watcher->accept(['openapi' => [], 'views' => []], ['vault']);
    }
}
