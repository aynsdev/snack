import { Form, Head, router } from '@inertiajs/react';
import { BellOff, MessageSquare } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ChannelIcon } from '@/components/snack/channel-icon';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useWorkspace } from '@/hooks/use-workspace';
import { update as updateChannelPreferences } from '@/routes/channels/preferences';
import { update as updateConversationPreferences } from '@/routes/conversations/preferences';
import { update as updatePreferences } from '@/routes/notification-preferences';
import type {
    Channel,
    Conversation,
    NotificationLevel,
    NotificationPreferences,
} from '@/types';

type Props = {
    preferences: NotificationPreferences;
    levels: { value: NotificationLevel; label: string }[];
    /** Only the channels this member has actually overridden. */
    channels: Channel[];
    /** Only the conversations they have silenced or turned down. */
    conversations: Conversation[];
};

/**
 * How loud this workspace is, for one person.
 *
 * Workspace-scoped rather than account-wide, because that is what the setting
 * is: somebody can want everything from a five-person client workspace and
 * mentions only from a six-hundred-person one, and one global switch cannot say
 * that.
 *
 * The overrides below the form are the reason the page is worth having at all.
 * Muting is reachable from each channel's own menu, but "which of my forty
 * channels did I silence?" is a question only a list can answer — without it, a
 * mute made months ago is effectively unfindable, and the channel just looks
 * dead.
 */
