import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './specs',
    workers: 1,
    retries: 0,
    forbidOnly: true,
    timeout: 45_000,
    updateSnapshots: 'none',
    snapshotPathTemplate: '/work/snapshots/{arg}{ext}',
    outputDir: '/reports/test-results',
    reporter: [
        ['list'],
        ['html', { outputFolder: '/reports/html', open: 'never' }],
        ['json', { outputFile: '/reports/results.json' }],
    ],
    use: {
        // The suite deliberately cannot target a user-supplied/production origin.
        baseURL: 'http://php',
        browserName: 'chromium',
        viewport: { width: 1280, height: 900 },
        deviceScaleFactor: 2,
        locale: 'en-GB',
        timezoneId: 'Europe/Riga',
        serviceWorkers: 'block',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
