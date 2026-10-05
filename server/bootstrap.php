<?php
declare(strict_types=1);

//Load Relay classes without Composer (production has no vendor/):
spl_autoload_register(static function(string $klasse): void
{
	if(str_starts_with($klasse, 'Relay\\') === false) {
		return;
	}
	$pad = __DIR__ . '/src/' . str_replace('\\', '/', substr($klasse, 6)) . '.php';
	if(is_file($pad) === true) {
		require $pad;
	}
});
