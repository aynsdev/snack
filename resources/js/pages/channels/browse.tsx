import { Form, Head, Link } from '@inertiajs/react';
import { Compass, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { ChannelIcon } from '@/components/snack/channel-icon';
import { EmptyState } from '@/components/snack/empty-state';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { store as storeChannel } from '@/routes/channels';
import { show as showChannel } from '@/routes/channels';
import { store as joinChannel } from '@/routes/channels/membership';
import type { Channel } from '@/types';

type Props = {
    joinable: Channel[];
    joined: Channel[];
};

/**
 * The channel directory: everything the user can join, plus what they are
 * already in.
 *
 * Filtering is client-side because a workspace's channel list is small and
 * already in memory — a round trip per keystroke would feel slower, not faster.
 * Message search, which cannot work this way, is server-side in Phase 5.
 */
export default function BrowseChannels({ joinable, joined }: Props) {
    const workspace = useWorkspace();
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        const matches = (channel: Channel) =>
            needle === '' ||
            channel.name.toLowerCase().includes(needle) ||
            (channel.description ?? '').toLowerCase().includes(needle);

        return {
            joinable: joinable.filter(matches),
            joined: joined.filter(matches),
        };
    }, [query, joinable, joined]);

    const hasResults =
        filtered.joinable.length > 0 || filtered.joined.length > 0;

    return (
        <>
            <Head title="Browse channels" />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl px-6 py-8">
                    <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">
                                Channels
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {joinable.length + joined.length} channels in{' '}
                                {workspace.name}
                            </p>
                        </div>

                        {workspace.abilities.create_channels && (
                            <CreateChannelDialog />
                        )}
                    </header>

                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search channels by name or description"
                        aria-label="Search channels"
                        className="mb-6"
                    />

                    {!hasResults ? (
                        <EmptyState
                            icon={Compass}
                            title="No channels match that"
                            description="Try a different search, or create the channel you were looking for."
                        />
                    ) : (
                        <div className="space-y-8">
                            {filtered.joinable.length > 0 && (
                                <ChannelGroup
                                    title="Channels you can join"
                                    channels={filtered.joinable}
                                    workspaceSlug={workspace.slug}
                                />
                            )}

                            {filtered.joined.length > 0 && (
                                <ChannelGroup
                                    title="Channels you are in"
                                    channels={filtered.joined}
                                    workspaceSlug={workspace.slug}
                                />
                            )}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

function ChannelGroup({
    title,
    channels,
    workspaceSlug,
}: {
    title: string;
    channels: Channel[];
    workspaceSlug: string;
}) {
    return (
        <section>
            <h2 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h2>

            <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                {channels.map((channel) => (
                    <li
                        key={channel.id}
                        className="flex items-center gap-4 px-4 py-3 transition-colors hover:bg-accent/50"
                    >
                        <div className="min-w-0 flex-1">
                            <Link
                                href={showChannel({
                                    workspace: workspaceSlug,
                                    channel: channel.slug,
                                })}
                                className="flex items-center gap-1.5 font-medium hover:underline"
                            >
                                <ChannelIcon
                                    type={channel.type}
                                    className="text-muted-foreground"
                                />
                                {channel.name}
                            </Link>

                            <p className="mt-0.5 line-clamp-1 text-sm text-muted-foreground">
                                {typeof channel.member_count === 'number' && (
                                    <span className="tabular-nums">
                                        {channel.member_count} members
                                    </span>
                                )}
                                {typeof channel.member_count === 'number' &&
                                    channel.description &&
                                    ' · '}
                                {channel.description}
                            </p>
                        </div>

                        {channel.is_member ? (
                            <span className="shrink-0 text-sm text-muted-foreground">
                                Joined
                            </span>
                        ) : (
                            <Button asChild size="sm" variant="outline">
                                <Link
                                    href={joinChannel({
                                        workspace: workspaceSlug,
                                        channel: channel.slug,
                                    })}
                                    method="post"
                                    as="button"
                                >
                                    Join
                                </Link>
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function CreateChannelDialog() {
    const workspace = useWorkspace();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus aria-hidden="true" />
                    Create channel
                </Button>
            </DialogTrigger>

            <DialogContent>
                <Form
                    action={storeChannel(workspace.slug)}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    disableWhileProcessing
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Create a channel</DialogTitle>
                                <DialogDescription>
                                    Channels are where your team organizes
                                    conversations by topic.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4 py-4">
                                <div className="space-y-2">
                                    <Label htmlFor="channel-name">Name</Label>
                                    <Input
                                        id="channel-name"
                                        name="name"
                                        required
                                        autoFocus
                                        placeholder="e.g. product-launch"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="channel-description">
                                        Description{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Textarea
                                        id="channel-description"
                                        name="description"
                                        rows={2}
                                        placeholder="What is this channel for?"
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="channel-type">
                                        Visibility
                                    </Label>
                                    <Select name="type" defaultValue="public">
                                        <SelectTrigger id="channel-type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="public">
                                                Public — anyone can find and
                                                join
                                            </SelectItem>
                                            <SelectItem value="private">
                                                Private — invite only
                                            </SelectItem>
                                            {workspace.abilities.administer && (
                                                <SelectItem value="announcement">
                                                    Announcement — everyone
                                                    reads, admins post
                                                </SelectItem>
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.type} />
                                </div>
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Create channel
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
