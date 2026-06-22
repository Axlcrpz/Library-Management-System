<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers the authorization matrix (userCan) and CSRF token round-trip — the two
 * pure pieces of the auth surface that gate every privileged action. These run
 * with no database (auth.php has no DB dependency).
 */
final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testAdminCanDoAnything(): void
    {
        $_SESSION['role'] = 'admin';
        $this->assertTrue(userCan('delete'));
        $this->assertTrue(userCan('approve'));
        $this->assertTrue(userCan('something_new'));
    }

    public function testStaffIsScopedToOperationalActions(): void
    {
        $_SESSION['role'] = 'staff';
        $this->assertTrue(userCan('borrow'));
        $this->assertTrue(userCan('return'));
        $this->assertTrue(userCan('approve'));
        $this->assertFalse(userCan('delete'), 'delete must remain admin-only');
    }

    public function testViewerIsReadOnly(): void
    {
        $_SESSION['role'] = 'viewer';
        $this->assertTrue(userCan('view'));
        $this->assertTrue(userCan('read'));
        $this->assertFalse(userCan('borrow'));
        $this->assertFalse(userCan('approve'));
    }

    public function testUnknownRoleDefaultsToNoPrivilege(): void
    {
        $_SESSION['role'] = 'nonsense';
        $this->assertFalse(userCan('borrow'));
        $this->assertTrue(userCan('view'));   // base read still allowed by the matrix
    }

    public function testCsrfTokenRoundTrip(): void
    {
        unset($_SESSION['csrf_token']);
        $token = generateCSRFToken();
        $this->assertNotEmpty($token);
        $this->assertSame($token, generateCSRFToken(), 'token must be stable within a session');
        $this->assertTrue(validateCSRFToken($token));
    }

    public function testCsrfRejectsBadTokens(): void
    {
        generateCSRFToken();
        $this->assertFalse(validateCSRFToken('not-the-token'));
        $this->assertFalse(validateCSRFToken(null));
        $this->assertFalse(validateCSRFToken(''));
    }
}
