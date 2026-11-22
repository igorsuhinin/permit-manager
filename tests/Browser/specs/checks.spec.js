import { checkAccessibility } from './accessibility.js';
import { expect, fixtures, login, publicPath, test } from './helpers.js';

const removeHeadings = {
    en: 'Remove blacklist entry?',
    lv: 'Izņemt ierakstu no melnā saraksta?',
    ru: 'Удалить запись из чёрного списка?',
};

for (const [locale, block, unblock, heading, cancel, added, removed] of [
    [
        'en',
        'Blacklist ID',
        'Remove from blacklist',
        'Blacklist permit ID?',
        'Cancel',
        'The ID has been added to the blacklist.',
        'The ID is no longer blacklisted.',
    ],
    [
        'lv',
        'Bloķēt numuru',
        'Noņemt no melnā saraksta',
        'Pievienot numuru melnajam sarakstam?',
        'Atcelt',
        'Atļaujas numurs ir pievienots melnajam sarakstam.',
        'Atļaujas numurs vairs nav melnajā sarakstā.',
    ],
    [
        'ru',
        'Заблокировать номер',
        'Удалить из чёрного списка',
        'Добавить номер в чёрный список?',
        'Отмена',
        'Номер разрешения добавлен в чёрный список.',
        'Номер разрешения больше не находится в чёрном списке.',
    ],
]) {
    test(`legacy check details and confirmed blacklist toggle in ${locale}`, async ({ page }, info) => {
        // An older script may remain cached under the unversioned URL.
        const staleRequests = [];
        await page.route(
            (url) => url.pathname === '/assets/admin.js' && url.search === '',
            async (route) => {
                staleRequests.push(route.request().url());
                await route.fulfill({ contentType: 'text/javascript', body: '// Stale unversioned asset.' });
            },
        );
        await login(page);
        expect(staleRequests, 'The current page must load the revised script URL').toEqual([]);
        const query = fixtures.legacy.guest;
        await page.goto(publicPath(query));
        await expect(page.locator('h2.valid')).toHaveText('Ir derīga');
        const history = `/admin/checks?q=${query.id}&result=valid&page=1`;
        await page.goto(`${history}&lang=${locale}`);
        const rows = page
            .getByRole('row')
            .filter({ has: page.getByRole('rowheader', { name: query.id, exact: true }) });
        const row = rows.first();
        await expect(row.locator('td, th').nth(2)).toHaveText('12');
        await expect(row.locator('td, th').nth(3)).toHaveText('31.12.2099');
        const dialog = page.getByRole('dialog');
        await row.getByRole('button', { name: block, exact: true }).click();
        await expect(dialog).toHaveAccessibleName(heading);
        await expect(dialog.locator('#confirmation-message')).toContainText(query.id);
        await expect(dialog.getByRole('button', { name: cancel, exact: true })).toBeFocused();
        await checkAccessibility(page, info, `check-blacklist-${locale}`);
        await dialog.getByRole('button', { name: cancel, exact: true }).click();
        await expect(dialog).toBeHidden();
        await expect(row.getByRole('button', { name: block, exact: true })).toBeVisible();
        await row.getByRole('button', { name: block, exact: true }).click();
        await dialog.getByRole('button', { name: block, exact: true }).click();
        await expect(page).toHaveURL(`http://php${history}`);
        await expect(page.getByRole('status')).toHaveText(added);
        await expect(page.getByRole('status')).toBeFocused();
        await expect(rows.getByRole('button', { name: block, exact: true })).toHaveCount(0);
        await expect(row.getByRole('button', { name: unblock, exact: true })).toBeVisible();
        await page.goto(publicPath(query));
        await expect(page.locator('.status-invalid')).toBeVisible();
        await page.goto(history);
        await row.getByRole('button', { name: unblock, exact: true }).click();
        await expect(dialog).toHaveAccessibleName(removeHeadings[locale]);
        await expect(dialog.getByRole('button', { name: unblock, exact: true })).toBeVisible();
        await expect(dialog.getByRole('button', { name: block, exact: true })).toHaveCount(0);
        await page.screenshot({ path: info.outputPath(`unblock-${locale}.png`), animations: 'disabled' });
        await checkAccessibility(page, info, `check-unblock-${locale}`);
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await row.getByRole('button', { name: unblock, exact: true }).click();
        await dialog.getByRole('button', { name: unblock, exact: true }).click();
        await expect(page).toHaveURL(`http://php${history}`);
        await expect(page.getByRole('status')).toHaveText(removed);
        await expect(rows.getByRole('button', { name: unblock, exact: true })).toHaveCount(0);
        expect(staleRequests, 'Navigation must not reuse the old script URL').toEqual([]);
        await expect(row.getByRole('button', { name: block, exact: true })).toBeVisible();
        await page.goto(publicPath(query));
        await expect(page.locator('h2.valid')).toHaveText('Ir derīga');
        await page.goto(`/admin/checks?q=${query.id}&result=rejected`);
        await expect(row).toContainText(
            {
                en: 'ID was blacklisted when checked',
                lv: 'Pārbaudes brīdī numurs bija melnajā sarakstā',
                ru: 'Номер был в чёрном списке на момент проверки',
            }[locale],
        );
        await expect(row.getByRole('button', { name: block, exact: true })).toBeVisible();
        await expect(rows.getByRole('button', { name: unblock, exact: true })).toHaveCount(0);
    });
}

test.describe('history actions without JavaScript', () => {
    test.use({ javaScriptEnabled: false });
    test('blacklist changes remain disabled when confirmation cannot run', async ({ page }) => {
        await page.goto(publicPath(fixtures.legacy.guest));
        await login(page);
        await page.goto(`/admin/checks?q=${fixtures.legacy.guest.id}`);
        await expect(page.locator('form[data-confirm] button').first()).toBeDisabled();
        await expect(page.locator('noscript p')).toContainText('Enable JavaScript');
    });
});
