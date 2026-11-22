import assert from 'node:assert/strict';

export function checkToolchain({ manifest, lock, dockerfile, installed }) {
    for (const [name, version] of Object.entries(manifest.devDependencies)) {
        assert.match(version, /^\d+\.\d+\.\d+$/, `${name} must use an exact version`);
        assert.equal(lock.packages[''].devDependencies[name], version, `${name}: manifest/lock mismatch`);
        assert.equal(lock.packages[`node_modules/${name}`]?.version, version, `${name}: lock resolution mismatch`);
        assert.equal(installed[name], version, `${name}: rebuild the Docker tool image with locked dependencies`);
    }
    const version = manifest.devDependencies['@playwright/test'];
    assert.ok(version, 'The Playwright package is required');
    for (const name of ['playwright', 'playwright-core']) {
        assert.equal(lock.packages[`node_modules/${name}`]?.version, version, `${name}: Playwright version mismatch`);
        assert.equal(installed[name], version, `${name}: installed Playwright version mismatch`);
    }
    const image = dockerfile.match(
        /^FROM mcr\.microsoft\.com\/playwright:v(\d+\.\d+\.\d+)-[a-z0-9-]+@sha256:[a-f0-9]{64}\s*$/m,
    );
    assert.ok(image, 'The official Playwright image must have an explicit version and SHA-256 digest');
    assert.equal(image[1], version, 'Playwright package and Docker image versions must match');
}
