<?php
namespace Tests;

use Lib\Fines;
use PHPUnit\Framework\TestCase;

/**
 * Covers the overdue-fine rule (Lib\Fines), which handleCalculateFine delegates
 * to. Money math is exactly the kind of logic that must not silently regress.
 */
final class FinesTest extends TestCase
{
    public function testNoFineWhenNotYetDue(): void
    {
        $r = Fines::calculate('2999-01-01 23:59:59', new \DateTime('2026-01-01 00:00:00'), 5.0);
        $this->assertSame(0, $r['overdue_days']);
        $this->assertSame(0.0, $r['fine_amount']);
    }

    public function testFineAccruesPerDay(): void
    {
        // Due Jan 1, evaluated Jan 6 → 5 days overdue at ₱5/day = ₱25.
        $r = Fines::calculate('2026-01-01 00:00:00', new \DateTime('2026-01-06 00:00:00'), 5.0);
        $this->assertSame(5, $r['overdue_days']);
        $this->assertSame(25.0, $r['fine_amount']);
    }

    public function testGracePeriodSuppressesFine(): void
    {
        // 3 days late but a 3-day grace window → still no fine.
        $r = Fines::calculate('2026-01-01 00:00:00', new \DateTime('2026-01-04 00:00:00'), 5.0, 3);
        $this->assertSame(0, $r['overdue_days']);
        $this->assertSame(0.0, $r['fine_amount']);
    }

    public function testNullOrEmptyDueYieldsNoFine(): void
    {
        $this->assertSame(0.0, Fines::calculate(null, new \DateTime(), 5.0)['fine_amount']);
        $this->assertSame(0.0, Fines::calculate('', new \DateTime(), 5.0)['fine_amount']);
    }

    public function testNegativeRateIsClampedToZero(): void
    {
        $r = Fines::calculate('2026-01-01 00:00:00', new \DateTime('2026-01-10 00:00:00'), -5.0);
        $this->assertSame(0.0, $r['fine_amount']);
    }
}
