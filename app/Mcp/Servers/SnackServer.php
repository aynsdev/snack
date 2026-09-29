<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\BrowseWorkspaceTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\FindFilesTool;
use App\Mcp\Tools\ReadChannelTool;
use App\Mcp\Tools\ReadThreadTool;
use App\Mcp\Tools\SearchMessagesTool;
use App\Mcp\Tools\SearchTasksTool;
use App\Mcp\Tools\SendMessageTool;
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
one workspace, and can see only what they can see.

- To answer a question about what was said or decided, start with
  `search_messages`, then open context with `read_thread` or `read_channel`.
- Use `browse_workspace` to turn a name ("the design channel", "Ada", "the
  mobile project") into the exact channel, @username, or project key.
- `send_message` and `create_task` act under the person's own name and notify
  their colleagues. Only use them when the person has asked you to.
TEXT)]
class SnackServer extends Server
{
    protected array $tools = [
        SearchMessagesTool::class,
        ReadChannelTool::class,
        ReadThreadTool::class,
        BrowseWorkspaceTool::class,
        SearchTasksTool::class,
        FindFilesTool::class,
        SendMessageTool::class,
        CreateTaskTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
