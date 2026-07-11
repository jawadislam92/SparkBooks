import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import MoneyText from '@/Components/ui/MoneyText';

/**
 * Extracts the class attribute from MoneyText's rendered <span>. Uses
 * renderToStaticMarkup (server render, no DOM needed) so this runs in
 * vitest's plain node environment.
 */
function renderClasses(props: Parameters<typeof MoneyText>[0]): string {
    const html = renderToStaticMarkup(createElement(MoneyText, props));
    const match = html.match(/class="([^"]*)"/);

    return match ? match[1] : '';
}

describe('MoneyText color handling', () => {
    it('emits its default text-ink when no color is passed', () => {
        const classes = renderClasses({ minor: 12345, currency: 'PKR' });

        expect(classes).toContain('text-ink');
    });

    it('does NOT emit text-ink when the caller passes a color override (clay wins deterministically)', () => {
        // The P&L Net Profit loss case: className carries `text-clay`. Because
        // MoneyText no longer also emits `text-ink`, `text-clay` is the ONLY
        // text-color utility on the element — so it wins regardless of how
        // Tailwind orders the two utilities in the compiled stylesheet.
        const classes = renderClasses({
            minor: -30000,
            currency: 'PKR',
            className: 'font-semibold text-clay',
        });

        expect(classes).toContain('text-clay');
        expect(classes).not.toContain('text-ink');
    });

    it('does NOT emit text-ink for a positive spark override either', () => {
        const classes = renderClasses({
            minor: 70000,
            currency: 'PKR',
            className: 'font-semibold text-spark',
        });

        expect(classes).toContain('text-spark');
        expect(classes).not.toContain('text-ink');
    });

    it('still applies text-ink alongside a non-color className (size/alignment)', () => {
        const classes = renderClasses({
            minor: 500,
            currency: 'PKR',
            className: 'mt-2',
        });

        expect(classes).toContain('text-ink');
        expect(classes).toContain('mt-2');
    });
});
