import { MapPin, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { TimeEntry } from '@/types/hr';
import { mapsUrl } from './hr-ui';

function confirmThen(message: string, action: () => void) {
    if (window.confirm(message)) {
        action();
    }
}

/** A clock-in or clock-out location, coloured by whether it was at the office. */
export function LocationLink({
    stamp,
    label,
}: {
    stamp: TimeEntry['clock_in_location'];
    label: string;
}) {
    if (!stamp) {
        return null;
    }

    return (
        <a
            href={mapsUrl(stamp.latitude, stamp.longitude)}
            target="_blank"
            rel="noreferrer"
            className={cn(
                'inline-flex items-center gap-1 underline-offset-2 hover:underline',
                stamp.within_geofence === true &&
                    'text-emerald-600 dark:text-emerald-400',
                stamp.within_geofence === false &&
                    'text-amber-600 dark:text-amber-400',
            )}
            title={
                stamp.within_geofence === null
                    ? 'Location recorded'
                    : stamp.within_geofence
                      ? 'Inside the office area'
                      : 'Outside the office area'
            }
        >
            <MapPin aria-hidden="true" className="size-3" />
            {label}
        </a>
    );
}

export function DeleteButton({
    label,
    onConfirm,
}: {
    label: string;
    onConfirm: () => void;
}) {
    return (
        <Button
            size="icon"
            variant="ghost"
            aria-label={label}
            onClick={() =>
                confirmThen(`${label}? This cannot be undone.`, onConfirm)
            }
        >
            <Trash2 aria-hidden="true" />
        </Button>
    );
}

export function FormDialog({
    title,
    description,
    children,
    onClose,
    onSubmit,
    processing,
    submitLabel = 'Save',
}: {
    title: string;
    description?: string;
    children: ReactNode;
    onClose: () => void;
    onSubmit: (event: FormEvent) => void;
    processing: boolean;
    submitLabel?: string;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={onSubmit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && (
                            <DialogDescription>{description}</DialogDescription>
                        )}
                    </DialogHeader>
                    {children}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
