<?php

use App\Support\OneLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings names, channel names and topics, and task titles saved before their
 * models began keeping them to one line into line — see App\Support\OneLine.
 * Only rows that change are written.
 *
 * A name that would clean to nothing — it was all line breaks and invisible
 * characters — becomes the person's username, a channel's its slug, and a
 * task's its reference: an empty name would print as nothing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->select(['id', 'name', 'username'])->orderBy('id')->chunkById(500, function (iterable $users): void {
            foreach ($users as $user) {
                $this->rewrite('users', $user->id, ['name' => $this->nameOr((string) $user->name, (string) $user->username)], ['name' => $user->name]);
            }
        });

        DB::table('channels')->select(['id', 'name', 'slug', 'topic'])->orderBy('id')->chunkById(500, function (iterable $channels): void {
            foreach ($channels as $channel) {
                $this->rewrite('channels', $channel->id, [
                    'name' => $this->nameOr((string) $channel->name, (string) $channel->slug),
                    'topic' => OneLine::cleanOrNull($channel->topic),
                ], ['name' => $channel->name, 'topic' => $channel->topic]);
            }
        });

        DB::table('tasks')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->select(['tasks.id', 'tasks.title', 'tasks.number', 'projects.key'])
            ->orderBy('tasks.id')
            ->chunkById(500, function (iterable $tasks): void {
                foreach ($tasks as $task) {
                    $this->rewrite('tasks', $task->id, ['title' => $this->nameOr((string) $task->title, "{$task->key}-{$task->number}")], ['title' => $task->title]);
                }
            }, 'tasks.id', 'id');
    }

    public function down(): void
    {
        // The text it replaced held line breaks on purpose or by accident;
        // neither is worth restoring.
    }

    private function nameOr(string $name, string $fallback): string
    {
        $clean = OneLine::clean($name);

        return $clean === '' ? $fallback : $clean;
    }

    /**
     * @param  array<string, string|null>  $clean
     * @param  array<string, string|null>  $stored
     */
    private function rewrite(string $table, int $id, array $clean, array $stored): void
    {
        if ($clean !== $stored) {
            DB::table($table)->where('id', $id)->update($clean);
        }
    }
};
