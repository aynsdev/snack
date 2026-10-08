import { router, useForm } from '@inertiajs/react';
import { Crosshair, LoaderCircle, Pencil, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { ChecklistTemplatesSection } from '@/components/hr/checklist-templates-section';
import { DeleteButton, FormDialog } from '@/components/hr/hr-dialogs';
import {
    Field,
    HrPage,
    LEAVE_COLORS,
    mapsUrl,
    Section,
    SelectField,
} from '@/components/hr/hr-ui';
import { ShiftsSection } from '@/components/hr/shifts-section';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    destroy as destroyLeaveType,
    store as storeLeaveType,
    update as updateLeaveType,
} from '@/routes/hr/leave-types';
import { update as updateSettings } from '@/routes/hr/settings';
import type { ChecklistTemplate, Geofence, LeaveType, Shift } from '@/types/hr';

type Props = {
    geofence: Geofence | null;
    require_location: boolean;
    leave_types: LeaveType[];
    shifts: Shift[];
    checklist_templates: ChecklistTemplate[];
    auto_onboarding: boolean;
};

const COLORS = Object.keys(LEAVE_COLORS).map((color) => ({
    value: color,
    label: color[0].toUpperCase() + color.slice(1),
}));

/**
 * Where people may clock in from, the shifts they work, and which kinds of
 * leave exist.
 */
