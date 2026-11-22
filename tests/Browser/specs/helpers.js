import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { test as base, expect } from '@playwright/test';
import jsQR from 'jsqr';
import { PNG } from 'pngjs';

export const fixtures = JSON.parse(readFileSync('/fixtures/demo.json', 'utf8'));
if (fixtures.origin !== 'https://example.com') {
    throw new Error('Browser assertions require the isolated synthetic QR origin.');
}

export const test = base.extend({
    expectedNavigationErrors: [[], { option: true }],
    page: async ({ context, expectedNavigationErrors }, use) => {
        const issues = [];
        const navigationErrors = [];
        await context.route('**/*', async (route) => {
            const url = new URL(route.request().url());
            if (url.origin === 'http://php' || url.protocol === 'data:') {
                return route.continue();
            }
            issues.push(`Unexpected external request: ${url.origin}`);
            return route.abort();
        });
        const page = await context.newPage();
        page.on('response', (response) => {
            if (response.request().isNavigationRequest() && response.status() >= 400) {
                navigationErrors.push({ path: new URL(response.url()).pathname, status: response.status() });
            }
        });
        page.on('pageerror', (error) => issues.push(error.message));
        page.on('console', (message) => {
            const expected = expectedNavigationErrors.some(
                ({ path, status }) =>
                    message.location().url === `http://php${path}` &&
                    message
                        .text()
                        .startsWith(`Failed to load resource: the server responded with a status of ${status} (`),
            );
            if (message.type() === 'error' && !expected) issues.push(message.text());
        });
        await page.exposeFunction('recordCspViolation', (directive) => issues.push(`CSP: ${directive}`));
        await page.addInitScript(() => {
            // Observe requests for the native dialog without opening OS UI in automated runs.
            window.__printRequests = [];
            window.print = () => {
                const qr = document.querySelector('img.qr');
                window.__printRequests.push({
                    readyState: document.readyState,
                    fonts: document.fonts.status,
                    qrReady: Boolean(qr?.complete && qr.naturalWidth > 0),
                });
            };
            document.addEventListener('securitypolicyviolation', (event) => {
                window.recordCspViolation(event.effectiveDirective);
            });
        });
        await page.clock.install({ time: new Date('2026-11-11T10:00:00Z') });
        await use(page);
        expect(navigationErrors, 'Only explicitly expected navigation errors occurred').toEqual(
            expectedNavigationErrors,
        );
        expect(issues, 'No external requests, JavaScript errors, console errors, or CSP violations').toEqual([]);
        await page.close();
    },
});

export { expect };

export async function login(page) {
    await page.goto('/admin/login');
    await page.getByLabel('Login', { exact: true }).fill('admin');
    await page.getByLabel('Password', { exact: true }).fill('test-password');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL('http://php/admin/permits');
}

export function publicPath(query, path = '/p/check') {
    return `${path}?${new URLSearchParams(query)}`;
}

export function decodePng(buffer) {
    const image = PNG.sync.read(buffer);
    const code = jsQR(new Uint8ClampedArray(image.data), image.width, image.height, {
        inversionAttempts: 'dontInvert',
    });
    expect(code, 'The rendered pixels must contain an independently readable QR code').not.toBeNull();
    return code;
}

export async function generatedQr(page) {
    const code = decodePng(await page.locator('img.qr').screenshot());
    expect(code.data).toBe(await page.locator('img.qr').getAttribute('alt'));
    const url = new URL(code.data);
    expect(url.origin).toBe(fixtures.origin);
    expect(url.pathname).toBe('/p/check');
    return url;
}

export async function capture(page, name) {
    mkdirSync('/reports/screenshots', { recursive: true });
    await page.screenshot({ path: `/reports/screenshots/${name}.png`, fullPage: true, animations: 'disabled' });
}

export async function checkPrint(page, kind, expectedUrl, info) {
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.admin-toolbar')).toBeHidden();
    await expect(page.locator('.admin-notice')).toBeHidden();
    await page.evaluate(() => document.fonts.ready);
    const geometry = await page.locator('.wrapper').evaluateAll((elements) =>
        elements.map((element) => {
            const rect = element.getBoundingClientRect();
            return { width: rect.width, height: rect.height };
        }),
    );
    expect(geometry).toHaveLength(2);
    for (const panel of geometry) {
        expect((panel.width * 25.4) / 96).toBeCloseTo(148, 1);
        expect((panel.height * 25.4) / 96).toBeCloseTo(105, 1);
    }
    const qr = await page.locator('img.qr').boundingBox();
    expect((qr.height * 25.4) / 96).toBeCloseTo(35, 1);
    expect(qr.width).toBeCloseTo(qr.height, 1);
    await expect(page.locator('.Raleway').first()).toHaveCSS('font-family', '"Raleway Dots", cursive');
    await expect(page.locator('.Plate')).toHaveCSS(
        'font-family',
        '"Alte DIN 1451 Mittelschrift gepraegt", "Comic Sans MS"',
    );
    expect(await page.evaluate(() => document.fonts.check('20px "Raleway Dots"'))).toBe(true);
    expect(await page.evaluate(() => document.fonts.check('20px "Alte DIN 1451 Mittelschrift gepraegt"'))).toBe(true);
    await expect(page.locator('body')).toHaveScreenshot(`${kind}-print.png`, { animations: 'disabled' });

    // A3 fits both unchanged 148 mm panels without scaling or changing application CSS.
    const pdf = info.outputPath(`${kind}.pdf`);
    await page.pdf({
        path: pdf,
        format: 'A3',
        landscape: true,
        printBackground: true,
        margin: { top: '0', right: '0', bottom: '0', left: '0' },
    });
    await info.attach(`${kind}-print`, { path: pdf, contentType: 'application/pdf' });
    const metadata = execFileSync('pdfinfo', [pdf], { encoding: 'utf8', timeout: 10_000 });
    expect(metadata).toMatch(/Pages:\s+1\b/);
    const raster = info.outputPath(`${kind}-pdf`);
    execFileSync('pdftoppm', ['-r', '144', '-singlefile', '-png', pdf, raster], { timeout: 10_000 });
    const printed = decodePng(readFileSync(`${raster}.png`));
    expect(printed.data).toBe(expectedUrl.href);
    const { topLeftCorner: left, topRightCorner: right } = printed.location;
    const modules = 17 + 4 * printed.version;
    const outerWidthMm = (((Math.hypot(right.x - left.x, right.y - left.y) * (modules + 8)) / modules) * 25.4) / 144;
    expect(
        Math.abs(outerWidthMm - 35),
        'The PDF QR, including its quiet zone, retains its 35 mm physical size',
    ).toBeLessThan(0.8);
    await page.emulateMedia({ media: 'screen' });
}
