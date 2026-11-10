-- Service tokens: a trusted service (not a person) that may list the teams in its scope and their members.
-- Only the SHA-256 hash of the token is stored.

CREATE TABLE service_token (
	id serial PRIMARY KEY,
	name text NOT NULL UNIQUE,
	token_hash text NOT NULL UNIQUE,
	teams text[] NOT NULL,
	created_at timestamptz NOT NULL DEFAULT now(),
	revoked_at timestamptz
);
