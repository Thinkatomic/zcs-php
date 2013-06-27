<?php

require_once 'SplClassLoader.php';
//
// ZIMBRA_PASSWORD should be defined in password.inc
require_once 'password.inc';

define('ZIMBRA_LOGIN', 'admin@zimbra.voonami.com');
define('ZIMBRA_SERVER', 'zimbra.voonami.com');
define('ZIMBRA_PORT', '7071');

$classLoader = new SplClassLoader('Zimbra', realpath(__DIR__.'/../'));
$classLoader->register();


$zimbra = new \Zimbra\ZCS\Admin(ZIMBRA_SERVER, ZIMBRA_PORT);
$zimbra->auth(ZIMBRA_LOGIN, ZIMBRA_PASSWORD);

// $accounts = $zimbra->count("bushfam.com", 'name');
// $accounts = $zimbra->count("9465", 'id');

$accounts = $zimbra->getAllAccounts(ZIMBRA_SERVER, "bushfam.com");

var_dump($accounts);

