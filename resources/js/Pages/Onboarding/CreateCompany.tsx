import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import Button from '@/Components/ui/Button';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

export default function CreateCompany({ currencies }: { currencies: string[] }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        base_currency: '',
        fiscal_year_start_month: 1,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('onboarding.company'));
    };

    return (
        <GuestLayout>
            <Head title="Create your company" />

            <h1 className="font-display text-lg font-semibold text-ink">
                Set up your company
            </h1>
            <p className="mt-1 text-sm text-ink-700">
                Tell us a little about your business to get started.
            </p>

            <form onSubmit={submit} className="mt-6">
                <div>
                    <InputLabel htmlFor="name" value="Company name" />

                    <TextInput
                        id="name"
                        name="name"
                        value={data.name}
                        className="mt-1 block w-full focus:border-spark focus:ring-spark"
                        isFocused={true}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                    />

                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="base_currency" value="Base currency" />

                    <select
                        id="base_currency"
                        name="base_currency"
                        value={data.base_currency}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-spark focus:ring-spark"
                        onChange={(e) => setData('base_currency', e.target.value)}
                        required
                    >
                        <option value="" disabled>
                            Select a currency
                        </option>
                        {currencies.map((currency) => (
                            <option key={currency} value={currency}>
                                {currency}
                            </option>
                        ))}
                    </select>

                    <InputError message={errors.base_currency} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel
                        htmlFor="fiscal_year_start_month"
                        value="Fiscal year start month"
                    />

                    <select
                        id="fiscal_year_start_month"
                        name="fiscal_year_start_month"
                        value={data.fiscal_year_start_month}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-spark focus:ring-spark"
                        onChange={(e) =>
                            setData(
                                'fiscal_year_start_month',
                                Number(e.target.value),
                            )
                        }
                        required
                    >
                        {MONTHS.map((month, index) => (
                            <option key={month} value={index + 1}>
                                {month}
                            </option>
                        ))}
                    </select>

                    <InputError
                        message={errors.fiscal_year_start_month}
                        className="mt-2"
                    />
                </div>

                <div className="mt-6 flex items-center justify-end">
                    <Button disabled={processing}>Create company</Button>
                </div>
            </form>
        </GuestLayout>
    );
}
