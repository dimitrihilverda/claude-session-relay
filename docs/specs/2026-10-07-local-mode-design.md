# Local mode: the plugin without a relay server

Status: agreed, 07-10-2026

## Goal

Someone working alone (no team, no server, no token) installs the plugin and gets the same
coordination between their own Claude Code sessions on one machine: a board, claims, notes and
questions, and the commit/push check. Teams keep using a relay server exactly as today. Same repo,
same plugin, same client, same commands.

## Non-goals

- Coordination between machines without a server. Local mode is one machine only.
- Changes to the server, the MCP connector or the board page.
- A second client. Everything stays in `plugin/client/session-relay.ps1`.

## Design

### A relay without a URL

A relay in `~/.claude/session-relay.json` may be **local**:

```json
{ "relays":  { "local": { "local": true, "person": "me" } },
  "folders": { "C:/Projects": { "relay": "local", "team": "private" } } }
```

- No `url`, no `token`. `person` defaults to the OS user name.
- New command: `session-relay relay add-local [<name>] [--person P]` (name defaults to `local`).
- `relays`, `folders`, `me` and `board` show a local relay as `local (this machine)`.
- A local relay has only one team: `private`. `folder <path> local <team>` with any other team is
  refused with a clear message.

### Zero config

If **no config file exists**, the client behaves as if it contained a local relay named `local`
with every folder mapped to it (team `private`). Installing the plugin is then the whole setup.

- Nothing is written until the first `register` or hook needs state.
- As soon as a config file exists, the normal rules apply again: unmapped folders stay off every
  relay. So existing team users (who already have a config) see no change.
- `relay add` on a machine without a config writes the config with that relay only; the implicit
  local relay disappears unless `relay add-local` is run as well.

### One seam: `Invoke-Relay`

All server traffic already goes through `Invoke-Relay $Relay $Method $Path $Body`. For a local
relay it calls `Invoke-LocalRelay` instead of `Invoke-WebRequest`, with the same return shape
(`@{ ok; status; data; error }`). That function implements the same endpoints the client uses:

| Endpoint | Local behaviour |
|---|---|
| `GET /me` | `{ person, teams: [] }` |
| `POST /session` | upsert the session (name, team `private`, machine, repo, repo_base, branch, ticket, claim, last_seen) |
| `DELETE /session/<name>` | remove it |
| `GET /board` | live sessions (last_seen < 10 min) |
| `POST /check` | conflicts with other live sessions, see below |
| `POST /message` | store note / question / answer; 404 when the recipient is unknown |
| `GET /inbox?wait=N` | unread messages to this session; with `wait > 0` poll the store every second up to N seconds |

Because the seam stays the same, hooks, `listen`, `Format-Board`, `Complete-Result` and the
messages Claude sees need no change. Status codes mimic the server (404 unknown session, 409 name
taken), so the existing error handling keeps working.

### The store

- One file: `<StateDir>/local-<relay>.json` (`~/.claude/session-relay/local-local.json`), with
  `sessions` and `messages` (incrementing `id`, `read` flag).
- Writes take an exclusive lock: open `<file>.lock` with `FileShare.None`, retry for up to 2 s.
  Write to a temp file and rename, so a crash never leaves half a file.
- Cleanup on every write: sessions without a heartbeat for 24 h, messages older than 14 days
  (same numbers as the server's `Cleaner`).
- The file holds no secrets; it lives next to the existing state in `StateDir`.

### The check between your own sessions

The server's check skips sessions of the same person (`s.person_id <> :person`): on a team relay,
your own sessions are not a conflict. In local mode **every** session is the same person, so the
local check compares against all other live sessions with the same `repo_base` — same rules
otherwise (`Conflict::reason`: same branch on push, overlapping claim on commit). The path logic
is ported 1:1 from `server/src/Conflict.php` into the client.

### Texts

- Start hook: `this session is <name>, local (sessions on this machine only)`.
- `session.md`: a short "Local mode" section: what it is, that it is one machine only, and how to
  move to a team (`relay add` + `folder`) later without reinstalling.
- README: "Quick start, alone" (install the plugin, done) before "Quick start, with a team".

## Tests

Extend `tests/client-offline.ps1` (no server needed, temp `StateDir`):

1. No config: start hook registers the session locally, `board` shows it.
2. Two sessions in one repo: overlapping claim → commit denied; same branch → push denied;
   `# session-relay:override` passes.
3. `send` / `ask` / `answer` between two sessions; `inbox` marks them read; `listen` returns a
   message that arrives while waiting.
4. Session without heartbeat for 10 min drops off the board (fake `last_seen`).
5. Config with only a remote relay: an unmapped folder stays off every relay (unchanged behaviour).
6. Concurrent writes (two jobs registering 20 times each): the file stays valid JSON, nothing lost.
7. Port of the `Conflict` cases from the server's unit tests.

## Documentation

- `README.md`: intro no longer "different machines" only; a "Quick start, alone" before the team
  quick start; local mode in "How it works", "Conflict rules" (own sessions do block each other
  locally) and "Requirements" (no server needed); the offline tests in "Development".
- `plugin/README.md`, `PRIVACY.md`: in local mode nothing leaves the machine; where the local
  file lives and how long it keeps data.
- `plugin/commands/session.md`: a "Local mode" section and setup without a token.
- Client `help` text; manifest descriptions (`plugin.json`, `marketplace.json`).

## Decisions (07-10-2026)

1. Zero config by default: without a config file, every folder is on the implicit local relay.
2. Own sessions on a team relay still never block each other; that is a follow-up.
3. Version 1.2.0 for the plugin, and `server.json` goes along so the registry version matches.
