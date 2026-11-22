import { readFileSync } from 'node:fs';
import { checkToolchain } from './toolchain.js';

const root = new URL('../', import.meta.url);
const manifest = JSON.parse(readFileSync(new URL('package.json', root), 'utf8'));
const installed = Object.fromEntries(
    [...Object.keys(manifest.devDependencies), 'playwright', 'playwright-core'].map((name) => [
        name,
        JSON.parse(readFileSync(new URL(`node_modules/${name}/package.json`, root), 'utf8')).version,
    ]),
);
checkToolchain({
    manifest,
    lock: JSON.parse(readFileSync(new URL('package-lock.json', root), 'utf8')),
    dockerfile: readFileSync(new URL('Dockerfile', root), 'utf8'),
    installed,
});
console.log('Browser packages, lockfile, installed tools, and pinned Playwright image are aligned.');
