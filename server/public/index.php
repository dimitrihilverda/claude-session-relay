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
	$pdo = Db::verbind(Config::laad(__DIR__ . '/../config.php'));
} catch(Throwable $fout) {
	error_log('sessie-relay: database unreachable: ' . $fout->getMessage());
	(new Response(503, array('fout' => 'Database unreachable.')))->stuur();
	exit;
}

(new App($pdo))->handle(Request::uitGlobals())->stuur();
