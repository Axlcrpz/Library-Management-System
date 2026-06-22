<?php
namespace Lib;

/**
 * Overdue-fine calculation — pure, dependency-free, and therefore unit-testable.
 *
 * Extracted from handleCalculateFine() so the money-critical rule can be covered
 * by tests (see tests/FinesTest.php) without standing up a database. The handler
 * delegates here, so the behaviour the tests pin down is the behaviour in prod.
 */
final class Fines
{
    /**
     * @param string|null         $dueAt       SQL datetime the loan was due, or null/'' if none.
     * @param \DateTimeInterface  $now         The moment to evaluate against.
     * @param float               $ratePerDay  Fine charged per overdue day.
     * @param int                 $graceDays   Days of grace before fines accrue (default 0 = current behaviour).
     * @return array{overdue_days:int, fine_amount:float}
     */
    public static function calculate(?string $dueAt, \DateTimeInterface $now, float $ratePerDay, int $graceDays = 0): array
    {
        if ($dueAt === null || $dueAt === '') {
            return ['overdue_days' => 0, 'fine_amount' => 0.0];
        }

        try {
            $due = new \DateTimeImmutable($dueAt);
        } catch (\Throwable) {
            return ['overdue_days' => 0, 'fine_amount' => 0.0];
        }

        if ($graceDays > 0) {
            $due = $due->modify("+{$graceDays} days");
        }

        if ($now <= $due) {
            return ['overdue_days' => 0, 'fine_amount' => 0.0];
        }

        $overdueDays = (int) $due->diff($now)->days;
        return [
            'overdue_days' => $overdueDays,
            'fine_amount'  => round($overdueDays * max(0.0, $ratePerDay), 2),
        ];
    }
}
