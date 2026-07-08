type WordmarkTone = 'white' | 'ink';

const TONE_CLASSES: Record<WordmarkTone, string> = {
    white: 'text-white',
    ink: 'text-ink',
};

/**
 * "SparkBooks" in the display face, with the signature 10px spark→lagoon
 * gradient beam under the "k". Used white-on-ink in the sidebar and
 * ink-on-paper above the centered sheet on auth/onboarding screens.
 */
export default function Wordmark({ tone = 'white' }: { tone?: WordmarkTone }) {
    return (
        <span
            className={`inline-flex items-baseline font-display text-xl font-bold ${TONE_CLASSES[tone]}`}
        >
            SparkBoo
            <span className="relative inline-block">
                k
                <span
                    aria-hidden="true"
                    className="absolute -bottom-0.5 left-0 h-[3px] w-[10px] rounded-full bg-gradient-to-r from-spark to-lagoon"
                />
            </span>
            s
        </span>
    );
}
