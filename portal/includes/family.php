<?php
// Family scoping helpers — which student records a logged-in user may access.
// A parent's scope is their own student record plus any children linked via
// student_guardians. Shared by the parent pages, api/paypal_create.php, and
// the api/v1/parent endpoints, so the ownership boundary lives in one place.

require_once __DIR__ . '/db.php';

/** The logged-in user's own students row, or null if none is linked. */
function family_own_student(int $user_id): ?array {
    $stmt = db()->prepare('SELECT * FROM students WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Child student ids linked to a parent's student record via student_guardians. */
function family_child_ids(int $parent_student_id): array {
    $stmt = db()->prepare(
        'SELECT child_student_id FROM student_guardians WHERE parent_student_id = ?'
    );
    $stmt->execute([$parent_student_id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Every student id this user may access: own record plus linked children. */
function family_allowed_ids(int $user_id): array {
    $own = family_own_student($user_id);
    if ($own === null) return [];
    $own_id = (int)$own['id'];
    return array_merge([$own_id], family_child_ids($own_id));
}

/** True if the user may access the given student record. */
function family_can_access(int $user_id, int $student_id): bool {
    return $student_id > 0 && in_array($student_id, family_allowed_ids($user_id), true);
}

/**
 * The subset of a students row a parent/student may see over the API.
 * Whitelist, not blacklist — admin-only fields (notes, active_override, …)
 * never leave the server even if the query selected *.
 */
function family_student_profile(array $student): array {
    return [
        'id'                      => (int)$student['id'],
        'first_name'              => (string)$student['first_name'],
        'last_name'               => (string)$student['last_name'],
        'date_of_birth'           => $student['date_of_birth'] ?? null,
        'phone'                   => $student['phone'] ?? null,
        'email'                   => (string)$student['email'],
        'emergency_contact_name'  => $student['emergency_contact_name'] ?? null,
        'emergency_contact_phone' => $student['emergency_contact_phone'] ?? null,
        'street_address'          => $student['street_address'] ?? null,
        'city_state_zip'          => $student['city_state_zip'] ?? null,
        'uniform_size'            => $student['uniform_size'] ?? null,
        'belt_size'               => $student['belt_size'] ?? null,
        'medical_note'            => $student['medical_note'] ?? null,
        'registration_date'       => $student['registration_date'] ?? null,
        'student_type'            => (string)$student['student_type'],
        'injury_waiver'           => (bool)$student['injury_waiver'],
        'injury_waiver_date'      => $student['injury_waiver_date'] ?? null,
    ];
}
