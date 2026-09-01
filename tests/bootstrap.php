<?php declare(strict_types=1);

if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo 'Install Nette Tester using `composer install`';
	exit(1);
}


spl_autoload_register(function (string $class): void {
	if (str_starts_with($class, 'Acme\DressCode\\')) {
		require __DIR__ . '/fixtures/plugin/src/' . strtr(substr($class, 15), '\\', '/') . '.php';
	}
});

Tester\Environment::setup();
Tester\Environment::setupFunctions();