export default function HrSettingsPage({
    geofence,
    require_location,
    leave_types,
    shifts,
    checklist_templates,
    auto_onboarding,
}: Props) {
    const [editing, setEditing] = useState<LeaveType | 'new' | null>(null);
    const workspace = useWorkspace();

    return (
        <HrPage title="HR settings" width="max-w-3xl">
            <GeofenceForm
                geofence={geofence}
                requireLocation={require_location}
            />

            <ShiftsSection shifts={shifts} />

            <ChecklistTemplatesSection
                templates={checklist_templates}
                autoOnboarding={auto_onboarding}
            />

            <Section
                title="Leave types"
                action={
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setEditing('new')}
                    >
                        <Plus aria-hidden="true" />
                        Add type
                    </Button>
                }
            >
                <ul className="divide-y divide-border">
                    {leave_types.map((type) => (
                        <li
                            key={type.id}
                            className={cn(
                                'flex items-center gap-3 py-2.5 first:pt-0 last:pb-0',
                                type.is_archived && 'opacity-60',
                            )}
                        >
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2.5 shrink-0 rounded-full',
                                    LEAVE_COLORS[type.color] ?? 'bg-slate-500',
                                )}
                            />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium">
                                    {type.name}
                                    {type.is_archived && (
                                        <span className="ml-2 text-xs font-normal text-muted-foreground">
                                            Archived
                                        </span>
                                    )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {type.days_per_year === null
                                        ? 'No yearly limit'
                                        : `${type.days_per_year} days a year`}{' '}
                                    · {type.is_paid ? 'Paid' : 'Unpaid'}
                                </p>
                            </div>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label={`Edit ${type.name}`}
                                onClick={() => setEditing(type)}
                            >
                                <Pencil aria-hidden="true" />
                            </Button>
                            {!type.is_archived && (
                                <DeleteButton
                                    label={`Remove ${type.name}`}
                                    onConfirm={() =>
                                        router.delete(
                                            destroyLeaveType({
                                                workspace: workspace.slug,
                                                leaveType: type.id,
                                            }).url,
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            )}
                        </li>
                    ))}
                </ul>
                <p className="mt-3 text-xs text-muted-foreground">
                    A type somebody has already used is archived rather than
                    deleted, so their history keeps its name.
                </p>
            </Section>

            {editing !== null && (
                <LeaveTypeDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    leaveType={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </HrPage>
    );
}

function GeofenceForm({
    geofence,
    requireLocation,
}: {
    geofence: Geofence | null;
    requireLocation: boolean;
}) {
    const workspace = useWorkspace();
    const [locating, setLocating] = useState(false);
    const [locateError, setLocateError] = useState<string | null>(null);
    const form = useForm({
        require_location: requireLocation,
        has_geofence: geofence !== null,
        latitude: geofence ? String(geofence.latitude) : '',
        longitude: geofence ? String(geofence.longitude) : '',
        radius_meters: geofence ? String(geofence.radius_meters) : '200',
        enforced: geofence?.enforced ?? false,
    });

    function useCurrentLocation() {
        if (!('geolocation' in navigator)) {
            setLocateError('This browser cannot share a location.');

            return;
        }

        setLocating(true);
        setLocateError(null);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                form.setData((data) => ({
                    ...data,
                    has_geofence: true,
                    latitude: position.coords.latitude.toFixed(6),
                    longitude: position.coords.longitude.toFixed(6),
                }));
                setLocating(false);
            },
            () => {
                setLocateError('Your location could not be determined.');
                setLocating(false);
            },
            { enableHighAccuracy: true, timeout: 15000 },
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            require_location: data.require_location,
            geofence: data.has_geofence
                ? {
                      latitude: Number(data.latitude),
                      longitude: Number(data.longitude),
                      radius_meters: Number(data.radius_meters),
                      enforced: data.enforced,
                  }
                : null,
        }));
        form.patch(updateSettings(workspace.slug).url, {
            preserveScroll: true,
        });
    }

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit}>
            <Section title="Time clock location">
                <div className="space-y-4">
                    <label className="flex items-start gap-2 text-sm">
                        <Checkbox
                            className="mt-0.5"
                            checked={form.data.require_location}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'require_location',
                                    checked === true,
                                )
                            }
                        />
                        <span>
                            Require a location to clock in or out
                            <span className="block text-xs text-muted-foreground">
                                Without this, people who decline location access
                                can still clock in; their shift is recorded
                                without a geotag.
                            </span>
                        </span>
                    </label>

                    <label className="flex items-start gap-2 text-sm">
                        <Checkbox
                            className="mt-0.5"
                            checked={form.data.has_geofence}
                            onCheckedChange={(checked) =>
                                form.setData('has_geofence', checked === true)
                            }
                        />
                        <span>
                            Set an office location
                            <span className="block text-xs text-muted-foreground">
                                Each shift is tagged as inside or outside this
                                area.
                            </span>
                        </span>
                    </label>

                    {form.data.has_geofence && (
                        <div className="space-y-4 rounded-lg border border-border p-4">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field
                                    label="Latitude"
                                    htmlFor="geo-lat"
                                    error={errors['geofence.latitude']}
                                >
                                    <Input
                                        id="geo-lat"
                                        inputMode="decimal"
                                        value={form.data.latitude}
                                        onChange={(event) =>
                                            form.setData(
                                                'latitude',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Longitude"
                                    htmlFor="geo-lng"
                                    error={errors['geofence.longitude']}
                                >
                                    <Input
                                        id="geo-lng"
                                        inputMode="decimal"
                                        value={form.data.longitude}
                                        onChange={(event) =>
                                            form.setData(
                                                'longitude',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Radius (metres)"
                                    htmlFor="geo-radius"
                                    error={errors['geofence.radius_meters']}
                                >
                                    <Input
                                        id="geo-radius"
                                        type="number"
                                        min={10}
                                        max={50000}
                                        value={form.data.radius_meters}
                                        onChange={(event) =>
                                            form.setData(
                                                'radius_meters',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Field>
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={useCurrentLocation}
                                    disabled={locating}
                                >
                                    {locating ? (
                                        <LoaderCircle
                                            aria-hidden="true"
                                            className="animate-spin"
                                        />
                                    ) : (
                                        <Crosshair aria-hidden="true" />
                                    )}
                                    Use my current location
                                </Button>
                                {form.data.latitude && form.data.longitude && (
                                    <a
                                        href={mapsUrl(
                                            Number(form.data.latitude),
                                            Number(form.data.longitude),
                                        )}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-sm underline underline-offset-2"
                                    >
                                        View on map
                                    </a>
                                )}
                            </div>
                            <InputError message={locateError ?? undefined} />
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox
                                    className="mt-0.5"
                                    checked={form.data.enforced}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'enforced',
                                            checked === true,
                                        )
                                    }
                                />
                                <span>
                                    Only allow clocking from inside this area
                                    <span className="block text-xs text-muted-foreground">
                                        Clock-ins and outs from anywhere else
                                        are refused.
                                    </span>
                                </span>
                            </label>
                        </div>
                    )}

                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </div>
                </div>
            </Section>
        </form>
    );
}

function LeaveTypeDialog({
    leaveType,
    onClose,
}: {
    leaveType: LeaveType | null;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        name: leaveType?.name ?? '',
        color: leaveType?.color ?? 'sky',
        days_per_year:
            leaveType?.days_per_year === null || leaveType === null
                ? leaveType
                    ? ''
                    : '10'
                : String(leaveType.days_per_year),
        is_paid: leaveType?.is_paid ?? true,
        is_archived: leaveType?.is_archived ?? false,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            days_per_year:
                data.days_per_year === '' ? null : Number(data.days_per_year),
        }));

        if (leaveType) {
            form.patch(
                updateLeaveType({
                    workspace: workspace.slug,
                    leaveType: leaveType.id,
                }).url,
                { preserveScroll: true, onSuccess: onClose },
            );
        } else {
            form.post(storeLeaveType(workspace.slug).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    }

    return (
        <FormDialog
            title={leaveType ? 'Edit leave type' : 'Add a leave type'}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Name"
                    htmlFor="type-name"
                    error={form.errors.name}
                >
                    <Input
                        id="type-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                    />
                </Field>
                <SelectField
                    id="type-color"
                    label="Colour"
                    value={form.data.color}
                    onChange={(value) => form.setData('color', value)}
                    options={COLORS}
                    error={form.errors.color}
                />
                <Field
                    label="Days per year"
                    htmlFor="type-days"
                    error={form.errors.days_per_year}
                    className="sm:col-span-2"
                >
                    <Input
                        id="type-days"
                        type="number"
                        min={0}
                        max={366}
                        step="0.5"
                        placeholder="Leave empty for no limit"
                        value={form.data.days_per_year}
                        onChange={(event) =>
                            form.setData('days_per_year', event.target.value)
                        }
                    />
                </Field>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_paid}
                    onCheckedChange={(checked) =>
                        form.setData('is_paid', checked === true)
                    }
                />
                Paid leave
            </label>
            {leaveType && (
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={form.data.is_archived}
                        onCheckedChange={(checked) =>
                            form.setData('is_archived', checked === true)
                        }
                    />
                    Archived (no longer offered)
                </label>
            )}
        </FormDialog>
    );
}
