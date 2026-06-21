<?php
// Shared helpers for the registration flow (register.php and google-register.php)

function mask_email(string $email): string {
    if (strpos($email, '@') === false) return '***';
    $parts  = explode('@', $email, 2);
    $local  = $parts[0];
    $domain = $parts[1];
    return substr($local, 0, 1) . '***@' . $domain;
}

function find_matches(string $first, string $last, string $dob, string $email): array {
    $stmt = db()->prepare(
        'SELECT s.id, s.first_name, s.last_name, s.date_of_birth,
                s.city_state_zip, s.email, s.student_type,
                (SELECT r.name FROM student_ranks sr
                 JOIN ranks r ON r.id = sr.rank_id
                 WHERE sr.student_id = s.id
                 ORDER BY r.rank_order DESC LIMIT 1) AS rank_name
         FROM students s
         WHERE s.user_id IS NULL
           AND (
               (LOWER(s.first_name) = LOWER(?) AND LOWER(s.last_name) = LOWER(?))
               OR (? != \'\' AND s.date_of_birth IS NOT NULL AND s.date_of_birth = ?)
               OR (? != \'\' AND s.email IS NOT NULL AND s.email != \'\' AND LOWER(s.email) = LOWER(?))
           )
         ORDER BY
             (LOWER(s.first_name) = LOWER(?) AND LOWER(s.last_name) = LOWER(?)) DESC,
             s.first_name, s.last_name'
    );
    $stmt->execute([$first, $last, $dob, $dob, $email, $email, $first, $last]);
    return $stmt->fetchAll();
}
