<?php
// Set secrets in server environment, or in a PHP file OUTSIDE the public web directory.
require_once __DIR__ . '/env.php';
define('OMISE_ENABLED', restaurant_env('OMISE_ENABLED') === '1');
define('OMISE_MODE', restaurant_env('OMISE_MODE') ?: 'test');
define('OMISE_SECRET_KEY', restaurant_env('OMISE_SECRET_KEY') ?: '');
define('OMISE_WEBHOOK_SECRET', restaurant_env('OMISE_WEBHOOK_SECRET') ?: '');

