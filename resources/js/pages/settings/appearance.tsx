import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { ThemePicker } from '@/components/theme-picker';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Color theme"
                    description="Choose a palette that feels comfortable and personal."
                />
                <ThemePicker />

                <div className="space-y-4 border-t border-border pt-8">
                    <Heading
                        variant="small"
                        title="Mode"
                        description="Use a light or dark version of your chosen palette."
                    />
                    <AppearanceTabs />
                    <p className="max-w-md text-sm text-pretty text-muted-foreground">
                        System automatically follows your device setting.
                    </p>
                </div>
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
