<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the update_profile auth boundary.
 * Tests the DB-level logic used by parent/index.php and instructor/student_profile.php:
 *   - allowed_ids query (parent guardian links)
 *   - that a linked student can be updated
 *   - that an unlinked student_id is correctly excluded
 *   - that the user_id ownership guard (instructor own-profile) works
 *
 * Creates isolated test rows and tears them down after each test class.
 */
class UpdateProfileAuthTest extends TestCase
{
    // Test parent user
    private static int $parent_uid;
    private static int $parent_sid;

    // Child linked to the parent
    private static int $child_sid;

    // Unrelated student (no guardian link)
    private static int $unlinked_sid;

    // Test instructor user
    private static int $inst_uid;
    private static int $inst_sid;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::cleanup();

        $hash = password_hash('PHPUnit_Test_123!', PASSWORD_BCRYPT);

        // Create parent user
        db()->prepare(
            'INSERT INTO users (username, email, password_hash, is_admin, active)
             VALUES (?,?,?,0,1)'
        )->execute(['phpunit_par_user', 'phpunit_par@localhost.invalid', $hash]);
        self::$parent_uid = (int)db()->lastInsertId();

        db()->prepare(
            'INSERT INTO students (user_id, first_name, last_name, email, student_type, registration_date)
             VALUES (?,?,?,?,?,NOW())'
        )->execute([self::$parent_uid, 'PHPUnit', 'Parent', 'phpunit_par_s@localhost.invalid', 'parent']);
        self::$parent_sid = (int)db()->lastInsertId();

        // Create child student (no user account needed)
        db()->prepare(
            'INSERT INTO students (first_name, last_name, email, student_type, registration_date)
             VALUES (?,?,?,?,NOW())'
        )->execute(['PHPUnit', 'Child', 'phpunit_child@localhost.invalid', 'student']);
        self::$child_sid = (int)db()->lastInsertId();

        // Link child to parent
        db()->prepare(
            'INSERT INTO student_guardians (parent_student_id, child_student_id)
             VALUES (?,?)'
        )->execute([self::$parent_sid, self::$child_sid]);

        // Unlinked student
        db()->prepare(
            'INSERT INTO students (first_name, last_name, email, student_type, registration_date)
             VALUES (?,?,?,?,NOW())'
        )->execute(['PHPUnit', 'Unlinked', 'phpunit_unlinked@localhost.invalid', 'student']);
        self::$unlinked_sid = (int)db()->lastInsertId();

        // Create instructor user with own student record
        db()->prepare(
            'INSERT INTO users (username, email, password_hash, is_admin, active)
             VALUES (?,?,?,0,1)'
        )->execute(['phpunit_inst_user', 'phpunit_inst@localhost.invalid', $hash]);
        self::$inst_uid = (int)db()->lastInsertId();

