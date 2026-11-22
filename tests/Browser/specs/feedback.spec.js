import { checkAccessibility, expectPageReflow, expectVisibleFocus } from './accessibility.js';
import { expect, login, test } from './helpers.js';

for (const [locale, heading, cancel, removeLabel, added, removed] of [
    [
        'en',
        'Remove blacklist entry?',
        'Cancel',
        'Remove from blacklist',
        'The ID has been added to the blacklist.',
        'The ID is no longer blacklisted.',
    ],
    [
        'lv',
        'Izņemt ierakstu no melnā saraksta?',
        'Atcelt',
        'Noņemt no melnā saraksta',
        'Atļaujas numurs ir pievienots melnajam sarakstam.',
        'Atļaujas numurs vairs nav melnajā sarakstā.',
    ],
    [
        'ru',
        'Удалить запись из чёрного списка?',
        'Отмена',
        'Удалить из чёрного списка',
        'Номер разрешения добавлен в чёрный список.',
        'Номер разрешения больше не находится в чёрном списке.',
    ],
]) {
    test(`styled confirmation and success feedback in ${locale}`, async ({ page }, info) => {
        await login(page);
        await page.goto(`/admin/blacklist?lang=${locale}`);
        const id = `DEMO-FEEDBACK-${locale}-${info.repeatEachIndex}-"<test>`;
        await page.locator('#blacklist-id').fill(id);
        await page.locator('form[action="/admin/blacklist"] button[type="submit"]').first().click();
        await expect(page.getByRole('status')).toHaveText(added);
        await expect(page.getByRole('status')).toBeFocused();
        await checkAccessibility(page, info, `added-${locale}`);
        await page.reload();
        await expect(page.getByRole('status')).toHaveCount(0);

        const row = page.getByRole('row').filter({ has: page.getByRole('rowheader', { name: id, exact: true }) });
        const remove = row.getByRole('button', { name: removeLabel, exact: true });
        await remove.press('Enter');
        const dialog = page.getByRole('dialog', { name: heading, exact: true });
        await expect(dialog).toHaveAccessibleDescription(new RegExp(id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
        await expectVisibleFocus(dialog.getByRole('button', { name: cancel, exact: true }));
        await checkAccessibility(page, info, `confirmation-${locale}`);
        await page.screenshot({ path: info.outputPath(`confirmation-${locale}.png`), animations: 'disabled' });
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(remove).toBeFocused();
        await expect(row).toBeVisible();

        await remove.press('Enter');
        await expect(dialog).toBeVisible();
        await expectVisibleFocus(dialog.getByRole('button', { name: cancel, exact: true }));
        await page.keyboard.press('Tab');
        await expectVisibleFocus(dialog.getByRole('button', { name: removeLabel, exact: true }));
        await page.setViewportSize({ width: 320, height: 800 });
        await expectPageReflow(page);
        await checkAccessibility(page, info, `confirmation-mobile-${locale}`);
        await page.screenshot({ path: info.outputPath(`confirmation-mobile-${locale}.png`), animations: 'disabled' });
        await dialog.getByRole('button', { name: removeLabel, exact: true }).press('Enter');
        await expect(page.getByRole('status')).toHaveText(removed);
        await expect(page.getByRole('status')).toBeFocused();
        await expect(row).toHaveCount(0);
        await checkAccessibility(page, info, `removed-${locale}`);
    });
}

test.describe('removal without JavaScript', () => {
    test.use({ javaScriptEnabled: false });
    test('removal stays disabled when confirmation cannot run', async ({ page }) => {
        await login(page);
        await page.goto('/admin/blacklist');
        await expect(page.locator('form[data-confirm] button').first()).toBeDisabled();
        await expect(page.locator('noscript p')).toContainText('Enable JavaScript');
    });
});
