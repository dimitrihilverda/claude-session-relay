# Session Relay plugin

Claude Code sessions share a board, message each other and are stopped before a colliding
`git push` or `git commit`: on one machine without any setup (local mode), or across machines
through a relay. Teams on one relay are isolated from each other, and a session can also be
private.

This folder is the plugin: hooks, the `/session-relay:session` command and the client
(`client/session-relay.ps1`, Windows PowerShell 5.1 or PowerShell 7). Without a config file it
works in local mode: no server, no token, nothing leaves the machine. For a team it talks to a
relay you run yourself (PHP + PostgreSQL, Docker setup included) or one your team already runs.

**Local mode** keeps the board and the messages in `~/.claude/session-relay/` on your machine and
sends nothing anywhere. Sessions are forgotten 24 hours after their last heartbeat, messages
after 14 days.

**Data it sends** to a relay you configure, and nowhere else: session names, machine name,
repository and branch names, ticket ids taken from branch names, changed file paths, and the
short messages sessions exchange. No code or file contents. Nothing is sent from folders you
have not mapped to a relay. The relay forgets a session 24 hours after its last heartbeat and
deletes messages after 14 days.

Setup, the relay, the cloud connector and the security model:
https://github.com/dimitrihilverda/claude-session-relay#readme

Privacy: https://github.com/dimitrihilverda/claude-session-relay/blob/main/PRIVACY.md

MIT licence, see LICENSE.
