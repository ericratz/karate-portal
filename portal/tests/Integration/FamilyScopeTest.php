<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the family scoping helpers (includes/family.php),
 * which gate every parent-facing page and api/v1/parent endpoint.
 * Seeds a sentinel family (parent user + own student + linked child) plus an
 * unrelated student and a student-less user; cleans up in tearDown.
 */
class FamilyScopeTest extends TestCase
{
    private const PARENT_USER   = 'phpunit_family_parent';
    private const OUTSIDER_USER = 'phpunit_family_outsider';
    private const FAKE_HASH     = 'phpunit-not-a-real-hash';

    private int $parent_user_id   = 0;
    private int $outsider_user_id = 0;
    private int $parent_sid       = 0;
    private int $child_sid        = 0;
    private int $unrelated_sid    = 0;

    #[\Override]
    protected function setUp(): void
    {
        $db = db();

        $user_ins = $db->prepare(
            'INSERT INTO users (username, password_hash, email) VALUES (?,?,?)'
        );
        $user_ins->execute([self::PARENT_USER, self::FAKE_HASH, 'phpunit_family_parent@example.com']);
        $this->parent_user_id = (int)$db->lastInsertId();
        $user_ins->execute([self::OUTSIDER_USER, self::FAKE_HASH, 'phpunit_family_outsider@example.com']);
        $this->outsider_user_id = (int)$db->lastInsertId();

        $student_ins = $db->prepare(
            'INSERT INTO students (user_id, first_name, last_name, email, registration_date, student_type)
             VALUES (?,?,?,?,CURDATE(),?)'
        );
        $student_ins->execute([$this->parent_user_id, 'Phpunit', 'Parent', 'phpunit_family_p@example.com', 'parent']);
        $this->parent_sid = (int)$db->lastInsertId();
        $student_ins->execute([null, 'Phpunit', 'Child', 'phpunit_family_c@example.com', 'student']);
        $this->child_sid = (int)$db->lastInsertId();
        $student_ins->execute([null, 'Phpunit', 'Unrelated', 'phpunit_family_u@example.com', 'student']);
        $this->unrelated_sid = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO student_guardians (parent_student_id, child_student_id) VALUES (?,?)')
           ->execute([$this->parent_sid, $this->child_sid]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $db = db();
        // student_guardians rows cascade with the students below
        $db->prepare('DELETE FROM students WHERE id IN (?,?,?)')
           ->execute([$this->parent_sid, $this->child_sid, $this->unrelated_sid]);
        $db->prepare('DELETE FROM users WHERE id IN (?,?)')
           ->execute([$this->parent_user_id, $this->outsider_user_id]);
    }

    // ── family_own_student ────────────────────────────────────────────────────

    public function test_own_student_resolves_from_user_id(): void
    {
        $own = family_own_student($this->parent_user_id);
        $this->assertNotNull($own);
        $this->assertSame($this->parent_sid, (int)$own['id']);
    }

    public function test_user_without_student_record_has_no_own_student(): void
    {
        $this->assertNull(family_own_student($this->outsider_user_id));
    }

    // ── family_child_ids / family_allowed_ids ─────────────────────────────────

    public function test_child_ids_lists_linked_children_only(): void
    {
        $this->assertSame([$this->child_sid], family_child_ids($this->parent_sid));
    }

    public function test_allowed_ids_is_own_record_plus_children(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->parent_sid, $this->child_sid],
            family_allowed_ids($this->parent_user_id)
        );
    }

    public function test_allowed_ids_empty_for_user_without_student_record(): void
    {
        $this->assertSame([], family_allowed_ids($this->outsider_user_id));
    }

    // ── family_can_access ─────────────────────────────────────────────────────

    public function test_parent_can_access_own_record_and_child(): void
    {
        $this->assertTrue(family_can_access($this->parent_user_id, $this->parent_sid));
        $this->assertTrue(family_can_access($this->parent_user_id, $this->child_sid));
    }

    public function test_parent_cannot_access_unrelated_student(): void
    {
        $this->assertFalse(family_can_access($this->parent_user_id, $this->unrelated_sid));
    }

    public function test_outsider_cannot_access_anyone(): void
    {
        $this->assertFalse(family_can_access($this->outsider_user_id, $this->parent_sid));
        $this->assertFalse(family_can_access($this->outsider_user_id, $this->child_sid));
    }

    public function test_non_positive_student_ids_rejected(): void
    {
        $this->assertFalse(family_can_access($this->parent_user_id, 0));
        $this->assertFalse(family_can_access($this->parent_user_id, -$this->child_sid));
    }
}
