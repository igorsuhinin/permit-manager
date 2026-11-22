import { checkAccessibility, expectPageReflow, expectVisibleFocus, tabTo } from './accessibility.js';
import { expect, fixtures, login, publicPath, test } from './helpers.js';

for (const locale of ['en', 'lv', 'ru']) {
    test(`admin accessibility scans in ${locale}`, async ({ page }, info) => {
        await page.goto(`/admin/login?lang=${locale}`);
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        await checkAccessibility(page, info, `login-${locale}`);
        await page.locator('.language-switcher summary').click();
        await checkAccessibility(page, info, `language-selector-${locale}`);
        await page.screenshot({
            path: info.outputPath(`language-${locale}.png`),
            fullPage: true,
            animations: 'disabled',
        });
        await page.keyboard.press('Escape');
        await page.goto('/admin/login?lang=en');
        await login(page);
        await page.goto(publicPath(fixtures.legacy.resident));
        for (const [name, path] of [
            ['permits', '/admin/permits'],
            ['blacklist', '/admin/blacklist'],
            ['checks', '/admin/checks'],
            ['generation', '/admin/permits/generate'],
        ]) {
            await page.goto(`${path}?lang=${locale}`);
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            if (name === 'checks') {
                await expect(page.getByRole('columnheader')).toHaveCount(7);
                await expect(page.locator('tbody tr').first().locator('td, th')).toHaveCount(7);
                await expect(page.locator('table')).not.toContainText('/p/check');
                await expect(page.locator('body')).not.toContainText('HTTP');
            }
            await checkAccessibility(page, info, `${name}-${locale}`);
        }
        await page.locator('#term').click();
        await expect(page.locator('#ui-datepicker-div')).toBeVisible();
        await checkAccessibility(page, info, `calendar-${locale}`);
        await expect(page.locator('.ui-datepicker-month')).toHaveAccessibleName(
            { en: 'Select month', lv: 'Izvēlieties mēnesi', ru: 'Выберите месяц' }[locale],
        );
        await page.screenshot({
            path: info.outputPath(`calendar-${locale}.png`),
            fullPage: true,
            animations: 'disabled',
        });
        await page.locator('#term').fill('11.11.2026');
        await page.locator('#term').press('Escape');
        await page.locator('#term').press('Control+Home');
        await expect(page.locator('#ui-datepicker-div .ui-state-active')).toHaveAttribute('data-date', '11');
        await checkAccessibility(page, info, `selected-date-${locale}`);
    });
}

test('keyboard login, skip link, header order, language selection, and Escape focus restoration', async ({ page }) => {
    await page.goto('/admin/login');
    await expect(page.locator('#username')).toBeFocused();
    await page.keyboard.type('admin');
    await page.keyboard.press('Tab');
    await expectVisibleFocus(page.locator('#password'));
    await page.keyboard.type('test-password');
    await page.keyboard.press('Tab');
    await expectVisibleFocus(page.locator('form[action="/admin/login"] button'));
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL('http://php/admin/permits');
    await page.keyboard.press('Tab');
    await expectVisibleFocus(page.locator('.skip-link'));
    await page.keyboard.press('Enter');
    await expect(page.locator('main')).toBeFocused();
    await page.keyboard.press('Tab');
    await expectVisibleFocus(page.locator('main a[href="/admin/permits/generate"]'));

    await page.goto('/admin/permits');
    for (const selector of [
        '.skip-link',
        'header a[href="/admin"]',
        'form[action="/admin/logout"] button',
        '.language-switcher summary',
    ]) {
        await page.keyboard.press('Tab');
        await expectVisibleFocus(page.locator(selector));
    }
    await page.keyboard.press('Enter');
    await expect(page.locator('.language-switcher')).toHaveAttribute('open');
    await page.keyboard.press('Tab');
    await expectVisibleFocus(page.locator('.language-switcher a[hreflang="en"]'));
    await page.keyboard.press('Tab');
    await expectVisibleFocus(page.locator('.language-switcher a[hreflang="lv"]'));
    await page.keyboard.press('Escape');
    await expect(page.locator('.language-switcher')).not.toHaveAttribute('open');
    await expectVisibleFocus(page.locator('.language-switcher summary'));
    await page.keyboard.press('Enter');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Enter');
    await expect(page.locator('html')).toHaveAttribute('lang', 'lv');
    await page.locator('.language-switcher summary').press('Enter');
    await expect(page.locator('.language-switcher a[hreflang="lv"]')).toHaveAttribute('aria-current', 'true');
    await page.keyboard.press('Escape');
    for (const path of ['/admin/permits', '/admin/permits/generate', '/admin/blacklist', '/admin/checks']) {
        await page.keyboard.press('Tab');
        await expectVisibleFocus(page.locator(`.admin-navigation a[href="${path}"]`));
    }
});

