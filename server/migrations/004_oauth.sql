-- OAuth 2.1 for the MCP endpoint (Claude custom connectors). Codes and tokens are stored as SHA-256 hashes only.

-- Clients from dynamic client registration (RFC 7591); public clients, no secret.
-- ip_hash (SHA-256 of the registering address) only serves the per-address rate limit.
CREATE TABLE oauth_client (
	id text PRIMARY KEY,
	name text NOT NULL,
	redirect_uris jsonb NOT NULL,
	ip_hash text NOT NULL,
	created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX oauth_client_ip ON oauth_client (ip_hash, created_at);

-- Authorization codes: valid 60 seconds, single use, PKCE S256. A replayed code revokes the
-- token family that was issued from it.
CREATE TABLE oauth_code (
	code_hash text PRIMARY KEY,
	family text NOT NULL,
	client_id text NOT NULL REFERENCES oauth_client(id) ON DELETE CASCADE,
	person_id integer NOT NULL REFERENCES person(id) ON DELETE CASCADE,
	redirect_uri text NOT NULL,
	code_challenge text NOT NULL,
	expires_at timestamptz NOT NULL,
	used_at timestamptz
);

-- Access (1 hour) + refresh (30 days) token pairs. A refresh rotates the pair; reusing an old
-- refresh token revokes the whole family.
CREATE TABLE oauth_token (
	id bigserial PRIMARY KEY,
	family text NOT NULL,
	access_hash text NOT NULL UNIQUE,
	refresh_hash text NOT NULL UNIQUE,
	person_id integer NOT NULL REFERENCES person(id) ON DELETE CASCADE,
	client_id text NOT NULL REFERENCES oauth_client(id) ON DELETE CASCADE,
	access_expires_at timestamptz NOT NULL,
	refresh_expires_at timestamptz NOT NULL,
	rotated_at timestamptz,
	revoked_at timestamptz,
	created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX oauth_token_family ON oauth_token (family);
CREATE INDEX oauth_token_person ON oauth_token (person_id);

-- Server secrets (e.g. the key that signs the CSRF token of the consent page).
CREATE TABLE relay_secret (
	name text PRIMARY KEY,
	value text NOT NULL
);
