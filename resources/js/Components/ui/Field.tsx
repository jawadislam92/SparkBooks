import { HTMLAttributes, ReactNode } from 'react';

interface FieldProps extends HTMLAttributes<HTMLDivElement> {
    label: string;
    error?: string;
    /** Pass the same id you gave the wrapped input, so the label links to it. */
    htmlFor?: string;
    children: ReactNode;
}

/** Label + control + error wrapper used around every form input. */
export default function Field({
    label,
    error,
    htmlFor,
    className = '',
    children,
    ...props
}: FieldProps) {
    return (
        <div {...props} className={`flex flex-col gap-1.5 ` + className}>
            <label
                htmlFor={htmlFor}
                className="font-sans text-sm font-medium text-ink-700"
            >
                {label}
            </label>
            {children}
            {error && (
                <p className="font-sans text-sm text-clay" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}
