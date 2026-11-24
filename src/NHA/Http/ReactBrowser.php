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

namespace NHA\Http;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Socket\Connector;

/** Composes only the bot's independent ReactPHP HTTP clients. */
final class ReactBrowser
{
    public static function create(?LoopInterface $loop = null): Browser
    {
        $loop ??= Loop::get();
        $options = self::socketOptions(PHP_OS_FAMILY === 'Windows', getenv('NHA_REACT_DNS'));

        return new Browser(new Connector($options, $loop), $loop);
    }

    /**
     * WMIC is absent on current Windows. Explicitly use ReactPHP's usual
     * fallback there instead of spawning blocking or deprecated tools.
     *
     * @return array{dns?: string}
     */
    public static function socketOptions(bool $windows, string|false $configured): array
    {
        $address = trim((string) $configured);
        if ($address !== '') {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('NHA_REACT_DNS must be a numeric IPv4 or IPv6 address.');
            }

            return ['dns' => $address];
        }

        return $windows ? ['dns' => '8.8.8.8'] : [];
    }
}
