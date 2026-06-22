<?php
namespace Tests;

use Lib\DueDate;
use PHPUnit\Framework\TestCase;

/**
 * Covers the due-date precedence rule (Lib\DueDate) that handleBookBorrowApprove
 * delegates to — the spine of every approved loan's overdue/fine behavior.
 */
final class DueDateTest extends TestCase
{
    public function testExplicitDueWins(): void
    {
        $this->assertSame(
            '2026-02-01 17:00:00',
            DueDate::compute('2026-02-01 17:00:00', '2026-03-01', 120, '2026-01-01 09:00:00')
        );
    }

    public function testFallsBackToRequestedDateEndOfDay(): void
    {
        $this->assertSame(
            '2026-03-01 23:59:59',
            DueDate::compute('', '2026-03-01', 120, '2026-01-01 09:00:00')
        );
    }

    public function testFallsBackToTimeAllowance(): void
    {
        // 90 minutes after a 09:00 borrow → 10:30 the same day (in-library use case).
        $this->assertSame(
            '2026-01-01 10:30:00',
            DueDate::compute('', null, 90, '2026-01-01 09:00:00')
        );
    }

    public function testOpenEndedWhenNoRuleApplies(): void
    {
        $this->assertNull(DueDate::compute('', null, null, '2026-01-01 09:00:00'));
        $this->assertNull(DueDate::compute('', '', 0, '2026-01-01 09:00:00'));
    }
}
