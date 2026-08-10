import { Form, Link, router } from '@inertiajs/react';
import {
    Bell,
    ChevronRight,
    LogOut,
    Settings2,
    Smile,
    UserRound,
    X,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PresenceDot } from '@/components/snack/presence-dot';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useCurrentUser, useCurrentWorkspace } from '@/hooks/use-workspace';
import { logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotifications } from '@/routes/notification-preferences';
import { edit as editProfile } from '@/routes/profile';
import { update as updateStatus } from '@/routes/status';
import type { User } from '@/types';

type Props = {
    user: User;
    onEditStatus?: () => void;
};

type StatusExpiration = 'one_hour' | 'four_hours' | 'today' | 'never';

const STATUS_SUGGESTIONS: {
    emoji: string;
    message: string;
    expiration: StatusExpiration;
    clears: string;
}[] = [
    {
        emoji: '🗓️',
        message: 'In a meeting',
        expiration: 'one_hour',
        clears: '1 hour',
    },
    {
        emoji: '🎧',
        message: 'Focusing',
        expiration: 'four_hours',
        clears: '4 hours',
    },
    {
        emoji: '🤒',
        message: 'Out sick',
        expiration: 'today',
        clears: 'Today',
    },
    {
        emoji: '🌴',
        message: 'Vacationing',
        expiration: 'never',
        clears: 'Do not clear',
    },
    {
        emoji: '🏡',
        message: 'Working remotely',
        expiration: 'today',
        clears: 'Today',
    },
];

const STATUS_EMOJIS = [
    ['💬', 'Speech bubble'],
    ['🗓️', 'Calendar'],
    ['🎧', 'Headphones'],
    ['🤒', 'Sick'],
    ['🌴', 'Palm tree'],
    ['🏡', 'House'],
    ['🎯', 'Target'],
    ['☕', 'Coffee'],
    ['🍽️', 'Meal'],
    ['✈️', 'Airplane'],
    ['🚫', 'Do not disturb'],
    ['✨', 'Sparkles'],
] as const;

