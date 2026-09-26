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

namespace NHA\Upstream;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;

/**
 * The few GitHub REST calls {@see Watcher} makes: read the public engine
 * source's head and compare two of its commits, and keep one labelled issue
 * on this repository.
 *
 * Reads work without a token on public repositories, within GitHub's
 * anonymous rate limit. Writing an issue needs one with `issues: write`,
 * which the workflow's `GITHUB_TOKEN` has. The token only ever goes in the
 * `Authorization` header.
 *
 * @link https://docs.github.com/en/rest
 *
 * @since 3.23.0
 */
final class GitHub
{
    public const API = 'https://api.github.com/';

    public function __construct(
        private Browser $browser,
        private string $token = '',
    ) {}

    /**
     * A branch's newest commit.
     *
     * @link https://docs.github.com/en/rest/commits/commits#get-a-commit
     *
     * @return PromiseInterface<array<string,mixed>>
     */
    public function head(string $repo, string $branch = 'main'): PromiseInterface
    {
        return $this->request('GET', "repos/{$repo}/commits/" . rawurlencode($branch));
    }

    /**
     * The commits and changed files between two commits, or null when GitHub
     * cannot compare them (404: a history rewritten under the old one).
     *
     * @link https://docs.github.com/en/rest/commits/commits#compare-two-commits
     *
     * @return PromiseInterface<array<string,mixed>|null>
     */
    public function compare(string $repo, string $base, string $head): PromiseInterface
    {
        return $this->request('GET', "repos/{$repo}/compare/{$base}...{$head}")
            ->catch(static function (ResponseException $e): ?array {
                if ($e->getCode() === 404) {
                    return null;
                }

                throw $e;
            });
    }

    /**
     * The oldest open issue carrying the label, or null. Pull requests,
     * which the issues endpoint also lists, are skipped.
     *
     * @link https://docs.github.com/en/rest/issues/issues#list-repository-issues
     *
     * @return PromiseInterface<array<string,mixed>|null>
     */
    public function openIssue(string $repo, string $label): PromiseInterface
    {
        return $this->request('GET', "repos/{$repo}/issues?" . http_build_query(['state' => 'open', 'labels' => $label, 'sort' => 'created', 'direction' => 'asc', 'per_page' => 20]))
            ->then(static function (array $issues): ?array {
                foreach ($issues as $issue) {
                    if (is_array($issue) && ! isset($issue['pull_request'])) {
                        return $issue;
                    }
                }

                return null;
            });
    }

    /**
     * Creates the label if the repository lacks it; an existing one is left as is.
     *
     * @link https://docs.github.com/en/rest/issues/labels#create-a-label
     *
     * @return PromiseInterface<null>
     */
    public function ensureLabel(string $repo, string $label, string $color, string $description): PromiseInterface
    {
        return $this->request('POST', "repos/{$repo}/labels", ['name' => $label, 'color' => $color, 'description' => $description])
            ->then(static fn(): mixed => null, static function (\Throwable $e): mixed {
                if ($e instanceof ResponseException && $e->getCode() === 422) {
                    return null; // already_exists
                }

                throw $e;
            });
    }

    /**
     * @link https://docs.github.com/en/rest/issues/issues#create-an-issue
     *
     * @param list<string> $labels
     *
     * @return PromiseInterface<array<string,mixed>>
     */
    public function createIssue(string $repo, string $title, string $body, array $labels): PromiseInterface
    {
        return $this->request('POST', "repos/{$repo}/issues", ['title' => $title, 'body' => $body, 'labels' => $labels]);
    }

    /**
     * @link https://docs.github.com/en/rest/issues/issues#update-an-issue
     *
     * @param array<string,mixed> $fields e.g. `title`, `body`, `state`, `state_reason`.
     *
     * @return PromiseInterface<array<string,mixed>>
     */
    public function updateIssue(string $repo, int $number, array $fields): PromiseInterface
    {
        return $this->request('PATCH', "repos/{$repo}/issues/{$number}", $fields);
    }

    /**
     * @link https://docs.github.com/en/rest/issues/comments#create-an-issue-comment
     *
     * @return PromiseInterface<array<string,mixed>>
     */
    public function comment(string $repo, int $number, string $body): PromiseInterface
    {
        return $this->request('POST', "repos/{$repo}/issues/{$number}/comments", ['body' => $body]);
    }

    /**
     * @param array<string,mixed>|null $body JSON body, or none.
     *
     * @return PromiseInterface<mixed> The decoded JSON response.
     */
    private function request(string $method, string $path, ?array $body = null): PromiseInterface
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'DiscordPHP-NHA upstream-watch',
        ];
        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->browser->request($method, self::API . $path, $headers, $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->then(static fn(ResponseInterface $r): mixed => json_decode((string) $r->getBody(), true));
    }
}
