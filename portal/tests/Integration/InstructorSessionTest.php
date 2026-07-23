<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the class-session instructor helpers
 * (includes/instructors.php), which decide who counts as an instructor and
 * gate what the take-attendance endpoint will persist as the taught-by set.
 * Seeds one account of each relevant kind (admin, active instructor, plain
 * student, inactive instructor) plus a throwaway session; cleans up in tearDown.
 */
class InstructorSessionTest extends TestCase
{
    private const FAKE_HASH   = 'phpunit-not-a-real-hash';
    private const TEST_DATE   = '2099-12-31';

    private int $admin_id          = 0;
    private int $instructor_id     = 0;
    private int $student_id        = 0;
    private int $inactive_instr_id = 0;
    private int $session_id         = 0;

    #[\Override]
    protected function setUp(): void
    {
        $db = db();

        $user_ins = $db->prepare(
            'INSERT INTO users (username, password_hash, email, is_admin, active) VALUES (?,?,?,?,?)'
        );
        $user_ins->execute(['phpunit_instr_admin', self::FAKE_HASH, 'phpunit_instr_admin@example.com', 1, 1]);
        $this->admin_id = (int)$db->lastInsertId();
        $user_ins->execute(['phpunit_instr_active', self::FAKE_HASH, 'phpunit_instr_active@example.com', 0, 1]);
        $this->instructor_id = (int)$db->lastInsertId();
        $user_ins->execute(['phpunit_instr_student', self::FAKE_HASH, 'phpunit_instr_student@example.com', 0, 1]);
        $this->student_id = (int)$db->lastInsertId();
        $user_ins->execute(['phpunit_instr_inactive', self::FAKE_HASH, 'phpunit_instr_inactive@example.com', 0, 0]);
        $this->inactive_instr_id = (int)$db->lastInsertId();

        // student rows give the two non-admin users their student_type
        $student_ins = $db->prepare(
            'INSERT INTO students (user_id, first_name, last_name, email, registration_date, student_type)
             VALUES (?,?,?,?,CURDATE(),?)'
        );
        $student_ins->execute([$this->instructor_id, 'Phpunit', 'Instructor', 'phpunit_si@example.com', 'instructor']);
        $student_ins->execute([$this->student_id, 'Phpunit', 'Student', 'phpunit_ss@example.com', 'student']);
        $student_ins->execute([$this->inactive_instr_id, 'Phpunit', 'Inactive', 'phpunit_sx@example.com', 'instructor']);

        $db->prepare('INSERT INTO class_sessions (session_date, class_type) VALUES (?, ?)')
           ->execute([self::TEST_DATE, 'class']);
        $this->session_id = (int)$db->lastInsertId();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $db = db();
        // class_session_instructors rows cascade from the session and the users
        $db->prepare('DELETE FROM class_sessions WHERE id = ?')->execute([$this->session_id]);
        // students cascade from users
        $db->prepare('DELETE FROM users WHERE id IN (?,?,?,?)')
           ->execute([$this->admin_id, $this->instructor_id, $this->student_id, $this->inactive_instr_id]);
    }

    // ── filter_instructor_ids ─────────────────────────────────────────────────

    public function test_keeps_admins_and_active_instructors(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->admin_id, $this->instructor_id],
            filter_instructor_ids([$this->admin_id, $this->instructor_id])
        );
    }

    public function test_drops_plain_students_and_inactive_instructors(): void
    {
        $kept = filter_instructor_ids([
            $this->admin_id,
            $this->instructor_id,
            $this->student_id,        // not an instructor/admin
            $this->inactive_instr_id, // instructor type but user inactive
        ]);
        $this->assertEqualsCanonicalizing([$this->admin_id, $this->instructor_id], $kept);
    }

    public function test_drops_unknown_ids_and_dedupes(): void
    {
        $kept = filter_instructor_ids([
            $this->instructor_id,
            $this->instructor_id, // duplicate
            999000111,            // no such user
        ]);
        $this->assertSame([$this->instructor_id], $kept);
    }

    public function test_empty_and_non_positive_ids_yield_empty(): void
    {
        $this->assertSame([], filter_instructor_ids([]));
        $this->assertSame([], filter_instructor_ids([0, -$this->admin_id]));
    }

    // ── default_instructor_ids ────────────────────────────────────────────────

    public function test_default_is_the_single_primary_active_admin(): void
    {
        $default = default_instructor_ids();
        $this->assertCount(1, $default);

        $row = db()->prepare('SELECT is_admin, active FROM users WHERE id = ?');
        $row->execute([$default[0]]);
        $u = $row->fetch();
        $this->assertSame(1, (int)$u['is_admin']);
        $this->assertSame(1, (int)$u['active']);

        // It's the lowest-id admin (the founder account), not the one we just
        // seeded — the seed admin predates it.
        $this->assertLessThan($this->admin_id, $default[0]);
    }

    // ── session_instructor_ids / set_session_instructors ──────────────────────

    public function test_set_and_read_back_taught_by_set_sorted(): void
    {
        set_session_instructors($this->session_id, [$this->instructor_id, $this->admin_id]);
        $expected = [$this->admin_id, $this->instructor_id];
        sort($expected);
        $this->assertSame($expected, session_instructor_ids($this->session_id));
    }

    public function test_set_is_a_full_rewrite(): void
    {
        set_session_instructors($this->session_id, [$this->admin_id, $this->instructor_id]);
        set_session_instructors($this->session_id, [$this->instructor_id]);
        $this->assertSame([$this->instructor_id], session_instructor_ids($this->session_id));
    }

    public function test_set_empty_clears_the_set(): void
    {
        set_session_instructors($this->session_id, [$this->admin_id]);
        set_session_instructors($this->session_id, []);
        $this->assertSame([], session_instructor_ids($this->session_id));
    }

    public function test_no_instructors_recorded_reads_empty(): void
    {
        $this->assertSame([], session_instructor_ids($this->session_id));
    }
}
