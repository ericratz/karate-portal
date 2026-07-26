<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the class-session instructor helpers
 * (includes/instructors.php). An instructor is a roster person (student typed
 * instructor/admin, usually with no login) addressed as "s:<id>", or an admin
 * login with no roster record addressed as "u:<id>". Seeds one of each relevant
 * kind plus a throwaway session; cleans up in tearDown.
 */
class InstructorSessionTest extends TestCase
{
    private const FAKE_HASH = 'phpunit-not-a-real-hash';
    private const TEST_DATE = '2099-12-31';

    private int $admin_uid       = 0; // admin login, no roster record  -> u:
    private int $plain_uid       = 0; // non-admin login                -> u: invalid
    private int $instr_sid       = 0; // roster instructor, no login    -> s:
    private int $student_sid     = 0; // roster student (not instructor) -> s: invalid
    private int $inactive_sid    = 0; // inactive roster instructor      -> s: invalid
    private int $session_id      = 0;

    #[\Override]
    protected function setUp(): void
    {
        $db = db();

        $user_ins = $db->prepare(
            'INSERT INTO users (username, password_hash, email, is_admin, active) VALUES (?,?,?,?,?)'
        );
        $user_ins->execute(['phpunit_csi_admin', self::FAKE_HASH, 'phpunit_csi_admin@example.com', 1, 1]);
        $this->admin_uid = (int)$db->lastInsertId();
        $user_ins->execute(['phpunit_csi_plain', self::FAKE_HASH, 'phpunit_csi_plain@example.com', 0, 1]);
        $this->plain_uid = (int)$db->lastInsertId();

        // Roster people with NO user_id — the real-world "instructor without a login" case.
        $s_ins = $db->prepare(
            'INSERT INTO students (user_id, first_name, last_name, email, registration_date, student_type, active)
             VALUES (NULL,?,?,?,CURDATE(),?,?)'
        );
        $s_ins->execute(['Phpunit', 'Instructor', 'phpunit_csi_i@example.com', 'instructor', 1]);
        $this->instr_sid = (int)$db->lastInsertId();
        $s_ins->execute(['Phpunit', 'Student', 'phpunit_csi_s@example.com', 'student', 1]);
        $this->student_sid = (int)$db->lastInsertId();
        $s_ins->execute(['Phpunit', 'Inactive', 'phpunit_csi_x@example.com', 'instructor', 0]);
        $this->inactive_sid = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO class_sessions (session_date, class_type) VALUES (?, ?)')
           ->execute([self::TEST_DATE, 'class']);
        $this->session_id = (int)$db->lastInsertId();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $db = db();
        $db->prepare('DELETE FROM class_sessions WHERE id = ?')->execute([$this->session_id]);
        $db->prepare('DELETE FROM students WHERE id IN (?,?,?)')
           ->execute([$this->instr_sid, $this->student_sid, $this->inactive_sid]);
        $db->prepare('DELETE FROM users WHERE id IN (?,?)')->execute([$this->admin_uid, $this->plain_uid]);
    }

    // ── instructor_options ────────────────────────────────────────────────────

    public function test_options_include_roster_instructor_and_admin_login(): void
    {
        $keys = array_column(instructor_options(), 'key');
        $this->assertContains('s:' . $this->instr_sid, $keys, 'roster instructor (no login) should be selectable');
        $this->assertContains('u:' . $this->admin_uid, $keys, 'admin login without a roster record should be selectable');
        $this->assertNotContains('s:' . $this->student_sid, $keys);
        $this->assertNotContains('s:' . $this->inactive_sid, $keys);
        $this->assertNotContains('u:' . $this->plain_uid, $keys);
    }

    // ── filter_instructor_keys ────────────────────────────────────────────────

    public function test_filter_keeps_valid_drops_invalid_and_dedupes(): void
    {
        $kept = filter_instructor_keys([
            's:' . $this->instr_sid,
            's:' . $this->instr_sid,      // duplicate
            'u:' . $this->admin_uid,
            's:' . $this->student_sid,    // not an instructor
            's:' . $this->inactive_sid,   // inactive
            'u:' . $this->plain_uid,      // not an admin
            'x:1', 'garbage', 's:999000', // malformed / unknown
        ]);
        $this->assertEqualsCanonicalizing(
            ['s:' . $this->instr_sid, 'u:' . $this->admin_uid],
            $kept
        );
    }

    public function test_filter_empty_yields_empty(): void
    {
        $this->assertSame([], filter_instructor_keys([]));
        $this->assertSame([], filter_instructor_keys(['nope', 's:', 'u:abc']));
    }

    // ── default_instructor_keys ───────────────────────────────────────────────

    public function test_default_is_the_single_primary_active_admin(): void
    {
        $default = default_instructor_keys();
        $this->assertCount(1, $default);
        $this->assertMatchesRegularExpression('/^[su]:\d+$/', $default[0]);

        // Resolve the primary admin (lowest-id active admin) and confirm the key points at them.
        $row = db()->query(
            'SELECT u.id AS uid, s.id AS sid FROM users u LEFT JOIN students s ON s.user_id = u.id
             WHERE u.is_admin = 1 AND u.active = 1 ORDER BY u.id LIMIT 1'
        )->fetch();
        $expected = $row['sid'] !== null ? 's:' . (int)$row['sid'] : 'u:' . (int)$row['uid'];
        $this->assertSame($expected, $default[0]);
    }

    // ── session read / write round-trip ───────────────────────────────────────

    public function test_set_and_read_back_mixed_roster_and_admin(): void
    {
        set_session_instructors($this->session_id, ['u:' . $this->admin_uid, 's:' . $this->instr_sid]);
        $this->assertEqualsCanonicalizing(
            ['s:' . $this->instr_sid, 'u:' . $this->admin_uid],
            session_instructor_keys($this->session_id)
        );
    }

    public function test_set_is_a_full_rewrite(): void
    {
        set_session_instructors($this->session_id, ['u:' . $this->admin_uid, 's:' . $this->instr_sid]);
        set_session_instructors($this->session_id, ['s:' . $this->instr_sid]);
        $this->assertSame(['s:' . $this->instr_sid], session_instructor_keys($this->session_id));
    }

    public function test_set_empty_clears_and_unrecorded_reads_empty(): void
    {
        $this->assertSame([], session_instructor_keys($this->session_id));
        set_session_instructors($this->session_id, ['s:' . $this->instr_sid]);
        set_session_instructors($this->session_id, []);
        $this->assertSame([], session_instructor_keys($this->session_id));
    }
}
