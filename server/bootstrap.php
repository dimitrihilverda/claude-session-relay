<?php
declare(strict_types=1);

//Load Relay classes without Composer (production has no vendor/):
spl_autoload_register(static function(string $class): void
{
	if(str_starts_with($class, 'Relay\\') === false) {
		return;
	}
	$path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
	if(is_file($path) === true) {
		require $path;
	}
});
