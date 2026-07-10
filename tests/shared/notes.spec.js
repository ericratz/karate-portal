// @ts-check
// Notes tests — student notes and general notes.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, getCsrfToken, apiPost, BASE, AUTH } = require('../helpers');
const { ADMIN_USER, ADMIN_PASS } = require('../credentials');

const TS = Date.now();

test.describe('instructor notes', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: AUTH.instructor });

    // add_note.php was removed — adding a note is now an inline form on
    // instructor/student_profile.php's "Add Note" card.
    test('instructor: add note form on student_profile.php loads and empty note shows error', async ({ page }) => {
        await visit(page, '/instructor/student_profile.php?id=2', 'add note');
        await expect(page.locator('textarea[name="note_content"]')).toBeVisible();
        // Empty note triggers server error
        await page.evaluate(() => document.querySelector('textarea[name="note_content"]').removeAttribute('required'));
        await page.click('button:has-text("Save Note")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-danger').first()).toContainText('empty');
    });

    test('instructor: valid note saves and shows success', async ({ page }) => {
        await page.goto(BASE + '/instructor/student_profile.php?id=2');
        await page.fill('textarea[name="note_content"]', `Playwright note ${TS}`);
        await page.click('button:has-text("Save Note")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-success').first()).toContainText('saved');
    });
});

test.describe('admin notes', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: AUTH.admin });

    test('admin: student note add and delete round-trip', async ({ page }) => {
        test.setTimeout(20000);
        const text = `Admin note ${TS}`;
        await page.goto(BASE + '/admin/student_notes.php?student_id=2');
        await page.fill('textarea[name="content"]', text);
        await page.click('button:has-text("Save Note")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).toContainText(text);
        await page.click('#editToggle');
        page.once('dialog', d => d.accept());
        await page.locator('.delete-btn button').first().click();
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).not.toContainText(text);
    });

    test('admin: general notes add, search, and roster', async ({ page }) => {
        test.setTimeout(20000);
        const text = `Search test ${TS}`;
        await page.goto(BASE + '/admin/general_notes.php');
        await page.fill('textarea[name="content"]', text);
        await page.click('button:has-text("Save Entry")');
        await page.waitForLoadState('domcontentloaded');
        await page.fill('#noteSearch', text);
        expect(await page.locator('.note-entry:visible').count()).toBeGreaterThanOrEqual(1);
        // Roster shows students table
        await page.goto(BASE + '/admin/student_notes.php');
        await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toBeVisible();
        expect(await page.locator('tbody tr').count()).toBeGreaterThan(0);
    });

    test('admin: general note delete removes it from the list', async ({ page }) => {
        test.setTimeout(20000);
        const text = `Delete test ${TS}`;
        // Add the note
        await page.goto(BASE + '/admin/general_notes.php');
        await page.fill('textarea[name="content"]', text);
        await page.click('button:has-text("Save Entry")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).toContainText(text);
        // Get the note ID from the data-id attribute on its .note-entry element
        const noteId = await page.locator('.note-entry').filter({ hasText: text }).first().getAttribute('data-id');
        if (!noteId) return; // skip if we can't find the ID
        // Submit the delete form via JavaScript to bypass the confirm() dialog
        await page.evaluate((id) => {
            const entry = document.querySelector(`.note-entry[data-id="${id}"]`);
            if (!entry) return;
            const form = entry.querySelector('.delete-btn');
            if (!form) return;
            form.onsubmit = null; // remove confirm() guard
            form.submit();
        }, noteId);
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).not.toContainText(text);
    });

    test('admin: student_notes.php with student_id shows notes for that student', async ({ page }) => {
        await page.goto(BASE + '/admin/student_notes.php?student_id=2');
        await assertNoPhpErrors(page, 'student notes filtered by student');
        // Should show that student's note area, not the roster
        await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toHaveCount(0);
        // The page should have a textarea for adding notes
        await expect(page.locator('textarea[name="content"]')).toBeVisible();
    });
});
