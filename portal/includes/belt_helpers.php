<?php
// Belt advancement helpers — next rank, test homework URLs, test PDF URLs.
// Included by student dashboard and parent portal.

define('HW_BASE',        'https://noji.com/karate/class/homework/');
define('TEST_BASE',      'https://noji.com/karate/testing/');
define('HW_INDEX_ADULT', 'https://noji.com/karate/class/homework/homework.php');
define('HW_INDEX_YOUTH', 'https://noji.com/karate/class/homework/youth_homework.php');

function hw_index_url(?string $date_of_birth): string {
    if ($date_of_birth) {
        $age = (int)(new DateTime($date_of_birth))->diff(new DateTime())->y;
        if ($age < 16) return HW_INDEX_YOUTH;
    }
    return HW_INDEX_ADULT;
}

// Test homework filenames, keyed by age group then kyu_dan.
// Some ranks share a single PDF across youth/adult; others are age-split.
function _belt_hw_file(string $kyu_dan, bool $is_youth): ?string {
    static $map = [
        'youth' => [
            '10th Kyu' => 'HW-Test-Kyu-10.pdf',
            '9th Kyu'  => 'HW-Test-Kyu-09.pdf',
            '8th Kyu'  => 'HW-Youth-Test-Kyu-08.pdf',
            '7th Kyu'  => 'HW-Youth-Test-Kyu-07.pdf',
            '6th Kyu'  => 'HW-Youth-Test-Kyu-06.pdf',
            '5th Kyu'  => 'HW-Youth-Test-Kyu-05.pdf',
            '4th Kyu'  => 'HW-Test-Kyu-04.pdf',
            '3rd Kyu'  => 'HW-Test-Kyu-03.pdf',
            '2nd Kyu'  => 'HW-Test-Kyu-02.pdf',
            '1st Kyu'  => 'HW-Test-Kyu-01.pdf',
        ],
        'adult' => [
            '10th Kyu' => 'HW-Test-Kyu-10.pdf',
            '9th Kyu'  => 'HW-Test-Kyu-09.pdf',
            '8th Kyu'  => 'HW-Adult-Test-Kyu-08.pdf',
            '7th Kyu'  => 'HW-Adult-Test-Kyu-07.pdf',
            '6th Kyu'  => 'HW-Adult-Test-Kyu-06.pdf',
            '5th Kyu'  => 'HW-Adult-Test-Kyu-05.pdf',
            '4th Kyu'  => 'HW-Test-Kyu-04.pdf',
            '3rd Kyu'  => 'HW-Test-Kyu-03.pdf',
            '2nd Kyu'  => 'HW-Test-Kyu-02.pdf',
            '1st Kyu'  => 'HW-Test-Kyu-01.pdf',
            '1st Dan'  => 'HW-Test-Dan-01.pdf',
            '2nd Dan'  => 'HW-Test-Dan-02.pdf',
        ],
    ];
    return $map[$is_youth ? 'youth' : 'adult'][$kyu_dan] ?? null;
}

function _belt_test_file(string $kyu_dan): ?string {
    static $map = [
        '10th Kyu' => 'Test-Kyu-10.pdf',
        '9th Kyu'  => 'Test-Kyu-09.pdf',
        '8th Kyu'  => 'Test-Kyu-08.pdf',
        '7th Kyu'  => 'Test-Kyu-07.pdf',
        '6th Kyu'  => 'Test-Kyu-06.pdf',
        '5th Kyu'  => 'Test-Kyu-05.pdf',
        '4th Kyu'  => 'Test-Kyu-04.pdf',
        '3rd Kyu'  => 'Test-Kyu-03.pdf',
        '2nd Kyu'  => 'Test-Kyu-02.pdf',
        '1st Kyu'  => 'Test-Kyu-01.pdf',
        '1st Dan'  => 'Test-Dan-01.pdf',
        '2nd Dan'  => 'Test-Dan-02.pdf',
    ];
    return $map[$kyu_dan] ?? null;
}

/**
 * Returns info about a student's next rank, or null if at highest rank.
 *
 * @param string|null $current_kyu_dan  e.g. "7th Kyu", or null for unranked
 * @param string|null $date_of_birth    YYYY-MM-DD; null treated as adult
 * @return array|null ['kyu_dan', 'hw_url', 'test_url']
 */
function belt_next_rank(?string $current_kyu_dan, ?string $date_of_birth): ?array {
    static $all_ranks = null;
    if ($all_ranks === null) {
        // Keyed by kyu_dan => name for display
        $rows = db()->query('SELECT kyu_dan, name FROM ranks ORDER BY rank_order ASC')->fetchAll();
        $all_ranks = [];
        foreach ($rows as $r) {
            $all_ranks[] = ['kyu_dan' => $r['kyu_dan'], 'name' => $r['name']];
        }
    }

    $is_youth = false;
    if ($date_of_birth) {
        $age = (int)(new DateTime($date_of_birth))->diff(new DateTime())->y;
        $is_youth = ($age < 16);
    }

    $kyu_dan_list = array_column($all_ranks, 'kyu_dan');

    if ($current_kyu_dan === null) {
        // Unranked: adults start at 8th Kyu, youth start at 10th Kyu
        $next_kyu_dan = $is_youth ? '10th Kyu' : '8th Kyu';
    } else {
        $idx = array_search($current_kyu_dan, $kyu_dan_list, true);
        if ($idx === false || $idx === count($all_ranks) - 1) return null;
        $next_kyu_dan = $kyu_dan_list[$idx + 1];
    }

    $next_name = $all_ranks[array_search($next_kyu_dan, $kyu_dan_list, true)]['name'];
    $hw_file   = _belt_hw_file($next_kyu_dan, $is_youth);
    $test_file = _belt_test_file($next_kyu_dan);

    return [
        'kyu_dan'  => $next_kyu_dan,
        'name'     => $next_name,
        'hw_url'   => $hw_file   ? HW_BASE   . $hw_file   : null,
        'test_url' => $test_file ? TEST_BASE . $test_file : null,
    ];
}
