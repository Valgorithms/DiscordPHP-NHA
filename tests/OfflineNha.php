<?php

declare(strict_types=1);

use Discord\Http\DriverInterface;
use Discord\Http\Http;
use Discord\Http\Request;
use NHA\NHA;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/** Keeps constructor bootstrap and any unmocked NHA calls off the network. */
class OfflineNha extends NHA
{
    private ?Http $offlineHttp = null;

    public function __construct(array $options = [])
    {
        parent::__construct($options);
        $this->nha_http = new \NHA\Http\Http('', $this->loop, $this->logger);
    }

    public function connectWs(): void {}

    public function getHttpClient(): Http
    {
        return $this->offlineHttp ??= new Http('', $this->loop, $this->logger, new class ($this->loop) implements DriverInterface {
            public function __construct(private \React\EventLoop\LoopInterface $loop) {}

            public function runRequest(Request $request): PromiseInterface
            {
                if (! str_ends_with($request->getUrl(), '/applications/@me')) {
                    throw new \LogicException('Unexpected Discord request in offline NHA test.');
                }

                $deferred = new Deferred();
                $this->loop->futureTick(static function () use ($deferred): void {
                    $deferred->resolve(new Response(200, ['Content-Type' => 'application/json'], '{"id":"123456789","name":"offline-fixture"}'));
                });

                return $deferred->promise();
            }
        });
    }
}
