import { ButtonHTMLAttributes } from 'react';

type ButtonVariant = 'primary' | 'quiet' | 'danger';
type ButtonSize = 'md' | 'sm';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    size?: ButtonSize;
}

const VARIANT_CLASSES: Record<ButtonVariant, string> = {
    // Primary buttons are one of the four reserved uses of the brand gradient.
    primary:
        'bg-gradient-to-r from-spark to-lagoon text-white hover:brightness-105 active:brightness-95',
    quiet: 'bg-transparent text-ink-700 hover:bg-paper',
    danger: 'bg-clay text-white hover:brightness-105 active:brightness-95',
};

const SIZE_CLASSES: Record<ButtonSize, string> = {
    md: 'h-10 px-4 text-sm',
    sm: 'h-8 px-3 text-xs',
};

export default function Button({
    variant = 'primary',
    size = 'md',
    className = '',
    disabled,
    children,
    ...props
}: ButtonProps) {
    return (
        <button
            {...props}
            disabled={disabled}
            className={
                `inline-flex items-center justify-center gap-2 rounded-lg font-sans font-semibold transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised focus-visible:shadow-[0_0_10px_2px_rgba(12,143,166,0.35)] disabled:cursor-not-allowed disabled:opacity-40 ${VARIANT_CLASSES[variant]} ${SIZE_CLASSES[size]} ` +
                className
            }
        >
            {children}
        </button>
    );
}
