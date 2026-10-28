<?php

declare(strict_types=1);

use Config\Logger;

/*
 * Boots CodeIgniter the way PHPUnit does for the fuzz targets, with the test base URL.
 * It installs no error handler: php-fuzzer installs its own after this file runs, and that one turns
 * every warning and notice into a crash.
 *
 * The scripts load it through auto_prepend_file, before the fuzzer instruments anything: the
 * instrumentation breaks the framework's own Config\Logger, whose property default is a ternary.
 */

$root = dirname(__DIR__, 2);

// The framework's test bootstrap otherwise resolves these from the working directory.
define('HOMEPATH', $root . DIRECTORY_SEPARATOR);
define('CONFIGPATH', $root . '/vendor/codeigniter4/framework/app/Config' . DIRECTORY_SEPARATOR);
define('PUBLICPATH', $root . '/vendor/codeigniter4/framework/public' . DIRECTORY_SEPARATOR);

$_SERVER['app.baseURL'] = 'http://example.com/';

require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

class_exists(Logger::class);
