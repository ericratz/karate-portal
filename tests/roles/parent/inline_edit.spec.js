// @ts-check
// Tests for inline profile editing on the parent dashboard (React SPA).
// Verifies the card flow: Edit → fill → Save → card updates without a reload
// (React state + fetch to api/v1/parent/profile.php, replacing the old HTMX
// swap). Also verifies the auth boundary: a parent cannot update an unlinked
// student through the JSON API.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

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

    test('Save updates card in-place without full page reload', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);
        await assertNoPhpErrors(page, 'parent inline edit before save');

        await page.click('#profileEditBtn');
        await page.fill('input[name="first_name"]', 'EmilyEdited');

        // Capture navigation events — there should be none (React fetch, not a reload)
        let navigated = false;
        page.on('framenavigated', () => { navigated = true; });

        await page.click('#profileEditBtn'); // now labelled "Save"
        // personName() mirrors PHP hn() (ucwords(strtolower())),
        // so 'EmilyEdited' displays as 'Emilyedited'
        await expect(page.locator('#profile-card')).toContainText('Emilyedited');
        expect(navigated).toBe(false);

        // Restore original value
        await page.click('#profileEditBtn');
        await page.fill('input[name="first_name"]', 'Emily');
        await page.click('#profileEditBtn');
        await expect(page.locator('#profile-card')).toContainText('Emily');
    });

    test('Save shows success alert inside card', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);

        await page.click('#profileEditBtn');
        // Keep first name as-is, just save
        await page.click('#profileEditBtn');

        await expect(page.locator('#profile-card .alert-success')).toBeVisible();
    });

    test('Empty first name shows validation error inside card', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);

        await page.click('#profileEditBtn');
        await page.fill('input[name="first_name"]', '');
        await page.fill('input[name="last_name"]', '');

        // Remove required attribute to bypass browser validation and hit the
        // server-side check (api/v1/parent/profile.php answers 422)
        await page.evaluate(() => {
            document.querySelectorAll('#profile-form input[required]').forEach(el => el.removeAttribute('required'));
        });

        await page.click('#profileEditBtn');

        await expect(page.locator('#profile-card .alert-danger')).toBeVisible();
    });

    test('parent cannot update an unlinked student via API', async ({ page }) => {
        // Load the SPA to establish the session, then hit the JSON API the way
        // the React client does: CSRF token from me.php, X-CSRF-Token header.
        await page.goto(BASE + `/parent/?student_id=${PARENT_OWN_ID}`);

        const result = await page.evaluate(async ({ base, unlinkedId }) => {
            const me = await (await fetch(base + '/api/v1/me.php', { credentials: 'same-origin' })).json();
            const res = await fetch(base + '/api/v1/parent/profile.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': me.data.csrf_token,
                },
                body: JSON.stringify({
                    student_id: unlinkedId, // unlinked — must be blocked
                    first_name: 'HACKED',
                    last_name:  'HACKED',
                }),
            });
            return { status: res.status, body: await res.text() };
        }, { base: BASE, unlinkedId: UNLINKED_ID });

        expect(result.status).toBe(403);
        // Response must not confirm a save of the injected name
        expect(result.body).not.toContain('HACKED');
    });
});
