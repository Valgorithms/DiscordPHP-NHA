<?php

declare(strict_types=1);

class OfflineNhaTest extends NHAUnitTestCase
{
    public function testLiveSingletonRequiresExplicitOptIn(): void
    {
        $previous = getenv('NHA_LIVE_TESTS');
        putenv('NHA_LIVE_TESTS');
        try {
            $this->expectException(\LogicException::class);
            NHASingleton::get();
        } finally {
            $previous === false ? putenv('NHA_LIVE_TESTS') : putenv('NHA_LIVE_TESTS=' . $previous);
        }
    }

    public function testBootstrapUsesAnInMemoryApplicationAndKeepsTheNhaClient(): void
    {
        $nha = getMockNha();
        $this->assertInstanceOf(\NHA\Client::class, $nha->getClient());
        $nha->getLoop()->run();
        $this->assertInstanceOf(\NHA\Client::class, $nha->getClient());
        $this->assertSame('123456789', $nha->getClient()->application->id);
    }

    public function testAnAgentTokenCannotEnableTheDiscordLiveHelper(): void
    {
        $previous = [];
        foreach (['NHA_LIVE_TESTS', 'TOKEN', 'NHA_TOKEN'] as $key) {
            $previous[$key] = getenv($key);
        }
        putenv('NHA_LIVE_TESTS=1');
        putenv('TOKEN');
        putenv('NHA_TOKEN=agent-fixture');
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Discord bot TOKEN');
            NHASingleton::get();
        } finally {
            foreach ($previous as $key => $value) {
                $value === false ? putenv($key) : putenv($key . '=' . $value);
            }
        }
    }

    public function testAnUnmockedNhaCallRejectsWithoutADriver(): void
    {
        $error = null;
        getMockNha()->observe(42)->then(null, function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $this->assertInstanceOf(\Exception::class, $error);
        $this->assertSame('HTTP driver is missing.', $error->getMessage());
    }

    public function testUnexpectedDiscordRequestsAreRejectedByTheFixtureTransport(): void
    {
        $nha = getMockNha();
        $this->expectException(\LogicException::class);
        $nha->getHttpClient()->get('users/@me');
    }
}
