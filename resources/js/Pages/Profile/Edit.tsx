import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({
    mustVerifyEmail,
    status,
}: PageProps<{ mustVerifyEmail: boolean; status?: string }>) {
    return (
        <AppShell title="Profile">
            <Head title="Profile" />

            <div className="mx-auto flex max-w-3xl flex-col gap-6">
                <Sheet>
                    <UpdateProfileInformationForm
                        mustVerifyEmail={mustVerifyEmail}
                        status={status}
                        className="max-w-xl"
                    />
                </Sheet>

                <Sheet>
                    <UpdatePasswordForm className="max-w-xl" />
                </Sheet>

                <Sheet>
                    <DeleteUserForm className="max-w-xl" />
                </Sheet>
            </div>
        </AppShell>
    );
}
