import { writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { expect } from './helpers.js';

export async function checkAccessibility(page, info, label) {
    await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => {})));
        if (window.jQuery) await window.jQuery(':animated').promise();
    });
    const result = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'])
        .analyze();
    const report = info.outputPath(`${label}.axe.json`);
    writeFileSync(report, JSON.stringify(result, null, 2));
    await info.attach(label, { path: report, contentType: 'application/json' });
    expect(
        result.violations.map(({ id, impact, nodes }) => ({ id, impact, targets: nodes.map((node) => node.target) })),
        `${label}: no axe violations; incomplete findings remain in the attached report for manual review`,
    ).toEqual([]);
}

export async function expectVisibleFocus(locator) {
    await expect(locator).toBeFocused();
    await expect
        .poll(
            () =>
                locator.evaluate((element) => {
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    const left = Math.max(0, rect.left);
                    const right = Math.min(innerWidth, rect.right);
                    const topEdge = Math.max(0, rect.top);
                    const bottom = Math.min(innerHeight, rect.bottom);
                    const top = document.elementFromPoint((left + right) / 2, (topEdge + bottom) / 2);
                    return (
                        right > left &&
                        bottom > topEdge &&
                        style.outlineStyle !== 'none' &&
                        Number.parseFloat(style.outlineWidth) >= 2 &&
                        style.outlineColor !== 'transparent' &&
                        (top === element || element.contains(top))
                    );
                }),
            { message: 'Keyboard focus has a visible outline and is not covered by another element' },
        )
        .toBe(true);
}

export async function expectPageReflow(page) {
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth),
        'The page reflows without horizontal document scrolling; data tables have their own scroll regions',
    ).toBe(true);
}

export async function tabTo(page, locator) {
    for (let attempt = 0; attempt < 32; attempt++) {
        await page.keyboard.press('Tab');
        if (await locator.evaluate((element) => document.activeElement === element)) {
            await expectVisibleFocus(locator);
            return;
        }
    }
    throw new Error('The control was not reachable through the expected keyboard tab sequence');
}
