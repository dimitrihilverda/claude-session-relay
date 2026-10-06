<?php
declare(strict_types=1);

//Copy to config.php, or set RELAY_DSN / RELAY_DB_USER / RELAY_DB_PASS (those win).
return array(
	'dsn' => 'pgsql:host=127.0.0.1;dbname=session_relay',
	'user' => 'session_relay',
	'pass' => 'change-me',
);