export function UserMenuContent({ user, onEditStatus }: Props) {
    const cleanup = useMobileNavigation();
    const profile = useCurrentUser();
    const currentWorkspace = useCurrentWorkspace();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    const handleClearStatus = () => {
        router.patch(
            updateStatus.url(),
            { clear: true },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-3 px-3 py-3 text-left">
                    {profile ? (
                        <UserAvatar user={profile} size="lg" />
                    ) : (
                        <span className="flex size-10 items-center justify-center rounded-lg bg-brand-subtle text-sm font-semibold">
                            {user.name.slice(0, 1).toUpperCase()}
                        </span>
                    )}

                    <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold">{user.name}</p>
                        {profile && (
                            <PresenceDot
                                userId={profile.id}
                                status={profile.presence_status}
                                withLabel
                                className="mt-0.5"
                            />
                        )}
                    </div>
                </div>
            </DropdownMenuLabel>

            {profile?.status_message && onEditStatus ? (
                <div className="mx-1 my-1 grid grid-cols-[minmax(0,1fr)_2.25rem] overflow-hidden rounded-md border border-input">
                    <DropdownMenuItem
                        onSelect={onEditStatus}
                        className="min-w-0 cursor-pointer rounded-none px-3 py-2"
                    >
                        <span className="shrink-0 text-base" aria-hidden="true">
                            {profile.status_emoji || '💬'}
                        </span>
                        <span className="truncate">
                            {profile.status_message}
                        </span>
                    </DropdownMenuItem>

                    <DropdownMenuItem
                        onSelect={handleClearStatus}
                        aria-label="Clear status"
                        className="cursor-pointer justify-center rounded-none border-l border-input px-0 py-2"
                    >
                        <span className="flex size-6 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <X aria-hidden="true" className="size-4" />
                        </span>
                    </DropdownMenuItem>
                </div>
            ) : profile && onEditStatus ? (
                <DropdownMenuItem
                    onSelect={onEditStatus}
                    className="cursor-pointer"
                >
                    <Smile aria-hidden="true" />
                    <span className="truncate">Set a status</span>
                </DropdownMenuItem>
            ) : null}

            {currentWorkspace && (
                <DropdownMenuItem asChild>
                    <Link
                        className="w-full cursor-pointer"
                        href={editNotifications(
                            currentWorkspace.workspace.slug,
                        )}
                        onClick={cleanup}
                    >
                        <Bell aria-hidden="true" />
                        Notifications
                        <ChevronRight
                            aria-hidden="true"
                            className="ml-auto text-muted-foreground"
                        />
                    </Link>
                </DropdownMenuItem>
            )}

            <DropdownMenuSeparator />

            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="w-full cursor-pointer"
                        href={editProfile()}
                        prefetch
                        onClick={cleanup}
                    >
                        <UserRound aria-hidden="true" />
                        Profile
                    </Link>
                </DropdownMenuItem>

                <DropdownMenuItem asChild>
                    <Link
                        className="w-full cursor-pointer"
                        href={editAppearance()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings2 aria-hidden="true" />
                        Preferences
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>

            <DropdownMenuSeparator />

            <DropdownMenuItem asChild>
                <Link
                    className="w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut aria-hidden="true" />
                    Log out of Snack
                </Link>
            </DropdownMenuItem>
        </>
    );
}

export function StatusDialog({
    open,
    onOpenChange,
    emoji,
    message,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    emoji: string;
    message: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Set your status</DialogTitle>
                    <DialogDescription>
                        Let teammates know what you are focused on or when you
                        will be back.
                    </DialogDescription>
                </DialogHeader>

                {open && (
                    <StatusForm
                        key={`${emoji}:${message}`}
                        emoji={emoji}
                        message={message}
                        onSuccess={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function StatusForm({
    emoji,
    message,
    onSuccess,
}: {
    emoji: string;
    message: string;
    onSuccess: () => void;
}) {
    const [selectedEmoji, setSelectedEmoji] = useState(emoji || '💬');
    const [statusMessage, setStatusMessage] = useState(message);
    const [expiration, setExpiration] = useState<StatusExpiration>('today');

    const selectSuggestion = (
        suggestion: (typeof STATUS_SUGGESTIONS)[number],
    ) => {
        setSelectedEmoji(suggestion.emoji);
        setStatusMessage(suggestion.message);
        setExpiration(suggestion.expiration);
    };

    return (
        <Form
            {...updateStatus.form()}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="space-y-5"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="status_message">Status</Label>
                        <div className="grid grid-cols-[2.75rem_minmax(0,1fr)]">
                            <StatusEmojiPicker
                                emoji={selectedEmoji}
                                onPick={setSelectedEmoji}
                            />
                            <Input
                                id="status_message"
                                name="message"
                                value={statusMessage}
                                onChange={(event) =>
                                    setStatusMessage(event.target.value)
                                }
                                maxLength={80}
                                placeholder="What is your status?"
                                className="rounded-l-none"
                                autoFocus
                            />
                        </div>
                        <input
                            type="hidden"
                            name="emoji"
                            value={selectedEmoji}
                        />
                        <InputError message={errors.emoji ?? errors.message} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Suggestions</Label>
                        <div className="divide-y divide-border overflow-hidden rounded-md border border-border">
                            {STATUS_SUGGESTIONS.map((suggestion) => (
                                <button
                                    key={suggestion.message}
                                    type="button"
                                    onClick={() => selectSuggestion(suggestion)}
                                    className="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="text-base"
                                    >
                                        {suggestion.emoji}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate font-medium">
                                        {suggestion.message}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {suggestion.clears}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="status_expires_in">Clear status</Label>
                        <select
                            id="status_expires_in"
                            name="expires_in"
                            value={expiration}
                            onChange={(event) =>
                                setExpiration(
                                    event.target.value as StatusExpiration,
                                )
                            }
                            className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        >
                            <option value="one_hour">In one hour</option>
                            <option value="four_hours">In four hours</option>
                            <option value="today">End of today</option>
                            <option value="never">Do not clear</option>
                        </select>
                        <InputError message={errors.expires_in} />
                    </div>

                    <DialogFooter>
                        {message && (
                            <Button
                                type="submit"
                                name="clear"
                                value="1"
                                variant="ghost"
                                disabled={processing}
                            >
                                Clear status
                            </Button>
                        )}
                        <Button type="submit" disabled={processing}>
                            Save status
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function StatusEmojiPicker({
    emoji,
    onPick,
}: {
    emoji: string;
    onPick: (emoji: string) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={`Choose status emoji. Current emoji: ${emoji}`}
                    className="flex h-9 items-center justify-center rounded-l-md border border-r-0 border-input bg-background text-base outline-none hover:bg-accent focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                >
                    <span aria-hidden="true">{emoji}</span>
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="start"
                className="grid w-56 grid-cols-6 gap-1 p-2"
                onCloseAutoFocus={(event) => event.preventDefault()}
            >
                {STATUS_EMOJIS.map(([glyph, label]) => (
                    <button
                        key={label}
                        type="button"
                        title={label}
                        aria-label={label}
                        onClick={() => {
                            onPick(glyph);
                            setOpen(false);
                        }}
                        className="flex size-8 items-center justify-center rounded-md text-base hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                    >
                        <span aria-hidden="true">{glyph}</span>
                    </button>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
