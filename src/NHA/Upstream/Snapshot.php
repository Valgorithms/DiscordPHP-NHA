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

/**
 * The rule-level view of each thing the NHA server publishes that this
 * library's code is written against. {@see Drift} compares these views, and
 * `upstream/*.json` stores them as the baseline.
 *
 * A view keeps what a code change would have to follow: a recipe's inputs,
 * a colony module's bill, an endpoint's parameters. It drops what the world
 * moves on its own: who contributed, what players have invented, the tick.
 * So a difference means the rules moved, not that the game was played.
 *
 * @see https://nha.recluse.lol/updates The operator rule-update feed
 * @see https://nha.recluse.lol/openapi.json The API contract
 * @see https://github.com/Recluse/nha-mmo The public engine source
 *
 * @since 3.23.0
 */
final class Snapshot
{
    /** Every source, in report order. */
    public const SOURCES = ['updates', 'openapi', 'rules', 'colonies', 'source'];

    /** Routes that serve assets rather than data: a texture is not a contract. */
    private const ASSET = '/\.(png|jpe?g|gif|svg|webp|ico|css|js|html?)$/i';

    /** The HTTP methods an operation can sit under in an OpenAPI path item. */
    private const METHODS = ['get', 'put', 'post', 'patch', 'delete'];

    /** The fields of a terraform stage that are its rules, not its progress. */
    private const STAGE_RULES = ['label', 'need', 'index_effect', 'flagship', 'cap_pct_per_agent', 'min_funders', 'sustain'];

    /**
     * `GET /updates`: the operator's rule-update feed, keyed by id, oldest first.
     *
     * @param array<string,mixed> $raw
     *
     * @return array<string,array{tick: int, title: string, detail: string, verb: string}>
     */
    public static function updates(array $raw): array
    {
        $out = [];
        foreach ((array) ($raw['updates'] ?? []) as $u) {
            if (! is_array($u) || ! isset($u['id'])) {
                continue;
            }
            $out[(string) $u['id']] = [
                'tick' => (int) ($u['tick'] ?? 0),
                'title' => (string) ($u['title'] ?? ''),
                'detail' => (string) ($u['detail'] ?? ''),
                'verb' => (string) ($u['verb'] ?? ''),
            ];
        }
        uksort($out, static fn($a, $b): int => (int) $a <=> (int) $b);

        return $out;
    }

    /**
     * `GET /openapi.json`, reduced to what a client is written against:
     * each operation's documentation, parameters, request body and response,
     * and each schema's properties.
     *
     * FastAPI's generated `title`s are dropped: they restate the key they sit
     * under and would turn a rename into noise.
     *
     * @param array<string,mixed> $doc
     *
     * @return array{version: string, operations: array<string,array<string,mixed>>, schemas: array<string,array<string,mixed>>}
     */
    public static function openapi(array $doc): array
    {
        $ops = [];
        foreach ((array) ($doc['paths'] ?? []) as $path => $item) {
            if (preg_match(self::ASSET, (string) $path)) {
                continue;
            }
            foreach ((array) $item as $method => $op) {
                if (! is_array($op) || ! in_array($method, self::METHODS, true)) {
                    continue;
                }
                $params = [];
                foreach ((array) ($op['parameters'] ?? []) as $p) {
                    $params[($p['in'] ?? '') . ':' . ($p['name'] ?? '')] = self::untitled([
                        'required' => (bool) ($p['required'] ?? false),
                        'schema' => $p['schema'] ?? null,
                    ]);
                }
                ksort($params);
                $ops[strtoupper((string) $method) . ' ' . $path] = [
                    'doc' => trim(($op['summary'] ?? '') . "\n\n" . ($op['description'] ?? '')),
                    'params' => $params,
                    'body' => self::untitled($op['requestBody']['content']['application/json']['schema'] ?? null),
                    'returns' => self::untitled($op['responses']['200']['content']['application/json']['schema'] ?? null),
                ];
            }
        }
        ksort($ops);

        $schemas = [];
        foreach ((array) ($doc['components']['schemas'] ?? []) as $name => $s) {
            $props = [];
            foreach ((array) ($s['properties'] ?? []) as $prop => $def) {
                $props[(string) $prop] = self::untitled($def);
            }
            ksort($props);
            $required = array_map('strval', (array) ($s['required'] ?? []));
            sort($required);
            $schemas[(string) $name] = ['doc' => (string) ($s['description'] ?? ''), 'properties' => $props, 'required' => $required];
        }
        ksort($schemas);

        return ['version' => (string) ($doc['info']['version'] ?? ''), 'operations' => $ops, 'schemas' => $schemas];
    }