test.describe('validation feedback', () => {
    test.use({
        expectedNavigationErrors: [
            [
                { path: '/admin/login', status: 401 },
                { path: '/admin/permits/generate', status: 422 },
                { path: '/admin/blacklist', status: 422 },
            ],
            { scope: 'test' },
        ],
    });
    test('validation errors receive focus and remain associated with the submitted forms', async ({ page }, info) => {
        await page.goto('/admin/login');
        await page.locator('#username').fill('admin');
        await page.locator('#password').fill('incorrect-synthetic-password');
        await page.locator('form[action="/admin/login"] button').click();
        await expect(page.getByRole('alert')).toBeFocused();
        await expect(page.getByRole('alert')).toHaveText('Invalid login or password.');
        await expect(page.locator('form[action="/admin/login"]')).toHaveAttribute('aria-describedby', 'form-error');
        await checkAccessibility(page, info, 'login-error');
        await page.keyboard.press('Tab');
        await expectVisibleFocus(page.locator('#username'));
        await login(page);

        await page.goto('/admin/permits/generate');
        await page.locator('#id').fill('A11Y-VALIDATION');
        await page.locator('#plate').fill('AB1234');
        await page.locator('#apt').fill('12');
        await page.locator('#term').fill('invalid');
        await page.locator('#term').press('Escape');
        await page.locator('form[action="/admin/permits/generate"] button').click();
        await expect(page.getByRole('alert')).toBeFocused();
        await expect(page.locator('#term')).toHaveAttribute('aria-invalid', 'true');
        await expect(page.locator('#term')).toHaveAccessibleDescription(/Invalid expiration date/);
        await checkAccessibility(page, info, 'generation-error');

        await page.goto('/admin/blacklist');
        await page.locator('#blacklist-id').fill('   ');
        await page.locator('form[action="/admin/blacklist"] button[type="submit"]').first().click();
        await expect(page.getByRole('alert')).toBeFocused();
        await expect(page.locator('form[action="/admin/blacklist"]').first()).toHaveAttribute(
            'aria-describedby',
            'form-error',
        );
        await checkAccessibility(page, info, 'blacklist-error');
    });
});

test('datepicker keyboard entry, day and month navigation, selection, and dismissal', async ({ page }, info) => {
    await login(page);
    await page.goto('/admin/permits/generate');
    const term = page.locator('#term');
    const calendar = page.locator('#ui-datepicker-div');
    await term.fill('15.11.2027');
    await term.press('Escape');
    await expect(calendar).toBeHidden();
    await term.press('Control+Home');
    await expect(calendar).toBeVisible();
    await term.press('Control+ArrowRight');
    await term.press('Enter');
    await expect(term).toHaveValue('16.11.2027');
    await expect(calendar).toBeHidden();
    await expect(term).toBeFocused();
    await term.press('Control+Home');
    await term.press('PageDown');
    await term.press('Enter');
    await expect(term).toHaveValue('16.12.2027');
    await term.press('Control+Home');
    await term.press('Escape');
    await expect(calendar).toBeHidden();
    await expect(term).toHaveValue('16.12.2027');
    await term.press('Tab');
    await expectVisibleFocus(page.locator('#guest'));
    await checkAccessibility(page, info, 'keyboard-date');
});

for (const locale of ['en', 'lv', 'ru']) {
    test(`mobile reflow and keyboard table scrolling in ${locale}`, async ({ page }, info) => {
        await login(page);
        await page.goto(publicPath(fixtures.legacy.resident));
        await page.setViewportSize({ width: 320, height: 800 });
        let scrollableTables = 0;
        for (const path of ['permits', 'blacklist', 'checks', 'permits/generate']) {
            await page.goto(`/admin/${path}?lang=${locale}`);
            await expectPageReflow(page);
            await checkAccessibility(page, info, `mobile-${path.replaceAll('/', '-')}-${locale}`);
            if (path === 'permits/generate') {
                await page.locator('.language-switcher summary').click();
                await expectPageReflow(page);
                await checkAccessibility(page, info, `mobile-language-${locale}`);
                await page.screenshot({
                    path: info.outputPath(`mobile-language-${locale}.png`),
                    fullPage: true,
                    animations: 'disabled',
                });
                await page.keyboard.press('Escape');
                await page.locator('#term').click();
                await expectPageReflow(page);
                await checkAccessibility(page, info, `mobile-calendar-${locale}`);
                await page.screenshot({
                    path: info.outputPath(`mobile-calendar-${locale}.png`),
                    fullPage: true,
                    animations: 'disabled',
                });
            } else {
                const region = page.locator('main [role="region"]');
                await tabTo(page, region);
                if (await region.evaluate((element) => element.scrollWidth > element.clientWidth)) {
                    scrollableTables++;
                    await page.keyboard.press('ArrowRight');
                    await expect.poll(() => region.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
                }
            }
        }
        expect(scrollableTables).toBeGreaterThan(0);
    });
}

test('admin content reflows with text enlarged to 200 percent', async ({ page }, info) => {
    await login(page);
    for (const path of ['permits', 'blacklist', 'checks', 'permits/generate']) {
        await page.goto(`/admin/${path}?lang=ru`);
        await page.evaluate(() => {
            document.documentElement.style.fontSize = '200%';
        });
        await expectPageReflow(page);
        await expect(page.locator('h1')).toBeVisible();
        await checkAccessibility(page, info, `text-resize-${path.replaceAll('/', '-')}`);
    }
});
