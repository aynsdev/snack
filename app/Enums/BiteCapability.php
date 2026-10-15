<?php

namespace App\Enums;

use App\Ai\Tools;

/**
 * The things Bite is able to do, as an admin gets to switch them on and off.
 *
 * # Capabilities, not tool classes
 *
 * The stored value is one of these strings and never a class name. A class name
 * in a database column is a refactor that silently disables a feature: rename
 * the tool, and every workspace that had it switched on now has a setting
 * pointing at nothing. This enum is the stable vocabulary, and
 * {@see self::tool()} is the one place that knows what each one is made of.
 *
 * # Switching one off is a real restriction
 *
 * Not a hint to the model. A disabled capability is not handed to the provider
 * at all — see {@see App\Ai\Agents\Bite::tools()} — so the model cannot call it,
 * cannot be talked into calling it, and does not know it exists. That is the
 * only kind of restriction worth offering: anything enforced by asking the
 * model nicely is enforced right up until somebody phrases a message cleverly.
 */
enum BiteCapability: string
{
    case SearchMessages = 'search_messages';
    case BrowseWorkspace = 'browse_workspace';
    case FindFiles = 'find_files';
    case ReadFile = 'read_file';
    case AttachFile = 'attach_file';
    case SearchTasks = 'search_tasks';
    case CreateTask = 'create_task';
    case PeopleReport = 'people_report';
    case GenerateImage = 'generate_image';
    case GenerateVideo = 'generate_video';

    public function label(): string
    {
        return match ($this) {
            self::SearchMessages => 'Search messages',
            self::BrowseWorkspace => 'Browse the workspace',
            self::FindFiles => 'Find files',
            self::ReadFile => 'Read files',
            self::AttachFile => 'Send files into the chat',
            self::SearchTasks => 'Search tasks',
            self::CreateTask => 'Create tasks',
            self::PeopleReport => 'HR reports',
            self::GenerateImage => 'Generate images',
            self::GenerateVideo => 'Generate videos',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SearchMessages => 'Look through what people have written, in the channels and DMs the person asking can already read.',
            self::BrowseWorkspace => 'List the channels, projects, and people the person asking can see.',
            self::FindFiles => 'Find uploaded files and library assets by name.',
            self::ReadFile => 'Read the contents of a file to answer a question about it.',
            self::AttachFile => 'Attach a file it found to its reply, so nobody has to go and fetch it.',
            self::SearchTasks => 'Look through the project boards for existing work.',
            self::CreateTask => 'File a task on a board. Always asks the person for approval first.',
            self::PeopleReport => 'Report attendance and task output per employee — lateness, undertime, absences. Only for admins, and only in a private DM with Bite.',
            self::GenerateImage => 'Make images from a description with Magnific and post them in the chat. Each one is a paid generation, and only works when the server has a Magnific API key.',
            self::GenerateVideo => 'Make short videos from a description or an image with Magnific. Paid per generation and slower than images; needs a Magnific API key on the server.',
        };
    }

    /**
     * Whether this capability changes something rather than only reading.
     *
     * Used by the admin screen to file the writes apart from the reads: the
     * question "what can this thing alter" deserves an answer that is not
     * buried in a list of seven checkboxes.
     */
    public function writes(): bool
    {
        return match ($this) {
            self::CreateTask, self::AttachFile, self::GenerateImage, self::GenerateVideo => true,
            default => false,
        };
    }

    /**
     * The tool class this capability switches on.
     *
     * @return class-string
     */
    public function tool(): string
    {
        return match ($this) {
            self::SearchMessages => Tools\SearchMessages::class,
            self::BrowseWorkspace => Tools\BrowseWorkspace::class,
            self::FindFiles => Tools\FindFiles::class,
            self::ReadFile => Tools\ReadFile::class,
            self::AttachFile => Tools\AttachFile::class,
            self::SearchTasks => Tools\SearchTasks::class,
            self::CreateTask => Tools\CreateTask::class,
            self::PeopleReport => Tools\PeopleReport::class,
            self::GenerateImage => Tools\GenerateImage::class,
            self::GenerateVideo => Tools\GenerateVideo::class,
        };
    }

    /**
     * Every capability, as stored values.
     *
     * The default for a workspace that has never opened the settings screen:
     * Bite arrives with everything it shipped with, because an assistant that
     * has to be switched on capability by capability before it can answer
     * anything is one nobody gets round to switching on.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_map(fn (self $capability): string => $capability->value, self::cases());
    }
}
