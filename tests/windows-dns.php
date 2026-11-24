<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-NHA project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace React\Dns\Config {
    function shell_exec(string $command): never
    {
        throw new \LogicException('Unexpected implicit Windows DNS discovery');
    }
}

namespace {
    use NHA\Brain\OllamaClient;
    use NHA\Http\ReactBrowser;
    use React\EventLoop\StreamSelectLoop;
    use React\Http\Browser;

    $loader = require $argv[1] ?? __DIR__ . '/../vendor/autoload.php';
    $loader->addPsr4('NHA\\', __DIR__ . '/../src/NHA/', true);
    putenv('NHA_REACT_DNS');

    $check = static function (bool $condition): void {
        if (! $condition) {
            throw new \RuntimeException('React Browser DNS contract failed.');
        }
    };

    $check(ReactBrowser::socketOptions(true, false) === ['dns' => '8.8.8.8']);
    $check(ReactBrowser::socketOptions(false, false) === []);
    $check(ReactBrowser::socketOptions(true, ' 192.0.2.53 ') === ['dns' => '192.0.2.53']);
    $check(ReactBrowser::socketOptions(false, '2001:db8::53') === ['dns' => '2001:db8::53']);
    $invalid = false;
    try {
        ReactBrowser::socketOptions(true, 'not-an-address');
    } catch (\InvalidArgumentException) {
        $invalid = true;
    }
    $check($invalid);

    // Exercise the real Ollama constructor/default transport without calling chat().
    $loop = new StreamSelectLoop();
    new OllamaClient('http://192.0.2.50:11434/v1', 'offline-fixture', loop: $loop);
    $check(ReactBrowser::create($loop) instanceof Browser);
    if (PHP_OS_FAMILY === 'Windows') {
        $caught = false;
        try {
            new Browser(null, $loop);
        } catch (\LogicException $error) {
            $caught = $error->getMessage() === 'Unexpected implicit Windows DNS discovery';
        }
        $check($caught);
    }
    echo "React Browser DNS contracts passed.\n";
}
