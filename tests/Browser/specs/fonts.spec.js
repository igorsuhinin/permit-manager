import { execFileSync } from 'node:child_process';
import { expect, login, test } from './helpers.js';

const latvian = 'ĀČĒĢĪĶĻŅŠŪŽāčēģīķļņšūž';

async function renderedFonts(session, root, selector) {
    const { nodeId } = await session.send('DOM.querySelector', { nodeId: root, selector });
    expect(nodeId, `Font sample exists: ${selector}`).toBeGreaterThan(0);
    const { fonts } = await session.send('CSS.getPlatformFontsForNode', { nodeId });
    return fonts.filter(({ glyphCount }) => glyphCount > 0);
}

test('original permit fonts render Latvian letters without system fallback', async ({ page }, info) => {
    await login(page);
    await page
        .getByRole('row')
        .filter({ has: page.getByRole('rowheader', { name: '1001', exact: true }) })
        .getByRole('button', { name: 'Reprint', exact: true })
        .click();
    await page.evaluate(() => document.fonts.ready);
    const session = await page.context().newCDPSession(page);
    await session.send('DOM.enable');
    await session.send('CSS.enable');
    const { root } = await session.send('DOM.getDocument');

    for (const media of ['screen', 'print']) {
        await page.emulateMedia({ media });
        const fonts = await renderedFonts(session, root.nodeId, 'h1.Raleway');
        expect(fonts.length).toBeGreaterThan(0);
        for (const font of fonts) {
            expect(font.familyName).toBe('Raleway Dots');
            expect(font.isCustomFont).toBe(true);
        }
    }
    const pdf = info.outputPath('latvian-permit.pdf');
    await page.pdf({ path: pdf, format: 'A3', landscape: true, printBackground: true });
    await info.attach('latvian-permit', { path: pdf, contentType: 'application/pdf' });
    // Keep content order when Latin and accented letters use separate embedded subsets.
    const text = execFileSync('pdftotext', ['-raw', pdf, '-'], { encoding: 'utf8', timeout: 10_000 });
    for (const word of ['Atļauja', 'automašīnas', 'autostāvvietas', 'Derīga']) expect(text).toContain(word);
    const embedded = execFileSync('pdffonts', [pdf], { encoding: 'utf8', timeout: 10_000 });
    expect(embedded).toContain('RalewayDots-Regular');

    const families = [
        { name: 'Raleway Dots', className: 'Raleway', characters: `ABC123${latvian}` },
        { name: 'Audiowide', className: 'Audiowide', characters: `ABC123${latvian}` },
        { name: 'Faster One', className: 'Faster', characters: `ABC123${latvian}` },
        // Fugaz One's original upstream release only provides a Latin subset.
        { name: 'Fugaz One', className: 'Fugaz', characters: 'ABC123' },
    ];
    await page.evaluate(async (samples) => {
        const container = document.createElement('div');
        container.id = 'font-coverage';
        for (const [familyIndex, family] of samples.entries()) {
            const row = document.createElement('p');
            row.style.fontSize = '32px';
            const label = document.createElement('strong');
            label.textContent = `${family.name}: `;
            row.append(label);
            for (const [characterIndex, character] of Array.from(family.characters).entries()) {
                const sample = document.createElement('span');
                sample.id = `font-${familyIndex}-${characterIndex}`;
                sample.className = family.className;
                sample.textContent = character;
                row.append(sample);
            }
            container.append(row);
        }
        document.body.append(container);
        await Promise.all(samples.map((family) => document.fonts.load(`24px "${family.name}"`, family.characters)));
        await document.fonts.ready;
    }, families);
    for (const [familyIndex, family] of families.entries()) {
        for (const [characterIndex, character] of Array.from(family.characters).entries()) {
            const fonts = await renderedFonts(session, root.nodeId, `#font-${familyIndex}-${characterIndex}`);
            expect(fonts, `${family.name} draws ${character} with its own glyph`).toHaveLength(1);
            expect(fonts[0].familyName).toBe(family.name);
            expect(fonts[0].isCustomFont).toBe(true);
        }
    }
    await page.locator('#font-coverage').screenshot({ path: info.outputPath('latvian-fonts.png') });
    await session.detach();
});
