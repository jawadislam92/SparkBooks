/**
 * Truncates a list of names into a short display string for a table row —
 * Expenses/Index and Bills/Index's "categories" column (Phase 4 Task 9):
 * the distinct category account names touched by a document's lines, joined
 * up to `maxVisible`, with the remainder collapsed into a "+N more" suffix
 * rather than overflowing the row.
 */
export function summarizeList(items: string[], maxVisible = 2): string {
    if (items.length === 0) {
        return '—';
    }
    if (items.length <= maxVisible) {
        return items.join(', ');
    }

    const remaining = items.length - maxVisible;
    return `${items.slice(0, maxVisible).join(', ')} +${remaining} more`;
}
