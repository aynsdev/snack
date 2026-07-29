import { usePage } from '@inertiajs/react';

import { SnackLogoIcon } from '@/components/snack/snack-logo';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            {/* The mark carries the brand orange itself, so it sits on a plain
                surface rather than inside a filled tile. */}
            <div className="flex aspect-square size-8 items-center justify-center">
                <SnackLogoIcon className="size-7 text-brand" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    {name}
                </span>
            </div>
        </>
    );
}