export default function NotificationPreferencesPage({
    preferences,
    levels,
    channels,
    conversations,
}: Props) {
    const workspace = useWorkspace();

    const [quietHours, setQuietHours] = useState(
        preferences.do_not_disturb !== null,
    );

    return (
        <>
            <Head title={`Notifications · ${workspace.name}`} />

            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Notifications"
                    description={`How ${workspace.name} reaches you. These settings are yours alone and apply only to this workspace.`}
                />

                <Form
                    {...updatePreferences.form(workspace.slug)}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="default_level">
                                    Notify me about
                                </Label>

                                <Select
                                    name="default_level"
                                    defaultValue={preferences.default_level}
                                >
                                    <SelectTrigger id="default_level">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {levels.map((level) => (
                                            <SelectItem
                                                key={level.value}
                                                value={level.value}
                                            >
                                                {level.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <p className="text-xs text-muted-foreground">
                                    The starting point for channels you join
                                    from now on. Channels you have already set
                                    individually keep their own setting.
                                </p>

                                <InputError message={errors.default_level} />
                            </div>

                            <fieldset className="space-y-3">
                                <legend className="text-sm font-medium">
                                    Email
                                </legend>

                                <CheckboxField
                                    name="email_enabled"
                                    label="Email me when I am away"
                                    description="Only for things aimed at you — a mention, a direct message, or a reply in a thread you follow — and only when you are not already reading."
                                    defaultChecked={preferences.email_enabled}
                                />

                                <CheckboxField
                                    name="digest_enabled"
                                    label="Send a daily summary"
                                    description="One email a morning listing what is still unread. Off by default."
                                    defaultChecked={preferences.digest_enabled}
                                />
                            </fieldset>

                            <fieldset className="space-y-3">
                                <legend className="text-sm font-medium">
                                    Quiet hours
                                </legend>

                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="quiet_hours"
                                        checked={quietHours}
                                        onCheckedChange={(checked) =>
                                            setQuietHours(checked === true)
                                        }
                                    />
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor="quiet_hours"
                                            className="font-normal"
                                        >
                                            Hold emails overnight
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            In your own timezone. Nothing is
                                            withheld from the notification
                                            centre — this only stops the email.
                                        </p>
                                    </div>
                                </div>

                                {quietHours && (
                                    <div className="flex flex-wrap items-end gap-3 pl-7">
                                        <div className="grid gap-2">
                                            <Label htmlFor="dnd_start">
                                                From
                                            </Label>
                                            <Input
                                                id="dnd_start"
                                                name="do_not_disturb[start]"
                                                type="time"
                                                className="w-32"
                                                defaultValue={
                                                    preferences.do_not_disturb
                                                        ?.start ?? '22:00'
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="dnd_end">
                                                Until
                                            </Label>
                                            <Input
                                                id="dnd_end"
                                                name="do_not_disturb[end]"
                                                type="time"
                                                className="w-32"
                                                defaultValue={
                                                    preferences.do_not_disturb
                                                        ?.end ?? '07:00'
                                                }
                                            />
                                        </div>
                                    </div>
                                )}

                                <InputError
                                    message={
                                        errors['do_not_disturb.start'] ??
                                        errors['do_not_disturb.end']
                                    }
                                />
                            </fieldset>

                            <Button type="submit" disabled={processing}>
                                Save preferences
                            </Button>
                        </>
                    )}
                </Form>

                <section className="space-y-4 border-t border-border pt-8">
                    <Heading
                        variant="small"
                        title="Channels you have changed"
                        description="Everything else follows the setting above."
                    />

                    {channels.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No channel overrides.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border border-border">
                            {channels.map((channel) => (
                                <OverrideRow
                                    key={channel.id}
                                    title={`#${channel.slug}`}
                                    icon={
                                        <ChannelIcon
                                            type={channel.type}
                                            className="size-4 shrink-0 text-muted-foreground"
                                        />
                                    }
                                    isMuted={channel.is_muted}
                                    level={channel.notification_level}
                                    levels={levels}
                                    onChange={(payload) =>
                                        router.patch(
                                            updateChannelPreferences.url({
                                                workspace: workspace.slug,
                                                channel: channel.slug,
                                            }),
                                            payload,
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-4 border-t border-border pt-8">
                    <Heading
                        variant="small"
                        title="Conversations you have changed"
                        description="Direct messages start at everything, so anything listed here you turned down yourself."
                    />

                    {conversations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No conversation overrides.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border border-border">
                            {conversations.map((conversation) => (
                                <OverrideRow
                                    key={conversation.id}
                                    title={conversation.display_name}
                                    icon={
                                        <MessageSquare
                                            aria-hidden="true"
                                            className="size-4 shrink-0 text-muted-foreground"
                                        />
                                    }
                                    isMuted={conversation.is_muted}
                                    level={conversation.notification_level}
                                    levels={levels}
                                    onChange={(payload) =>
                                        router.patch(
                                            updateConversationPreferences.url({
                                                workspace: workspace.slug,
                                                conversation: conversation.id,
                                            }),
                                            payload,
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

function CheckboxField({
    name,
    label,
    description,
    defaultChecked,
}: {
    name: string;
    label: string;
    description: string;
    defaultChecked: boolean;
}) {
    const [checked, setChecked] = useState(defaultChecked);

    return (
        <div className="flex items-start gap-3">
            {/*
             * An unchecked box submits nothing, and the request validates these
             * as required booleans — so a hidden "0" rides along and the checked
             * input overwrites it. Without this, clearing a preference would be
             * a validation error rather than a save.
             */}
            <input type="hidden" name={name} value={checked ? '1' : '0'} />

            <Checkbox
                id={name}
                checked={checked}
                onCheckedChange={(next) => setChecked(next === true)}
            />

            <div className="grid gap-1">
                <Label htmlFor={name} className="font-normal">
                    {label}
                </Label>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}

function OverrideRow({
    title,
    icon,
    isMuted,
    level,
    levels,
    onChange,
}: {
    title: string;
    icon: React.ReactNode;
    isMuted: boolean;
    level: NotificationLevel | null;
    levels: { value: NotificationLevel; label: string }[];
    onChange: (payload: Record<string, string | boolean>) => void;
}) {
    return (
        <li className="flex flex-wrap items-center gap-3 px-4 py-3">
            {icon}

            <span className="min-w-0 flex-1 truncate text-sm font-medium">
                {title}
            </span>

            {isMuted && (
                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                    <BellOff aria-hidden="true" className="size-3.5" />
                    Muted
                </span>
            )}

            <Select
                value={level ?? 'all'}
                onValueChange={(next) => onChange({ notification_level: next })}
            >
                <SelectTrigger
                    className="w-40"
                    aria-label={`Notifications for ${title}`}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {levels.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => onChange({ is_muted: !isMuted })}
            >
                {isMuted ? 'Unmute' : 'Mute'}
            </Button>
        </li>
    );
}
