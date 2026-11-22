import { capture, checkPrint, expect, fixtures, generatedQr, login, publicPath, test } from './helpers.js';

test('anonymous access, login, three languages, calendar, mobile navigation, and logout', async ({ page }) => {
    await page.goto('/admin/permits');
    await expect(page).toHaveURL('http://php/admin/login');
    await expect(page.locator('body')).not.toContainText(/parking|issued permits/i);
    await login(page);
    await page.getByRole('navigation').getByRole('link', { name: 'Generate a permit', exact: true }).click();
    for (const [locale, title] of [
        ['lv', 'Ģenerēt atļauju'],
        ['ru', 'Создать разрешение'],
        ['en', 'Generate a permit'],
    ]) {
        await page.locator('.language-switcher summary').click();
        await page.locator(`.language-switcher a[hreflang="${locale}"]`).click();
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        await page.goto('/admin/permits/generate');
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(title);
        await page.locator('#term').click();
        await expect(page.locator('#ui-datepicker-div')).toBeVisible();
        await page.locator('.ui-datepicker-year').selectOption('2027');
        await page.locator('.ui-datepicker-next').click();
        await page.locator('.ui-datepicker-month').selectOption('10');
        await page.locator('.ui-datepicker-calendar').getByRole('link', { name: '15', exact: true }).click();
        await expect(page.locator('#term')).toHaveValue('15.11.2027');
        await page.locator('.language-switcher summary').click();
        await page.locator('.language-switcher summary').press('Escape');
        await expect(page.locator('.language-switcher')).not.toHaveAttribute('open');
    }
    await page.locator('#id').fill('1005');
    await page.locator('#plate').fill('LM5678');
    await page.locator('#apt').fill('12');
    await page.locator('#term').fill('31.12.2099');
    await page.locator('#term').press('Escape');
    await page.getByRole('heading', { level: 1 }).click();
    await expect(page.locator('#ui-datepicker-div')).toHaveCSS('display', 'none');
    await capture(page, 'admin-generator');
    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await expect(page.locator('form[action="/admin/logout"] button')).toBeVisible();
    await page.locator('form[action="/admin/logout"] button').click();
    await expect(page).toHaveURL('http://php/admin/login');
    await page.goto('/admin/permits');
    await expect(page).toHaveURL('http://php/admin/login');
});

for (const [kind, id] of [
    ['resident', '1003'],
    ['guest', '1004'],
]) {
    test(`${kind} issuance, independent QR decoding, and physical print regression`, async ({ page }, info) => {
        await login(page);
        await page.goto('/admin/permits/generate');
        await page.getByLabel('Permit ID', { exact: true }).fill(id);
        await page.getByLabel('Vehicle plate', { exact: true }).fill('AB1234');
        await page.getByLabel('Apartment', { exact: true }).fill('12');
        await page.getByLabel('Valid until', { exact: true }).fill('31.12.2099');
        await page.locator('#term').press('Escape');
        if (kind === 'guest') await page.getByLabel('Guest permit?', { exact: true }).check();
        await page.locator('form[action="/admin/permits/generate"] button').click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'lv');
        await expect(page.getByRole('status')).toHaveText('The permit has been generated and saved.');
        await expect(page.getByRole('status')).toBeFocused();
        const url = await generatedQr(page);
        expect(await page.evaluate(() => window.__printRequests)).toEqual([]);
        expect(url.searchParams.get('id')).toBe(id);
        expect(url.searchParams.get('v')).toBe('2');
        expect(url.searchParams.get(kind === 'guest' ? 'apt' : 'plate')).toBe(kind === 'guest' ? '12' : 'AB1234');
        expect(url.searchParams.get('sign')).toMatch(/^[a-f0-9]{64}$/);
        await checkPrint(page, kind, url, info);
        await page.goto(publicPath(url.searchParams));
        await expect(page.locator('h2.valid')).toHaveText('Ir derīga');
        await expect(page.locator('html')).toHaveAttribute('lang', 'lv');
        url.searchParams.set('sign', 'invalid');
        await page.goto(publicPath(url.searchParams));
        await expect(page.locator('.status-invalid')).toBeVisible();
    });
}

test('legacy and version 2 reprinting preserve the decoded URL and issuance list', async ({ page }) => {
    await login(page);
    const rows = await page.locator('tbody tr').count();
    for (const [id, version] of [
        ['1001', null],
        ['1002', '2'],
    ]) {
        await page.goto('/admin/permits');
        await page
            .getByRole('row')
            .filter({ has: page.getByRole('rowheader', { name: id, exact: true }) })
            .getByRole('button', { name: 'Reprint', exact: true })
            .click();
        const first = await generatedQr(page);
        await expect
            .poll(() => page.evaluate(() => window.__printRequests))
            .toEqual([{ readyState: 'complete', fonts: 'loaded', qrReady: true }]);
        await expect(page.getByRole('status')).toHaveText(
            'The existing permit is ready to print. No new permit was created.',
        );
        expect(first.searchParams.get('v')).toBe(version);
        await page.goto('/admin/permits');
        await page
            .getByRole('row')
            .filter({ has: page.getByRole('rowheader', { name: id, exact: true }) })
            .getByRole('button', { name: 'Reprint', exact: true })
            .click();
        expect((await generatedQr(page)).href).toBe(first.href);
        await expect.poll(() => page.evaluate(() => window.__printRequests)).toHaveLength(1);
        await page.goto(publicPath(first.searchParams));
        await expect(page.locator('h2.valid')).toHaveText('Ir derīga');
    }
    await page.goto('/admin/permits');
    await expect(page.locator('tbody tr')).toHaveCount(rows);
    await capture(page, 'admin-permits');
});