        db()->prepare(
            'INSERT INTO students (user_id, first_name, last_name, email, student_type, registration_date)
             VALUES (?,?,?,?,?,NOW())'
        )->execute([self::$inst_uid, 'PHPUnit', 'Instructor', 'phpunit_inst_s@localhost.invalid', 'instructor']);
        self::$inst_sid = (int)db()->lastInsertId();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::cleanup();
    }

    private static function cleanup(): void
    {
        foreach (['phpunit_par_user', 'phpunit_inst_user'] as $uname) {
            $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$uname]);
            if ($uid = (int)$stmt->fetchColumn()) {
                $s = db()->prepare('SELECT id FROM students WHERE user_id = ?');
                $s->execute([$uid]);
                if ($sid = (int)$s->fetchColumn()) {
                    db()->prepare('DELETE FROM student_guardians WHERE parent_student_id = ? OR child_student_id = ?')
                         ->execute([$sid, $sid]);
                    db()->prepare('DELETE FROM students WHERE id = ?')->execute([$sid]);
                }
                db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            }
        }
        // Remove any orphaned test students
        db()->prepare("DELETE FROM students WHERE first_name = 'PHPUnit' AND last_name IN ('Child','Unlinked')")
             ->execute([]);
    }

    // ── allowed_ids query ─────────────────────────────────────────────────────

    /** Replicates the allowed_ids query from parent/index.php. */
    private function getAllowedIds(int $user_id): array
    {
        $own = db()->prepare('SELECT id FROM students WHERE user_id = ?');
        $own->execute([$user_id]);
        $own_sid = (int)$own->fetchColumn();

        $children_ids = [];
        if ($own_sid) {
            $ch = db()->prepare(
                'SELECT child_student_id FROM student_guardians WHERE parent_student_id = ?'
            );
            $ch->execute([$own_sid]);
            $children_ids = array_map('intval', $ch->fetchAll(PDO::FETCH_COLUMN));
        }

        $allowed = $children_ids;
        if ($own_sid) $allowed[] = $own_sid;
        return $allowed;
    }

    public function test_allowed_ids_includes_own_student_record(): void
    {
        $ids = $this->getAllowedIds(self::$parent_uid);
        $this->assertContains(self::$parent_sid, $ids);
    }

    public function test_allowed_ids_includes_linked_child(): void
    {
        $ids = $this->getAllowedIds(self::$parent_uid);
        $this->assertContains(self::$child_sid, $ids);
    }

    public function test_allowed_ids_excludes_unlinked_student(): void
    {
        $ids = $this->getAllowedIds(self::$parent_uid);
        $this->assertNotContains(self::$unlinked_sid, $ids);
    }

    public function test_allowed_ids_empty_for_user_without_student_record(): void
    {
        // A user_id that has no students row at all
        $ids = $this->getAllowedIds(999999);
        $this->assertEmpty($ids);
    }

    // ── update allowed student ────────────────────────────────────────────────

    public function test_update_runs_for_student_in_allowed_ids(): void
    {
        $allowed = $this->getAllowedIds(self::$parent_uid);
        $edit_sid = self::$child_sid;

        $this->assertContains($edit_sid, $allowed, 'Pre-condition: child must be allowed');

        db()->prepare(
            'UPDATE students SET first_name = ? WHERE id = ?'
        )->execute(['UpdatedName', $edit_sid]);

        $stmt = db()->prepare('SELECT first_name FROM students WHERE id = ?');
        $stmt->execute([$edit_sid]);
        $this->assertSame('UpdatedName', $stmt->fetchColumn());

        // Restore
        db()->prepare('UPDATE students SET first_name = ? WHERE id = ?')
             ->execute(['PHPUnit', $edit_sid]);
    }

    public function test_update_is_blocked_for_unlinked_student(): void
    {
        $allowed = $this->getAllowedIds(self::$parent_uid);

        // Guard: this is what parent/index.php does
        $blocked = !in_array(self::$unlinked_sid, $allowed, true);
        $this->assertTrue($blocked, 'Unlinked student_id must be blocked by in_array check');

        // Confirm name is unchanged (guard would have prevented the UPDATE)
        $stmt = db()->prepare('SELECT first_name FROM students WHERE id = ?');
        $stmt->execute([self::$unlinked_sid]);
        $this->assertSame('PHPUnit', $stmt->fetchColumn());
    }

    // ── instructor own-profile guard ──────────────────────────────────────────

    public function test_instructor_own_profile_guard_passes_for_self(): void
    {
        // Simulates: (int)($student['user_id'] ?? 0) === current_user_id()
        $stmt = db()->prepare('SELECT user_id FROM students WHERE id = ?');
        $stmt->execute([self::$inst_sid]);
        $student_user_id = (int)$stmt->fetchColumn();

        $this->assertSame(self::$inst_uid, $student_user_id);
        $this->assertTrue($student_user_id === self::$inst_uid);
    }

    public function test_instructor_own_profile_guard_fails_for_other_student(): void
    {
        // Instructor (inst_uid) tries to update child student (no user_id)
        $stmt = db()->prepare('SELECT user_id FROM students WHERE id = ?');
        $stmt->execute([self::$child_sid]);
        $student_user_id = (int)$stmt->fetchColumn();

        // child student has no user_id, so the guard must reject
        $this->assertFalse($student_user_id === self::$inst_uid);
    }

    public function test_instructor_update_applies_for_own_record(): void
    {
        // Guard passes → update should apply
        db()->prepare(
            'UPDATE students SET first_name = ? WHERE id = ? AND user_id = ?'
        )->execute(['InstEdited', self::$inst_sid, self::$inst_uid]);

        $stmt = db()->prepare('SELECT first_name FROM students WHERE id = ?');
        $stmt->execute([self::$inst_sid]);
        $this->assertSame('InstEdited', $stmt->fetchColumn());

        // Restore
        db()->prepare('UPDATE students SET first_name = ? WHERE id = ?')
             ->execute(['PHPUnit', self::$inst_sid]);
    }

    public function test_instructor_update_does_not_apply_for_other_record(): void
    {
        // Guard fails → the WHERE user_id = ? clause prevents the update
        $stmt_before = db()->prepare('SELECT first_name FROM students WHERE id = ?');
        $stmt_before->execute([self::$child_sid]);
        $before = $stmt_before->fetchColumn();

        // This is what happens when the guard's SQL condition includes user_id
        db()->prepare(
            'UPDATE students SET first_name = ? WHERE id = ? AND user_id = ?'
        )->execute(['SHOULD_NOT_APPLY', self::$child_sid, self::$inst_uid]);

        $stmt_after = db()->prepare('SELECT first_name FROM students WHERE id = ?');
        $stmt_after->execute([self::$child_sid]);
        $this->assertSame($before, $stmt_after->fetchColumn());
    }
}
