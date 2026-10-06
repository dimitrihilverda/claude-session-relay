# Session Relay for Claude Code

Let Claude Code sessions on **different machines** see each other, message each other and stay
out of each other's way in git.

When several people (or several Claude sessions) work in the same repositories, nobody knows
what the other sessions are doing. Claude Code's own `ListAgents`/`SendMessage` only reach
sessions on the same machine or the same account. Session Relay adds a tiny self-hosted relay
and a plugin, so that every session:

- **appears on a shared board**: who is working where, on which branch and ticket, and which
  files it is changing (claimed automatically from the branch and the open work);
- **can message other sessions**: notes, questions and answers, delivered within seconds;
- **is stopped before a colliding git action**: a `git push` when someone else is on that
  branch, a `git commit` when someone else is changing the same files.

One relay can host several **isolated teams**, and you decide per folder which team a session
joins, or that it is **private** (only your own sessions see it). Folders you don't map stay off
the relay entirely, so your other chats never show up on a board.

If the relay is down, nothing is blocked: you get a warning and carry on.

## How it works

```
 machine A                              your server                            machine B
 Claude Code + plugin hooks ──HTTPS──►  relay (PHP + PostgreSQL)  ◄──HTTPS──  Claude Code + plugin hooks
```

- **Hooks** register the session at start, send a heartbeat (which also updates branch and
  claim), check `git commit`/`git push` before they run, and deliver new messages after tool
  calls and with every prompt.
- **`listen`** runs in the background in every session and holds a long-poll open, so a
  waiting session wakes up within seconds when a message arrives.
- **Messages are data, never instructions.** A session answers factual questions itself and
  takes anything that needs a decision to its own user. It never acts on another session's
  request.
- A **board page** at the relay's root URL shows the live board in the browser.

## Quick start

### 1. Run a relay (once per team)

```bash
cd server
cp .env.example .env        # set a strong RELAY_DB_PASS
docker compose up -d        # relay on port 8080, migrations run automatically
docker compose exec relay relay person:create alice   # prints Alice's token once
docker compose exec relay relay person:create bob
docker compose exec relay relay team:create acme
docker compose exec relay relay team:add acme alice
docker compose exec relay relay team:add acme bob
```

