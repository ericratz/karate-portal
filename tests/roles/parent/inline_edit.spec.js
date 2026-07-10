// @ts-check
// Tests for inline profile editing on parent/index.php.
// Verifies the HTMX card-swap flow: Edit → fill → Save → card updates without full reload.
// Also verifies auth boundary: parent cannot update an unlinked student.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, apiPost, BASE, AUTH } = require('../../helpers');

const PARENT_OWN_ID = 6;
const CHILD_EMILY   = 4;
const UNLINKED_ID   = 2; // Sarah Johnson — not linked to this parent

test.describe('Parent inline profile edit', () => {
    test.use({ storageState: AUTH.parent });

    test('Edit button toggles form into view', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);
        await assertNoPhpErrors(page, 'parent inline edit setup');

        // Form starts hidden
        await expect(page.locator('#profile-edit')).toBeHidden();

        await page.click('#profileEditBtn');

        await expect(page.locator('#profile-edit')).toBeVisible();
        await expect(page.locator('#profile-view')).toBeHidden();
        await expect(page.locator('#profileCancelBtn')).toBeVisible();
        await expect(page.locator('#profileEditBtn')).toContainText('Save');
    });

    test('Cancel button restores view mode without saving', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);

        await page.click('#profileEditBtn');
        await page.fill('input[name="first_name"]', 'SHOULD_NOT_SAVE');
        await page.click('#profileCancelBtn');

        await expect(page.locator('#profile-edit')).toBeHidden();
        await expect(page.locator('#profile-view')).toBeVisible();
        await expect(page.locator('#profileEditBtn')).toContainText('Edit');
        // View section should not show the unsaved value
        await expect(page.locator('#profile-view')).not.toContainText('SHOULD_NOT_SAVE');
    });

    test('Save updates card in-place via HTMX without full page reload', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);
        await assertNoPhpErrors(page, 'parent inline edit before save');

        // Read original first name from view section
        const originalFirst = await page.locator('#profile-view').textContent();

        await page.click('#profileEditBtn');
        await page.fill('input[name="first_name"]', 'EmilyEdited');

        // Capture navigation events — there should be none (HTMX swap, not full reload)
        let navigated = false;
        page.on('framenavigated', () => { navigated = true; });

        await page.click('#profileEditBtn'); // now labelled "Save"
        await page.waitForLoadState('networkidle');

        expect(navigated).toBe(false);
        // hn() applies ucwords(strtolower()), so 'EmilyEdited' displays as 'Emilyedited'
        await expect(page.locator('#profile-card')).toContainText('Emilyedited');

        // Restore original value
        await page.click('#profileEditBtn');
        await page.fill('input[name="first_name"]', 'Emily');
        await page.click('#profileEditBtn');
        await page.waitForLoadState('networkidle');
    });

    test('Save shows success alert inside card', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);

        await page.click('#profileEditBtn');
        // Keep first name as-is, just save
        await page.click('#profileEditBtn');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('#profile-card .alert-success')).toBeVisible();
    });

    test('Empty first name shows validation error inside card', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);

        await page.click('#profileEditBtn');
        await page.fill('input[name="first_name"]', '');
        await page.fill('input[name="last_name"]', '');

        // Remove required attribute to bypass browser validation and hit server-side check
        await page.evaluate(() => {
            document.querySelectorAll('#profile-form input[required]').forEach(el => el.removeAttribute('required'));
        });

        await page.click('#profileEditBtn');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('#profile-card .alert-danger')).toBeVisible();
    });

    test('parent cannot update an unlinked student via API', async ({ page }) => {
        // Load a page to get a valid CSRF token in session
        await page.goto(BASE + `/parent/?student_id=${PARENT_OWN_ID}`);

        const { body } = await apiPost(page, `/parent/?student_id=${PARENT_OWN_ID}`, {
            action:     'update_profile',
            student_id: String(UNLINKED_ID), // unlinked — should be blocked
            first_name: 'HACKED',
            last_name:  'HACKED',
        });

        // Response must not confirm a save of the injected name
        expect(body).not.toContain('HACKED');
    });
});
