<?php
declare(strict_types=1);

/** Uses */

use Relay\App;
use Relay\Config;
use Relay\Db;
use Relay\Http\Request;
use Relay\Http\Response;

require __DIR__ . '/../bootstrap.php';

//Long-polls take up to 25 seconds:
set_time_limit(60);

try {
	$pdo = Db::connect(Config::load(__DIR__ . '/../config.php'));
} catch(Throwable $error) {
	error_log('session-relay: database unreachable: ' . $error->getMessage());
	(new Response(503, array('error' => 'Database unreachable.')))->send();
	exit;
}

(new App($pdo))->handle(Request::fromGlobals())->send();
