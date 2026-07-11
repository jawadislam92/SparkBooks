/**
 * Detects whether a Tailwind className string already contains a text-COLOR
 * utility (e.g. `text-clay`, `text-spark`, `text-ink-700`) — as opposed to a
 * text-SIZE / text-ALIGNMENT utility (`text-sm`, `text-right`, `text-2xl`),
 * which are NOT colors.
 *
 * Why this exists: a component that hardcodes its own default text color
 * (e.g. MoneyText's `text-ink`) and then appends a caller's className creates
 * a cascade fight when the caller passes an override color. Both utilities
 * have equal specificity, so the winner is whichever Tailwind emits LATER in
 * the compiled stylesheet — an order the component can't control (in the
 * current build `.text-ink` sorts after `.text-clay`, so `text-clay` would
 * silently lose). The fix is to NOT emit the default color at all when the
 * caller supplied one, so the override wins deterministically regardless of
 * stylesheet ordering. This predicate is what lets a component do that.
 *
 * Matches only this design system's token palette (ink/paper/rule/spark/
 * lagoon/amber/clay) plus Tailwind's built-in text-color keywords, with an
 * optional numeric shade suffix (`-700`, `-50`, ...). It deliberately does
 * NOT treat text sizes/alignment/wrap utilities as colors.
 */
const TEXT_COLOR_CLASS =
    /(?:^|\s)text-(?:ink|paper|rule|spark|lagoon|amber|clay|white|black|transparent|current|inherit)(?:-\d+)?(?=\s|$)/;

export function hasTextColorClass(className: string): boolean {
    return TEXT_COLOR_CLASS.test(className);
}
