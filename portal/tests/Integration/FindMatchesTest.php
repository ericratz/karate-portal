<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for find_matches() (registration.php) — the duplicate-
 * registration detector. Inserts/removes its own sentinel student row.
 */
class FindMatchesTest extends TestCase
{
    private const FIRST = 'Phpunit';
    private const LAST  = 'Findmatch';
    private const DOB   = '2000-01-01';
    private const EMAIL = 'phpunit_findmatch@test.invalid';

    protected function setUp(): void
    {
        db()->prepare(
            'INSERT INTO students (first_name, last_name, date_of_birth, email, registration_date, student_type)
             VALUES (?, ?, ?, ?, CURDATE(), \'guest\')'
        )->execute([self::FIRST, self::LAST, self::DOB, self::EMAIL]);
    }

    protected function tearDown(): void
    {
        db()->prepare('DELETE FROM students WHERE email = ?')->execute([self::EMAIL]);
    }

    public function test_matches_by_name(): void
    {
        $rows = find_matches(self::FIRST, self::LAST, '', '');
        $this->assertNotEmpty($rows);
        $this->assertSame(self::EMAIL, $rows[0]['email']);
    }

    public function test_matches_by_name_case_insensitive(): void
    {
        $rows = find_matches(strtoupper(self::FIRST), strtoupper(self::LAST), '', '');
        $this->assertNotEmpty($rows);
    }

    public function test_matches_by_date_of_birth(): void
    {
        $rows = find_matches('Someone', 'Else', self::DOB, '');
        $this->assertNotEmpty($rows);
        $this->assertSame(self::EMAIL, $rows[0]['email']);
    }

    public function test_matches_by_email(): void
    {
        $rows = find_matches('Someone', 'Else', '', self::EMAIL);
        $this->assertNotEmpty($rows);
    }

    public function test_no_match_returns_empty_array(): void
    {
        $rows = find_matches('Totally', 'Unrelated', '1901-01-01', 'nobody@nowhere.invalid');
        $this->assertSame([], $rows);
    }

    public function test_already_linked_student_is_excluded(): void
    {
        // A student with a user_id (already has a login) must never surface as
        // a "match" for a new registration — user_id IS NULL is the guard.
        // Use a disposable sentinel user, never a real account, to link against.
        db()->prepare(
            "INSERT INTO users (username, password_hash, email) VALUES ('phpunit_findmatch_user', 'x', ?)"
        )->execute([self::EMAIL]);
        $userId = (int)db()->lastInsertId();
        db()->prepare('UPDATE students SET user_id = ? WHERE email = ?')->execute([$userId, self::EMAIL]);

        try {
            $rows = find_matches(self::FIRST, self::LAST, '', '');
            $this->assertSame([], $rows);
        } finally {
            // FK is ON DELETE CASCADE — deleting the user removes the student row too,
            // so tearDown's DELETE on students becomes a no-op for this row (fine).
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }
}
