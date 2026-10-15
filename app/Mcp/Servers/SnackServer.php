<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddLinkTool;
use App\Mcp\Tools\BrowseAssetsTool;
use App\Mcp\Tools\BrowseWorkspaceTool;
use App\Mcp\Tools\CommentOnTaskTool;
use App\Mcp\Tools\CreateReleaseTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\FindFilesTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetReleaseTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\LinkTasksTool;
use App\Mcp\Tools\ListMyTasksTool;
use App\Mcp\Tools\ListReleasesTool;
use App\Mcp\Tools\ListSprintsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ReadChannelTool;
use App\Mcp\Tools\ReadFileTool;
use App\Mcp\Tools\ReadThreadTool;
use App\Mcp\Tools\RemoveLinkTool;
use App\Mcp\Tools\SearchMessagesTool;
use App\Mcp\Tools\SearchTasksTool;
use App\Mcp\Tools\SendMessageTool;
use App\Mcp\Tools\UnlinkTasksTool;
use App\Mcp\Tools\UpdateReleaseTool;
use App\Mcp\Tools\UpdateTasksTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Mcp\Tools\WhoAmITool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Snack, as seen by an AI client.
 *
 * Mounted at `/mcp` in routes/ai.php and reached with a connector token from a
 * workspace's Connectors page. Every tool acts as the person who created that
 * token, inside the one workspace it was created for — see
 * {@see App\Mcp\Tools\SnackTool} for how that is resolved, and why an assistant
 * connected here can never know more than its owner could find by clicking.
 */
#[Name('Snack')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Snack is a team chat workspace with channels, direct messages, threads,
project boards, and a shared file library. You are connected as one member of
one workspace, and can see only what they can see. You are not told who that
is up front: call `whoami` whenever a request says "me", "my", or "I".

- To answer a question about what was said or decided, start with
  `search_messages`, then open context with `read_thread` or `read_channel`.
- Use `browse_workspace` to turn a name ("the design channel", "Ada", "the
  mobile project") into the exact channel, @username, or project key.
- For files, `find_files` searches by name, `browse_assets` walks the asset
  library's folders, and `read_file` opens any file id they return. When the
  person wants a file itself, give them the download link those tools return.
- For project work, `list_my_tasks` lists the person's own tasks across every
  project, `get_project` shows a board's columns and people, `list_tasks` lists
  its cards (assignee "me" works), and `get_task` opens one by reference
  (ENG-42).
- `list_sprints` and `list_releases` show where work can be placed;
  `get_release` lists one release's tasks as a checklist. Tasks take a
  `sprint`, a `release`, and a `parent` task on create and update, and
  `update_tasks` moves many at once. When a name matches nothing, the reply
  lists the valid options.
- To break a task into subtasks, as in Jira, call `create_task` with
  `type: "subtask"` and `parent` set to the story, task, or bug. A subtask
  always has a parent, never has subtasks of its own, never sits straight
  under an epic, and starts in its parent's sprint and release. Moving the
  parent to another sprint takes its unfinished subtasks along.
- `add_link` puts a web link such as a pull request on a task; `link_tasks`
  says one task blocks, relates to, or duplicates another.
- Every tool that changes something — `send_message`, `create_task`,
  `update_task`, `update_tasks`, `comment_on_task`, `add_link`,
  `remove_link`, `link_tasks`, `unlink_tasks`, `create_release`, and
  `update_release` — acts under the person's own name and may notify their
  colleagues. Only use them when the person has asked you to.
TEXT)]
class SnackServer extends Server
{
    protected array $tools = [
        WhoAmITool::class,
        SearchMessagesTool::class,
        ReadChannelTool::class,
        ReadThreadTool::class,
        BrowseWorkspaceTool::class,
        SearchTasksTool::class,
        FindFilesTool::class,
        SendMessageTool::class,
        BrowseAssetsTool::class,
        ReadFileTool::class,
        GetProjectTool::class,
        ListMyTasksTool::class,
        ListTasksTool::class,
        GetTaskTool::class,
        CreateTaskTool::class,
        UpdateTaskTool::class,
        UpdateTasksTool::class,
        CommentOnTaskTool::class,
        AddLinkTool::class,
        RemoveLinkTool::class,
        LinkTasksTool::class,
        UnlinkTasksTool::class,
        ListSprintsTool::class,
        ListReleasesTool::class,
        GetReleaseTool::class,
        CreateReleaseTool::class,
        UpdateReleaseTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
