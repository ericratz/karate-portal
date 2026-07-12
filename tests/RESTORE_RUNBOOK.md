# Database Restore Runbook

How to restore a karate-portal backup, and how to verify it worked.
Last drill: **2026-07-10** against `backups/karate_backup_2026-07-09_213803.sql` — passed
(24/24 tables, 1,587/1,587 rows, 0 foreign-key orphans).

## Backup sources

- **Live (StackCP)**: `cron/backup.php` runs Sundays 7:00 AM Utah, writes
  `karate_YYYY-MM-DD.sql` to `/home/sites/35b/0/049118ce4f/backups/karate/`,
  keeps 8 weeks. Download via StackCP file manager, or use the admin portal's
  DB backup page (`portal/admin/db_backup.php`).
- **Local**: `backups/` in the repo checkout.

A valid backup starts with a `-- Database backup:` header and ends with
`-- End of backup`. If the footer is missing, the export was cut off — do not
trust the file.

## Restore procedure (local, MySQL 8.0 outside XAMPP)

PowerShell, adjusting the file name:

```powershell
$mysql = "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe"
$env:MYSQL_PWD = '<root password>'

# 1. Restore into a scratch DB first — never straight over karate_portal
& $mysql -u root -e "DROP DATABASE IF EXISTS karate_restore_test; CREATE DATABASE karate_restore_test CHARACTER SET utf8mb4;"
Get-Content "backups\<backup-file>.sql" -Raw | & $mysql -u root karate_restore_test
```

Exit code 0 means the SQL ran without errors.

## Verification checklist

1. **Row counts match the file.** Count `INSERT INTO` lines per table in the
   dump and compare with `SELECT COUNT(*)` per restored table. The dump writes
   one INSERT per row, so the numbers must match exactly.
2. **No foreign-key orphans.** The dump imports with `FOREIGN_KEY_CHECKS = 0`,
   so run LEFT-JOIN orphan checks afterward, at minimum:
   - `payments.student_id` → `students.id`
   - `attendance.student_id` → `students.id`, `attendance.session_id` → `class_sessions.id`
   - `student_ranks.student_id` → `students.id`, `student_ranks.rank_id` → `ranks.id`
   - `belt_tests.student_id` → `students.id`
   - `parent_students.parent_user_id` → `users.id`
3. **Spot-check credentials survive.** `SELECT username, LENGTH(password_hash)
   FROM users LIMIT 3;` — bcrypt hashes are exactly 60 chars.

## Promoting the restore (disaster scenario)

Only after the scratch restore verifies clean:

```powershell
& $mysql -u root -e "DROP DATABASE karate_portal; CREATE DATABASE karate_portal CHARACTER SET utf8mb4;"
Get-Content "backups\<backup-file>.sql" -Raw | & $mysql -u root karate_portal
```

On live (StackCP): import the dump through phpMyAdmin into the live database,
then immediately log into the portal and check the roster, payments page, and
one student profile.

## Cleanup

```powershell
& $mysql -u root -e "DROP DATABASE karate_restore_test;"
```

## Cadence

Run this drill after any change to `cron/backup.php`, and otherwise roughly
quarterly — a backup that has never been restored is not a backup.
