import assert from 'node:assert/strict';
import test from 'node:test';
import { checkToolchain } from './toolchain.js';

function fixture() {
    return {
        manifest: { devDependencies: { '@playwright/test': '1.2.3', formatter: '2.3.4' } },
        lock: {
            packages: {
                '': { devDependencies: { '@playwright/test': '1.2.3', formatter: '2.3.4' } },
                'node_modules/@playwright/test': { version: '1.2.3' },
                'node_modules/formatter': { version: '2.3.4' },
                'node_modules/playwright': { version: '1.2.3' },
                'node_modules/playwright-core': { version: '1.2.3' },
            },
        },
        installed: { '@playwright/test': '1.2.3', formatter: '2.3.4', playwright: '1.2.3', 'playwright-core': '1.2.3' },
        dockerfile: `FROM mcr.microsoft.com/playwright:v1.2.3-noble@sha256:${'a'.repeat(64)}\n`,
    };
}

test('accepts aligned tool versions and a digest-pinned browser image', () => {
    assert.doesNotThrow(() => checkToolchain(fixture()));
});

for (const [label, mutate, message] of [
    ['floating package', (f) => (f.manifest.devDependencies.formatter = '^2.3.4'), /exact version/],
    ['stale root lock', (f) => (f.lock.packages[''].devDependencies.formatter = '2.3.3'), /manifest\/lock/],
    ['stale resolution', (f) => (f.lock.packages['node_modules/formatter'].version = '2.3.3'), /lock resolution/],
    ['stale installed tools', (f) => (f.installed.formatter = '2.3.3'), /rebuild the Docker tool image/],
    [
        'stale browser driver lock',
        (f) => (f.lock.packages['node_modules/playwright'].version = '1.2.2'),
        /version mismatch/,
    ],
    ['stale installed driver', (f) => (f.installed['playwright-core'] = '1.2.2'), /version mismatch/],
    [
        'image version mismatch',
        (f) => (f.dockerfile = f.dockerfile.replace('v1.2.3-', 'v1.2.4-')),
        /versions must match/,
    ],
    ['missing digest', (f) => (f.dockerfile = 'FROM mcr.microsoft.com/playwright:v1.2.3-noble\n'), /SHA-256 digest/],
    ['invalid digest', (f) => (f.dockerfile = f.dockerfile.replace('a'.repeat(64), 'bad')), /SHA-256 digest/],
]) {
    test(`rejects ${label}`, () => {
        const value = fixture();
        mutate(value);
        assert.throws(() => checkToolchain(value), message);
    });
}
