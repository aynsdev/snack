<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\BrowseAssetsTool;
use App\Mcp\Tools\BrowseWorkspaceTool;
use App\Mcp\Tools\CommentOnTaskTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\FindFilesTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListMyTasksTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ReadChannelTool;
use App\Mcp\Tools\ReadFileTool;
use App\Mcp\Tools\ReadThreadTool;
use App\Mcp\Tools\SearchMessagesTool;
use App\Mcp\Tools\SearchTasksTool;
use App\Mcp\Tools\SendMessageTool;
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
  library's folders, and `read_file` opens any file id they return.
- For project work, `list_my_tasks` lists the person's own tasks across every
  project, `get_project` shows a board's columns and people, `list_tasks` lists
  its cards (assignee "me" works), and `get_task` opens one by reference
  (ENG-42).
- `send_message`, `create_task`, `update_task`, and `comment_on_task` act
  under the person's own name and notify their colleagues. Only use them when
  the person has asked you to.
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
        CommentOnTaskTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
