/**
 * Shared contact-type presentation + semantics, used by both Contacts/Index
 * and Contacts/Show so the labels, chip colors, and "both counts as either"
 * rules live in exactly one place. Mirrors App\Enums\ContactType on the
 * backend (isCustomer/isVendor: Both is both).
 */

export type ContactType = 'customer' | 'vendor' | 'both';

export const TYPE_LABELS: Record<string, string> = {
    customer: 'Customer',
    vendor: 'Vendor',
    both: 'Customer & vendor',
};

export const TYPE_CHIP_CLASSES: Record<string, string> = {
    customer: 'bg-spark-50 text-ink',
    vendor: 'bg-lagoon/10 text-lagoon',
    both: 'bg-amber-50 text-ink',
};

/** True for customer + both — matches ContactType::isCustomer() on the server. */
export function isCustomerType(type: string): boolean {
    return type === 'customer' || type === 'both';
}

/** True for vendor + both — matches ContactType::isVendor() on the server. */
export function isVendorType(type: string): boolean {
    return type === 'vendor' || type === 'both';
}
