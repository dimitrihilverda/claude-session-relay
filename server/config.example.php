<?php
declare(strict_types=1);

//Copy to config.php, or set RELAY_DSN / RELAY_DB_USER / RELAY_DB_PASS / RELAY_PUBLIC_URL / RELAY_OAUTH_EXTRA_REDIRECTS (those win).
return array(
	'dsn' => 'pgsql:host=127.0.0.1;dbname=session_relay',
	'user' => 'session_relay',
	'pass' => 'change-me',
	//Public HTTPS address of the relay (no trailing slash); the MCP/OAuth metadata is built from it.
	'public_url' => 'https://relay.example.com',
	//Optional: extra exact https:// OAuth redirect URIs (a trusted service that links its users to a relay person). Also RELAY_OAUTH_EXTRA_REDIRECTS (comma-separated).
	'oauth_extra_redirects' => array(),
);
