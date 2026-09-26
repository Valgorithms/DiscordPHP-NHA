<?php

declare(strict_types=1);

use NHA\Upstream\Report;

/**
 * One issue, kept in step with the drift: opened once, left alone while the
 * drift stands still, rewritten (with a comment) when it moves, closed when
 * the baseline catches up.
 *
 * @covers \NHA\Upstream\Report
 */
class ReportTest extends NHAUnitTestCase
{
    public function testTheBodyCarriesItsFingerprints(): void
    {
        $drift = ['openapi' => ['New endpoint `GET /vault`'], 'source' => ['`Recluse/nha-mmo` moved']];
        $body = Report::body($drift, ['openapi' => 'aaa', 'source' => 'bbb'], '2026-09-26 12:00 UTC');

        self::assertSame('NHA server changed: API contract, Engine source', Report::title($drift));
        self::assertStringContainsString("## API contract\n", $body);
        self::assertStringContainsString("- New endpoint `GET /vault`\n", $body);
        self::assertStringContainsString('`src/NHA/Http/Endpoint.php`', $body, 'says where the change lands');
        self::assertSame(['openapi' => 'aaa', 'source' => 'bbb'], Report::prints($body));
        self::assertSame([], Report::prints('an issue someone wrote by hand'));
    }

    public function testALongSectionIsCut(): void
    {
        $body = Report::body(['rules' => array_map(static fn(int $i): string => "Recipe `r{$i}` changed", range(1, Report::SECTION_MAX + 5))], ['rules' => 'x'], 'now');

        self::assertStringContainsString('- …and 5 more.', $body);
        self::assertStringNotContainsString('`r' . (Report::SECTION_MAX + 1) . '`', $body);
    }

    public function testTheIssueFollowsTheDrift(): void
    {
        $drift = ['openapi' => ['x'], 'rules' => ['y']];
        $prints = ['openapi' => 'aaa', 'rules' => 'bbb'];
        $open = ['number' => 7, 'body' => Report::body($drift, $prints, 'then')];

        self::assertSame('none', Report::action(null, [], [])['action'], 'nothing moved, nothing open');
        self::assertSame('create', Report::action(null, $drift, $prints)['action']);
        self::assertSame('none', Report::action($open, $drift, $prints)['action'], 'the same drift again');
        self::assertSame(['action' => 'update', 'moved' => ['rules']], Report::action($open, $drift, ['openapi' => 'aaa', 'rules' => 'ccc']), 'moved again: say what');
        self::assertSame(['action' => 'update', 'moved' => []], Report::action($open, ['openapi' => ['x']], ['openapi' => 'aaa']), 'part accepted: rewrite quietly');
        self::assertSame('close', Report::action($open, [], [])['action'], 'caught up');
    }
}
