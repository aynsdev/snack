import { Head } from '@inertiajs/react';
import { MessageSquare, SquarePen } from 'lucide-react';
import { useState } from 'react';
import { ConversationListItem } from '@/components/snack/conversation-list-item';
import { EmptyState } from '@/components/snack/empty-state';
import { NewMessageDialog } from '@/components/snack/new-message-dialog';
import { PersonListItem } from '@/components/snack/person-list-item';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useActiveConversationId } from '@/hooks/use-active-channel';
import {
    useCurrentUser,
    useSidebarConversations,
    useSidebarPeople,
    useUnreadState,
    useWorkspace,
} from '@/hooks/use-workspace';

/**
 * The direct message home: every group you are in, and everyone you could
 * message.
 *
 * Takes no props. The rows it renders are already on the shared `snack` prop
 * that dresses every workspace page — it is the same roster the sidebar shows —
 * so asking the server for them again would be a second answer to a question
 * already answered on this request.
 *
 * What this adds over the sidebar is room: a filter box for a workspace too big
 * to scan, and a list that is not competing with the channels above it.
 */
export default function ConversationsIndex() {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();
    const { groups } = useSidebarConversations();
    const people = useSidebarPeople();
    const unread = useUnreadState();
    const activeConversationId = useActiveConversationId();

    const [filter, setFilter] = useState('');
    const [composing, setComposing] = useState(false);

    const term = filter.trim().toLowerCase();
    const matches = (text: string) =>
        term === '' || text.toLowerCase().includes(term);

    const visibleGroups = groups.filter((group) => matches(group.display_name));
    const visiblePeople = people.filter(
        (person) =>
            matches(person.user.name) || matches(person.user.username ?? ''),
    );

    return (
        <>
            <Head title="Direct messages" />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center gap-2 border-b border-border px-4 py-3">
                    <MessageSquare
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    <h1 className="text-base font-bold tracking-tight">
                        Direct messages
                    </h1>

                    {workspace.abilities.start_conversations && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setComposing(true)}
                            className="ml-auto gap-1.5 text-muted-foreground"
                        >
                            <SquarePen aria-hidden="true" />
                            New message
                        </Button>
                    )}
                </header>

                <NewMessageDialog
                    workspaceSlug={workspace.slug}
                    open={composing}
                    onOpenChange={setComposing}
                />

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    <div className="mx-auto w-full max-w-2xl px-4 py-4">
                        <Input
                            value={filter}
                            onChange={(event) => setFilter(event.target.value)}
                            placeholder="Filter by name"
                            aria-label="Filter direct messages"
                        />

                        {visibleGroups.length === 0 &&
                        visiblePeople.length === 0 ? (
                            <EmptyState
                                icon={MessageSquare}
                                title="Nobody to show"
                                description={
                                    term === ''
                                        ? 'Nobody else has joined this workspace yet. Invite someone and they will appear here.'
                                        : 'No group or person matches that. Try a shorter search.'
                                }
                            />
                        ) : (
                            <div className="mt-4 space-y-6">
                                {visibleGroups.length > 0 && (
                                    <Section title="Groups">
                                        {visibleGroups.map((group) => (
                                            <li key={group.id}>
                                                <ConversationListItem
                                                    conversation={group}
                                                    workspaceSlug={
                                                        workspace.slug
                                                    }
                                                    viewerId={
                                                        currentUser?.id ?? null
                                                    }
                                                    isActive={
                                                        group.id ===
                                                        activeConversationId
                                                    }
                                                    unreadMessages={
                                                        unread.conversations[
                                                            group.id
                                                        ]?.messages ?? 0
                                                    }
                                                />
                                            </li>
                                        ))}
                                    </Section>
                                )}

                                {visiblePeople.length > 0 && (
                                    <Section title="People">
                                        {visiblePeople.map((person) => (
                                            <li key={person.user.id}>
                                                <PersonListItem
                                                    person={person}
                                                    workspaceSlug={
                                                        workspace.slug
                                                    }
                                                    isActive={
                                                        person.conversation !==
                                                            null &&
                                                        person.conversation
                                                            .id ===
                                                            activeConversationId
                                                    }
                                                    unreadMessages={
                                                        person.conversation ===
                                                        null
                                                            ? 0
                                                            : (unread
                                                                  .conversations[
                                                                  person
                                                                      .conversation
                                                                      .id
                                                              ]?.messages ?? 0)
                                                    }
                                                />
                                            </li>
                                        ))}
                                    </Section>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section>
            <h2 className="px-2 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h2>
            <ul className="space-y-0.5">{children}</ul>
        </section>
    );
}
