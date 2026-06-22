<?php
namespace Lib;

/**
 * Resolves a borrow's due timestamp at approval time — pure and unit-testable.
 *
 * Extracted from handleBookBorrowApprove() so the precedence rule (explicit due >
 * requested date > fixed time-allowance > open-ended) is pinned by tests and can't
 * silently drift. The handler delegates here.
 */
final class DueDate
{
    /**
     * @param string      $explicitDue          Staff-entered due ('Y-m-d H:i:s' / 'Y-m-d') or '' if none.
     * @param string|null $requestedDue         Date the borrower asked for on the form ('Y-m-d') or null.
     * @param int|null    $timeAllowedMinutes   Fixed allowance in minutes (e.g. in-library use) or null.
     * @param string      $borrowedAt           When the loan starts ('Y-m-d H:i:s').
     * @return string|null  Resolved due as 'Y-m-d H:i:s', or null for an open-ended loan.
     */
    public static function compute(string $explicitDue, ?string $requestedDue, ?int $timeAllowedMinutes, string $borrowedAt): ?string
    {
        if ($explicitDue !== '') {
            return $explicitDue;
        }
        if (!empty($requestedDue)) {
            return $requestedDue . ' 23:59:59';   // end of the requested day
        }
        if (!empty($timeAllowedMinutes)) {
            $ts = strtotime($borrowedAt . ' +' . (int) $timeAllowedMinutes . ' minutes');
            return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        }
        return null;
    }
}
