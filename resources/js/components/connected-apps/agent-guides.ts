export type GuideStep = {
    text: string;
    code?: string;
    file?: string;
};

export type AuthMode = 'sign-in' | 'api-key';

export const kinds: Record<string, string> = {
    'claude-code': 'Terminal',
    claude: 'Web and desktop',
    chatgpt: 'Web',
    cursor: 'Editor',
    'vs-code': 'Editor',
    'gemini-cli': 'Terminal',
    codex: 'Terminal',
    zed: 'Editor',
    other: 'Any MCP client',
};

const KEY = 'YOUR_API_KEY';

function json(value: unknown): string {
    return JSON.stringify(value, null, 2);
}

const approve: GuideStep = {
    text: 'Pick what it may do on the Databasify page that opens, then approve.',
};

export const guides: Record<
    string,
    (url: string, mode: AuthMode) => GuideStep[]
> = {
    'claude-code': (url, mode) =>
        mode === 'sign-in'
            ? [
                  {
                      text: 'Run the command. Add --scope user to use it in every project.',
                      code: `claude mcp add --transport http databasify ${url}`,
                  },
                  {
                      text: 'In Claude Code, run /mcp, choose databasify, and sign in.',
                  },
                  approve,
              ]
            : [
                  {
                      text: 'Run the command with your API key. Add --scope user to use it in every project.',
                      code: `claude mcp add --transport http databasify ${url} --header "Authorization: Bearer ${KEY}"`,
                  },
              ],
    claude: (url, mode) => [
        {
            text: 'In Claude, open Customize, then Connectors, then + Add and Add custom connector.',
        },
        { text: 'Name it Databasify and paste the server URL.', code: url },
        mode === 'sign-in'
            ? {
                  text: 'Choose Sign in now, then approve on the Databasify page that opens.',
              }
            : {
                  text: 'Choose No sign in, and under Request headers add your API key.',
                  code: `Authorization: Bearer ${KEY}`,
              },
    ],
    chatgpt: (url) => [
        {
            text: 'On chatgpt.com, open Plugins, select +, then Add custom MCP server.',
        },
        {
            text: 'Name it Databasify, paste the server URL, and pick OAuth.',
            code: url,
        },
        {
            text: 'Create it as a plugin, install it, and sign in when ChatGPT asks.',
        },
        approve,
    ],
    cursor: (url, mode) => [
        {
            text: 'Add the server to your Cursor MCP config.',
            file: '~/.cursor/mcp.json',
            code: json({
                mcpServers: {
                    databasify:
                        mode === 'sign-in'
                            ? { url }
                            : {
                                  url,
                                  headers: {
                                      Authorization:
                                          'Bearer ${env:DATABASIFY_API_KEY}',
                                  },
                              },
                },
            }),
        },
        mode === 'sign-in'
            ? {
                  text: 'In Cursor Settings, open MCP and sign in to databasify.',
              }
            : {
                  text: 'Set DATABASIFY_API_KEY to your API key before starting Cursor.',
              },
        ...(mode === 'sign-in' ? [approve] : []),
    ],
    'vs-code': (url, mode) => [
        {
            text: 'Add the server to your workspace MCP config.',
            file: '.vscode/mcp.json',
            code: json({
                servers: {
                    databasify:
                        mode === 'sign-in'
                            ? { type: 'http', url }
                            : {
                                  type: 'http',
                                  url,
                                  headers: { Authorization: `Bearer ${KEY}` },
                              },
                },
            }),
        },
        mode === 'sign-in'
            ? {
                  text: 'Start databasify from the MCP servers view. VS Code opens the sign-in page.',
              }
            : { text: 'Start databasify from the MCP servers view.' },
        ...(mode === 'sign-in' ? [approve] : []),
    ],
    'gemini-cli': (url, mode) =>
        mode === 'sign-in'
            ? [
                  {
                      text: 'Add the server. Add -s user to use it in every project.',
                      code: `gemini mcp add --transport http databasify ${url}`,
                  },
                  {
                      text: 'In Gemini CLI, sign in.',
                      code: '/mcp auth databasify',
                  },
                  approve,
              ]
            : [
                  {
                      text: 'Add the server with your API key. Add -s user to use it in every project.',
                      code: `gemini mcp add --transport http --header "Authorization: Bearer ${KEY}" databasify ${url}`,
                  },
              ],
    codex: (url, mode) =>
        mode === 'sign-in'
            ? [
                  {
                      text: 'Add the server.',
                      code: `codex mcp add databasify --url ${url}`,
                  },
                  { text: 'Sign in.', code: 'codex mcp login databasify' },
                  approve,
              ]
            : [
                  {
                      text: 'Add the server to your Codex config.',
                      file: '~/.codex/config.toml',
                      code: `[mcp_servers.databasify]\nurl = "${url}"\nbearer_token_env_var = "DATABASIFY_API_KEY"`,
                  },
                  {
                      text: 'Set DATABASIFY_API_KEY to your API key before starting Codex.',
                  },
              ],
    zed: (url, mode) => [
        {
            text: 'Add the server to your Zed settings.',
            file: 'settings.json',
            code: json({
                context_servers: {
                    databasify:
                        mode === 'sign-in'
                            ? { url }
                            : {
                                  url,
                                  headers: { Authorization: `Bearer ${KEY}` },
                              },
                },
            }),
        },
        mode === 'sign-in'
            ? { text: 'Zed asks you to sign in the first time it connects.' }
            : { text: 'Zed connects with your API key.' },
        ...(mode === 'sign-in' ? [approve] : []),
    ],
    other: (url, mode) => [
        {
            text: 'Point any MCP client that speaks Streamable HTTP at the server URL.',
            code: url,
        },
        mode === 'sign-in'
            ? {
                  text: 'Clients with MCP OAuth find the sign-in page on their own.',
              }
            : {
                  text: 'Send your API key with every request.',
                  code: `Authorization: Bearer ${KEY}`,
              },
        ...(mode === 'sign-in' ? [approve] : []),
    ],
};
