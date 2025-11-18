<?php

/*
|--------------------------------------------------------------------------
| Test Bootstrap
|--------------------------------------------------------------------------
|
| This file ensures tests use the correct environment configuration
| by setting environment variables before Laravel loads the .env file.
|
*/

// Force SQLite database for tests using putenv
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('APP_ENV=testing');
putenv('MAIL_MAILER=array');

// Also set in $_ENV and $_SERVER for consistency
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_ENV['APP_ENV'] = 'testing';
$_ENV['MAIL_MAILER'] = 'array';

$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = ':memory:';
$_SERVER['APP_ENV'] = 'testing';
$_SERVER['MAIL_MAILER'] = 'array';

// Load the standard Composer autoloader
require_once __DIR__.'/../vendor/autoload.php';
