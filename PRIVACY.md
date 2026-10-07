# Privacy

Session Relay is open-source software that you run yourself. The author does not operate a
service for it, does not receive any data from it, and has no access to your relay.

## Local mode: nothing leaves your machine

Without a config file (or for folders mapped to a local relay) the plugin talks to no server at
all. The board and the messages between your sessions are kept in a file in
`~/.claude/session-relay/` on your own machine: session names, repository and branch names,
claimed file paths and the messages. Sessions are removed 24 hours after their last heartbeat,
messages after 14 days. Delete that folder to remove everything.

## What the plugin sends, and where

The plugin talks only to the relay server(s) you configure with `session-relay relay add`: one
you host yourself, or one your team hosts. It sends nothing to the author, to Anthropic or to
any other third party, and it contains no telemetry or analytics.

For sessions started in a folder you mapped to a relay, it sends:

- the person name you chose when setting up the relay, and your relay token (as a bearer
  header, only over https, or plain http to localhost);
- the session name and the machine name;
- repository and branch names, and a ticket id taken from the branch name;
- the paths of files changed on the branch and of uncommitted work (not their contents);
- the short messages your sessions send to other sessions.

It never sends source code or file contents. Sessions started in folders you did not map send
nothing.

## What the relay stores

The relay stores the data above so that the sessions of your team can see the board and read
their messages:

- a session is removed 24 hours after its last heartbeat (it disappears from the board after
  10 minutes);
- messages are deleted after 14 days;
- tokens are stored only as SHA-256 hashes; OAuth codes and tokens for the cloud connector
  expire and are removed by the nightly cleanup.

Who can see what: people in the same team see that team's sessions and messages addressed to
them; private sessions are visible only to their owner. The relay's operator (you or your team)
controls the server, its database and its backups.

## Your choices

- Map only the folders you want on a relay (`session-relay folder`), or remove a mapping
  (`session-relay folder remove`).
- Remove a relay from your machine with `session-relay relay remove`.
- Stay fully local: don't add a relay server (or remove it); local mode needs none.
- The relay operator can revoke a token (`relay person:revoke`) and delete data from the
  database at any time.

## Contact

Questions about this software: open an issue at
https://github.com/dimitrihilverda/claude-session-relay/issues. Questions about data on a
specific relay go to whoever runs that relay.
