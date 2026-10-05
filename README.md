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
docker compose exec relay relay person:create Alice   # prints Alice's token once
docker compose exec relay relay person:create Bob
```

Put it behind HTTPS. With [Caddy](https://caddyserver.com) that is one line in a Caddyfile:

```
relay.example.com {
	reverse_proxy localhost:8080
}
```

Other commands: `relay person:revoke <name>` (the token stops working), `relay cleanup` (the
compose file already runs it daily). Running `person:create` again for an existing name issues a
new token and invalidates the old one.

### 2. Install the plugin (every developer)

In Claude Code:

```
/plugin marketplace add dimitrihilverda/claude-session-relay
/plugin install session-relay@claude-session-relay
/session-relay:session setup https://relay.example.com <your-token> <your-name>
```

Restart Claude Code. At the start of every session you will see
"Session relay: this session is alice-myrepo-1a2b", followed by the board.

### 3. Use it

Mostly you don't have to: the hooks do the work. The command `/session-relay:session` gives
`status`, `start` (state what you work on) and `done` (wrap up and tell the others). Claude
uses the client for messages, for example:

```
session-relay ask bob-myrepo-9f3c "Are you done with src/billing/?" --session alice-myrepo-1a2b
session-relay board
```

Open the relay's URL in a browser and enter your token to see the board.

## Conflict rules

| Action | Blocked when |
|---|---|
| `git push` | a live session of **another person** is on the same repository and branch |
| `git commit` | a live session of **another person** claims (or is changing) one of the files you commit |

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
- Messages from other sessions are presented to Claude as data, never as instructions.

## Development

```bash
# server tests (PHPUnit against a real PostgreSQL)
docker compose -f server/docker-compose.test.yml -p csr run --rm php composer install
docker compose -f server/docker-compose.test.yml -p csr run --rm php vendor/bin/phpunit

# client end-to-end tests against a local relay (Windows)
powershell -NoProfile -ExecutionPolicy Bypass -File tests/client-smoke.ps1
pwsh -NoProfile -File tests/client-smoke.ps1 -Shell pwsh
```

| Path | What |
|---|---|
| `plugin/` | the Claude Code plugin: hooks, the `/session-relay:session` command and the client |
| `plugin/client/session-relay.ps1` | the client (all logic; Windows PowerShell 5.1 and PowerShell 7) |
| `plugin/client/session-relay` | bash wrapper that starts the client (used by the hooks) |
| `server/` | the relay: PHP without a framework, PostgreSQL, Docker setup |

The relay was first built for a Dutch team, so the API's field names and the server's class
names are Dutch (`sessie` = session, `bericht` = message, `bord` = board). The client and
everything you see are in English.

## License

MIT, see [LICENSE](LICENSE).
