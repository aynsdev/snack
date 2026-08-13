<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BiteBot;
use App\Services\BiteSummons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The people you can mention in a channel.
 *
 * Answers with JSON because the composer filters as you type, and an Inertia
 * visit per keystroke would replace the page's props on every character.
 *
 * # Scoped to the channel, deliberately
 *
 * Only members are offered, because only members can be mentioned — see
 * {@see App\Actions\Messages\SyncMentions}, which resolves handles against the
 * container rather than against the users table. A picker that offered people
 * the server would then ignore would be worse than no picker: the author would
 * believe they had notified someone.
 *
 * Bite is the one exception, for the reason set out on {@see self::withBite()}:
 * it is addressable in a channel it has not joined, so leaving it out was the
 * picker lying in the other direction.
 *
 * Gated on `post` rather than `view`. Someone previewing a public channel they
 * have not joined cannot write in it, so they have no use for its roster — and
 * that keeps this from becoming a membership directory for channels the viewer
 * is merely allowed to look at.
 */
class MentionableController extends Controller
{
    public function __construct(
        private readonly BiteSummons $summons,
        private readonly BiteBot $bots,
    ) {}

    public function index(Workspace $workspace, Channel $channel, Request $request): JsonResponse
    {
        $this->authorize('post', $channel);

        $term = trim((string) $request->string('q'));

        $people = $channel->users()
            // You are not offered to yourself: mentioning yourself notifies
            // nobody, so the server would discard it anyway.
            ->whereKeyNot($request->user()->id)
            ->when($term !== '', fn ($query) => $query->where(
                fn ($match) => $match
                    ->where('users.name', 'ilike', '%'.$term.'%')
                    ->orWhere('users.username', 'ilike', '%'.$term.'%'),
            ))
            ->orderBy('users.name')
            // A picker, not an export. The composer shows a handful at a time.
            ->limit(20)
            ->get();

        return response()->json([
            'people' => $this->withBite($workspace, $channel, $people, $term)
                ->map(fn (User $user): array => (new UserResource($user))->resolve($request))
                ->all(),
        ]);
    }

    /**
     * Offer Bite whether or not it is in this channel yet.
     *
     * The one exception to the members-only rule above, and it is not a false
     * promise: {@see App\Services\BiteSummons} matches `@bite` against the text
     * of the message rather than against the channel's roster, and puts the bot
     * in the channel as it answers. So Bite is genuinely addressable in a
     * channel it has never spoken in — the roster simply has not caught up.
     *
     * Without this the picker was a chicken and egg. Bite joins on its first
     * summon, so it could only be *offered* somewhere it had already been
     * *typed by hand*, and a private channel — where nobody has broken the ice
     * and there is no public history to have seen it in — never got a first
     * summon at all. It looked exactly like a feature that does not work there.
     *
     * Excluded when the installation has Bite switched off or unconfigured, for
     * the same reason {@see BiteSummons::available()} exists: an offer that
     * summons nothing is worse than no offer.
     *
     * @param  Collection<int, User>  $people
     * @return Collection<int, User>
     */
    private function withBite(
        Workspace $workspace,
        Channel $channel,
        Collection $people,
        string $term,
    ): Collection {
        if (! $this->summons->available()) {
            return $people;
        }

        /*
         * Deliberately the account that exists rather than one created here.
         * This is a read — a picker being filtered as somebody types — and it
         * must not bring an account, a workspace seat, and a stored avatar into
         * being as a side effect of being looked at. A workspace that has never
         * used Bite still has to type the handle once; what this fixes is every
         * channel after that.
         */
        $bot = $this->bots->existingAccountFor($workspace);

        if ($bot === null || $people->contains(fn (User $person): bool => $person->id === $bot->id)) {
            return $people;
        }

        if ($term !== '' && ! $this->matches($bot, $term)) {
            return $people;
        }

        /*
         * Merged into alphabetical order rather than pinned to the top: it is
         * offered like anybody else in the channel, and typing toward the
         * handle narrows the list to it anyway. Re-trimmed to the same cap so a
         * large channel does not answer with twenty-one.
         */
        return $people
            ->push($bot)
            ->sortBy(fn (User $person): string => Str::lower($person->name))
            ->take(20)
            ->values();
    }

    /**
     * Whether what is being typed is reaching for this account.
     *
     * The same `ilike` contains-match the query above uses, so the bot appears
     * and disappears from the list on the same keystrokes a person would.
     */
    private function matches(User $bot, string $term): bool
    {
        $needle = Str::lower($term);

        return Str::contains(Str::lower($bot->name), $needle)
            || Str::contains(Str::lower((string) $bot->username), $needle);
    }
}
