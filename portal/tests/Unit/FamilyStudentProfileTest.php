<?php

use PHPUnit\Framework\TestCase;

/**
 * family_student_profile() is the whitelist between the students table and
 * the parent-facing API — admin-only columns must never appear in its output.
 */
class FamilyStudentProfileTest extends TestCase
{
    /** A full students row, including the admin-only columns. */
    private function studentRow(): array
    {
        return [
            'id'                      => '42',
            'user_id'                 => '7',
            'first_name'              => 'Test',
            'last_name'               => 'Student',
            'date_of_birth'           => '2012-03-04',
            'phone'                   => '8015551234',
            'email'                   => 'kid@example.com',
            'emergency_contact_name'  => 'EC Name',
            'emergency_contact_phone' => '8015555678',
            'street_address'          => '1 Main St',
            'city_state_zip'          => 'Orem, UT 84058',
            'registration_date'       => '2024-01-15',
            'student_type'            => 'student',
            'waiver_signed'           => '1',
            'waiver_date'             => '2024-01-15',
            'injury_waiver'           => '1',
            'injury_waiver_date'      => '2024-01-16',
            'notes'                   => 'ADMIN ONLY internal note',
            'medical_note'            => 'peanut allergy',
            'uniform_size'            => '3',
            'belt_size'               => '2',
            'active'                  => '1',
            'active_override'         => '0',
        ];
    }

    public function test_admin_only_columns_never_leave_the_server(): void
    {
        $profile = family_student_profile($this->studentRow());

        foreach (['notes', 'active', 'active_override', 'user_id', 'waiver_signed', 'waiver_date'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $profile, "profile leaked '$forbidden'");
        }
    }

    public function test_parent_editable_fields_pass_through(): void
    {
        $profile = family_student_profile($this->studentRow());

        $this->assertSame('Test', $profile['first_name']);
        $this->assertSame('kid@example.com', $profile['email']);
        $this->assertSame('peanut allergy', $profile['medical_note']);
        $this->assertSame('2012-03-04', $profile['date_of_birth']);
    }

    public function test_types_are_normalized_for_json(): void
    {
        $profile = family_student_profile($this->studentRow());

        $this->assertSame(42, $profile['id']);
        $this->assertTrue($profile['injury_waiver']);
    }

    public function test_missing_nullable_columns_default_to_null(): void
    {
        $row = $this->studentRow();
        unset($row['medical_note'], $row['uniform_size'], $row['date_of_birth']);
        $profile = family_student_profile($row);

        $this->assertNull($profile['medical_note']);
        $this->assertNull($profile['uniform_size']);
        $this->assertNull($profile['date_of_birth']);
    }
}