    /**
     * `GET /rules`, the crafting codex: each resource's physics tags, each
     * recipe's inputs and properties, and the codex note.
     *
     * Dropped: who discovered a recipe, what players have invented
     * (`dynamic`) and what is awaiting the Guild (`pending`).
     *
     * @param array<string,mixed> $raw
     *
     * @return array{note: string, resources: array<string,mixed>, recipes: array<string,array{needs: mixed, props: mixed}>}
     */
    public static function rules(array $raw): array
    {
        $resources = [];
        foreach ((array) ($raw['resources'] ?? []) as $name => $tags) {
            $resources[(string) $name] = self::canonical((array) $tags);
        }
        ksort($resources);

        $recipes = [];
        foreach ((array) ($raw['recipes'] ?? []) as $r) {
            if (! is_array($r) || ! isset($r['item'])) {
                continue;
            }
            $recipes[(string) $r['item']] = ['needs' => $r['needs'] ?? null, 'props' => self::canonical((array) ($r['props'] ?? []))];
        }
        ksort($recipes);

        return ['note' => (string) ($raw['note'] ?? ''), 'resources' => $resources, 'recipes' => $recipes];
    }

    /**
     * `GET /expansion`: each body's colony and terraform bills, and the era.
     *
     * Kept: what a module or stage needs, and the funding caps. Dropped: what
     * has been delivered, by whom, and what is complete. Those are the world
     * moving, not the rules.
     *
     * @param array<string,mixed> $raw
     *
     * @return array{era: string, bodies: array<string,array<string,mixed>>}
     */
    public static function colonies(array $raw): array
    {
        $bodies = [];
        foreach ((array) ($raw['bodies'] ?? []) as $body => $b) {
            $c = (array) ($b['colony'] ?? []);
            $modules = [];
            foreach ((array) ($c['modules'] ?? []) as $m) {
                $modules[(string) ($m['module'] ?? '')] = ['label' => (string) ($m['label'] ?? ''), 'need' => self::canonical((array) ($m['need'] ?? []))];
            }
            ksort($modules);
            $stages = [];
            foreach ((array) (((array) ($b['terraform'] ?? []))['stages'] ?? []) as $s) {
                $stages[(string) ($s['stage'] ?? '')] = self::canonical(array_intersect_key((array) $s, array_flip(self::STAGE_RULES)));
            }
            ksort($stages);
            $bodies[(string) $body] = array_filter([
                'label' => $c['label'] ?? null,
                'cap_pct_per_agent' => $c['cap_pct_per_agent'] ?? null,
                'min_funders_per_module' => $c['min_funders_per_module'] ?? null,
                'modules' => $modules,
                'terraform' => $stages,
            ], static fn($v): bool => $v !== null && $v !== []);
        }
        ksort($bodies);

        return ['era' => (string) ($raw['era'] ?? ''), 'bodies' => $bodies];
    }

    /**
     * The public engine source's newest commit (GitHub's `GET
     * /repos/{repo}/commits/{branch}`), as the point the baseline was taken.
     *
     * @param array<string,mixed> $commit
     *
     * @return array{repo: string, sha: string, date: string, subject: string}
     */
    public static function source(array $commit, string $repo): array
    {
        $c = (array) ($commit['commit'] ?? []);

        return [
            'repo' => $repo,
            'sha' => (string) ($commit['sha'] ?? ''),
            'date' => (string) (((array) ($c['committer'] ?? []))['date'] ?? ''),
            'subject' => strtok((string) ($c['message'] ?? ''), "\n") ?: '',
        ];
    }

    /**
     * A short, order-insensitive hash of a view: equal when the view is.
     */
    public static function fingerprint(mixed $view): string
    {
        return substr(hash('sha256', (string) json_encode(self::canonical($view), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 12);
    }

    /**
     * Map keys sorted at every depth; lists keep their order.
     */
    public static function canonical(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map([self::class, 'canonical'], $v);
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return $v;
    }

    /**
     * A schema fragment without FastAPI's generated `title`s. Inside a
     * `properties` map the keys are property names, so a property that is
     * itself called `title` stays.
     */
    private static function untitled(mixed $v, bool $names = false): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! $names) {
            unset($v['title']);
        }
        foreach ($v as $k => $item) {
            $v[$k] = self::untitled($item, ! $names && $k === 'properties');
        }

        return self::canonical($v);
    }
}