test('blacklist management changes an existing legacy permit immediately and appears in history', async ({ page }) => {
    await login(page);
    const query = { ...fixtures.legacy.resident };
    await page.goto(publicPath(query));
    await expect(page.locator('h2.valid')).toHaveText('Ir derīga');
    await expect(page.locator('.wrapper')).toHaveScreenshot('public-valid.png');
    await page.goto('/admin/blacklist');
    await page.locator('#blacklist-id').fill(query.id);
    await page.locator('#blacklist-reason').fill('Synthetic demonstration');
    await page.getByRole('button', { name: 'Blacklist ID', exact: true }).click();
    await expect(page.getByRole('status')).toHaveText('The ID has been added to the blacklist.');
    await expect(page.getByRole('status')).toBeFocused();
    await page.goto(publicPath(query));
    await expect(page.locator('.status-invalid')).toBeVisible();
    await expect(page.locator('body')).toContainText('Atļauja ir anulēta');
    await expect(page.locator('.wrapper')).toHaveScreenshot('public-revoked.png');
    await page.goto('/admin/checks');
    await expect(page.locator('tbody')).toContainText(query.id);
    await page.goto('/admin/blacklist');
    const remove = page
        .getByRole('row')
        .filter({ has: page.getByRole('rowheader', { name: query.id, exact: true }) })
        .getByRole('button', { name: 'Remove from blacklist', exact: true });
    const removals = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url() === 'http://php/admin/blacklist') removals.push(request);
    });
    await remove.click();
    const dialog = page.getByRole('dialog', { name: 'Remove blacklist entry?', exact: true });
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText(
        `Remove ID "${query.id}" from the blacklist? Permits with this ID may become valid again.`,
    );
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(remove).toBeVisible();
    expect(removals).toHaveLength(0);
    await expect(page.getByRole('status')).toHaveCount(0);
    await remove.click();
    await dialog.getByRole('button', { name: 'Remove from blacklist', exact: true }).click();
    await expect(page.getByRole('status')).toHaveText('The ID is no longer blacklisted.');
    await expect(page.getByRole('status')).toBeFocused();
    expect(removals).toHaveLength(1);
    await page.reload();
    await expect(page.getByRole('status')).toHaveCount(0);
    await page.goto(publicPath(query, '/p/check.php'));
    await expect(page.locator('h2.valid')).toHaveText('Ir derīga');
});

test('unrecorded legacy resident/guest checks, expiry, revocation, and private error headers', async ({ page }) => {
    for (const [name, query] of Object.entries(fixtures.legacy)) {
        for (const path of ['/p/check', '/p/check.php']) {
            const response = await page.goto(publicPath(query, path));
            expect(response.status()).toBe(200);
            expect(response.headers()['cache-control']).toBe('no-store');
            expect(response.headers()['referrer-policy']).toBe('no-referrer');
            expect(response.headers()['x-content-type-options']).toBe('nosniff');
            expect(response.headers()['x-frame-options']).toBe('DENY');
            expect(response.headers()['content-security-policy']).toContain("frame-ancestors 'none'");
            await expect(page.locator('html')).toHaveAttribute('lang', 'lv');
            await expect(
                page.locator(name === 'resident' || name === 'guest' ? '.status-valid' : '.status-invalid'),
            ).toBeVisible();
        }
    }
    await login(page);
    await page.getByRole('searchbox').fill(fixtures.legacy.resident.id);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('tbody')).toHaveText('No permits match your search.');
    const forged = await page.request.post('/admin/blacklist', {
        form: { id: '5555', action: 'add', csrf: 'invalid' },
    });
    expect(forged.status()).toBe(403);
    const missing = await page.request.get('/non-existing-page', {
        headers: { 'X-Request-ID': 'caller-controlled-id' },
    });
    expect(missing.status()).toBe(404);
    expect(missing.headers()['x-request-id']).toMatch(/^[a-f0-9]{32}$/);
    expect(forged.headers()['x-request-id']).toMatch(/^[a-f0-9]{32}$/);
    expect(missing.headers()['x-request-id']).not.toBe(forged.headers()['x-request-id']);
    expect(await missing.text()).not.toMatch(/Slim|Exception|Stack trace|\/var\/www/);
    for (const path of ['/config/database.php', '/fixtures/demo.json', '/tests/Support/browser-fixtures.php']) {
        const response = await page.request.get(path);
        expect([403, 404]).toContain(response.status());
    }
});