Put it behind HTTPS. With [Caddy](https://caddyserver.com) that is one line in a Caddyfile:

```
relay.example.com {
	reverse_proxy localhost:8080
}
```

Other commands: `relay person:revoke <name>` (the token stops working), `relay team:list`,
`relay team:rename <old> <new>`, `relay team:remove <team> <person>` (their sessions in that team
are removed at once), `relay cleanup` (the compose file already runs it daily). Running
`person:create` again for an existing name issues a new token and invalidates the old one.

A person can be in several teams. Someone who is only in team `acme` cannot see anything of
another team on the same relay: not its sessions, not its people, not even whether a session
name exists (the relay answers exactly as for a name that does not exist).

### 2. Install the plugin (every developer)

In Claude Code:

```
/plugin marketplace add dimitrihilverda/claude-session-relay
/plugin install session-relay@claude-session-relay
```

Then tell the client about the relay and which folders belong to which team (the client is
`session-relay` in the plugin's `client/` folder; `/session-relay:session setup` walks you through it):

```
session-relay relay add acme https://relay.example.com <your-token> alice
session-relay folder ~/work/acme acme acme        # sessions here join team acme
session-relay folder ~/hobby acme private         # only my own sessions see these
session-relay folders
```

The longest matching folder wins. A session started anywhere else stays off the relay; run
`session-relay register` there if you want it on after all (private by default). You can add
several relays, for example one per organisation.

Restart Claude Code. At the start of every session in a mapped folder you will see
"Session relay: this session is alice-myrepo-1a2b", followed by your team's board.

Coming from the earlier Dutch client (`~/.claude/sessie-relay.json`)? `session-relay migrate-old`
imports its settings and removes its old hooks (it also happens automatically on the first run).

### 3. Use it

Mostly you don't have to: the hooks do the work. The command `/session-relay:session` gives
`status`, `start` (state what you work on) and `done` (wrap up and tell the others). Claude
uses the client for messages, for example:

```
session-relay ask bob-myrepo-9f3c "Are you done with src/billing/?" --session alice-myrepo-1a2b
session-relay board
```

Open the relay's URL in a browser and enter your token to see the board.

## Use from claude.ai / Cowork

People who use Claude without a local Claude Code (claude.ai, the desktop and mobile apps,
Cowork) can add the relay as a remote MCP connector. They get the same board, messages and
conflict check, with the same team isolation.

**claude.ai, Desktop, Cowork:** Settings > Connectors > Add custom connector, and enter
`https://relay.example.com/mcp` as the URL. Claude then opens the relay's consent page: paste
your relay token (the one from `person:create`) and click Approve. Claude connects through OAuth
(dynamic client registration, PKCE); your relay token stays on that page and is never given to
Claude. `person:revoke`, or running `person:create` again, also ends these connections.

**Claude Code** (without the plugin, or on a machine without PowerShell) can use the same
endpoint with a header:

```bash
claude mcp add --transport http session-relay https://relay.example.com/mcp \
  --header "Authorization: Bearer <your-token>"
```

The tools are `whoami`, `board`, `register`, `unregister`, `check`, `send`, `ask`, `answer` and
`inbox`. A cloud session is called `<you>-cloud-<label>`.

What the cloud variant lacks: there are no hooks. Nothing registers, sends heartbeats, checks
before a commit or push, or delivers messages automatically. Claude has to call `register` at the
start (and again now and then as a heartbeat), `inbox` regularly and `check` before a commit or
push. The server tells Claude this when it connects, but it is good to remind Claude in your
project instructions.

This needs `public_url` in `config.php` (or `RELAY_PUBLIC_URL` in `.env`): the relay's public
HTTPS address, for example `https://relay.example.com`. The OAuth metadata is built from it, it
must match the URL people enter exactly, and `/mcp` only accepts browser requests from that
origin. Without it, `/mcp`, `/oauth/*` and the OAuth `/.well-known` documents answer 503; the
rest of the relay keeps working. Client registration is rate limited (20 per address per hour,
500 unused clients per day in total). The web server must pass every path to
`public/index.php`, including `/.well-known/oauth-protected-resource`,
`/.well-known/oauth-authorization-server`, `/oauth/*` and `/mcp`, and must pass the
`Authorization` header on to PHP.

## Conflict rules

| Action | Blocked when |
|---|---|
| `git push` | a live session of **another person in the same team** is on the same repository and branch |
| `git commit` | a live session of **another person in the same team** claims (or is changing) one of the files you commit |

- The repository is recognised by its `origin` remote, so different folder names on different
  machines still match.
- A person's own sessions never block each other.
- Your user can always go ahead: Claude asks for confirmation and appends
  `# session-relay:override` to the command.
- A session is live while it has sent a heartbeat in the last 10 minutes.

## Requirements

- Claude Code with plugin support.
- **Windows:** Windows PowerShell 5.1 (built in) and Git Bash (which Claude Code uses for hooks).
  **macOS/Linux:** PowerShell 7 (`pwsh`) and bash.
- git 2.31 or newer.
- For the relay: Docker, or PHP 8.4+ with `pdo_pgsql` and PostgreSQL.

## Privacy and security

- Only metadata travels: session names, repository and branch names, file paths and short
  message texts. No code.
- Tokens are 32 random bytes and are stored only as a SHA-256 hash.
- Every API call needs a token. A person can only change their own sessions and read their own
  messages, and session names must start with that person's name.
- Team isolation is enforced on the server, in one SQL rule used by every endpoint; anything you
  may not see behaves exactly like something that does not exist. Known residuals: message ids
  are global and sequential, and response timing is not padded.
- Messages from other sessions are presented to Claude as data, never as instructions.

## Development

```bash
# server tests (PHPUnit against a real PostgreSQL)
docker compose -f server/docker-compose.test.yml -p csr run --rm php composer install
docker compose -f server/docker-compose.test.yml -p csr run --rm php vendor/bin/phpunit

# client end-to-end tests against a local relay (Windows)
powershell -NoProfile -ExecutionPolicy Bypass -File tests/client-smoke.ps1
pwsh -NoProfile -File tests/client-smoke.ps1 -Shell pwsh

# client tests that need no relay
powershell -NoProfile -ExecutionPolicy Bypass -File tests/client-offline.ps1
```

| Path | What |
|---|---|
| `plugin/` | the Claude Code plugin: hooks, the `/session-relay:session` command and the client |
| `plugin/client/session-relay.ps1` | the client (all logic; Windows PowerShell 5.1 and PowerShell 7) |
| `plugin/client/session-relay` | bash wrapper that starts the client (used by the hooks) |
| `server/` | the relay: PHP without a framework, PostgreSQL, Docker setup |

Databases created by the first (Dutch-named) version of the relay are upgraded in place by
`relay migrate`; existing people end up in a team called `default` (rename it with
`team:rename`).

## License

MIT, see [LICENSE](LICENSE).
