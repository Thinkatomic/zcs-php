<?php

require_once 'SplClassLoader.php';
$classLoader = new SplClassLoader('Zimbra', realpath(__DIR__.'/../'));
$classLoader->register();

define('ZIMBRA_LOGIN', 'admin@zimbra.voonami.com');
define('ZIMBRA_PASSWORD', '29rL6E6TTldxAloa');
define('ZIMBRA_SERVER', 'zimbra.voonami.com');
define('ZIMBRA_PORT', '7071');

$zimbra = new \Zimbra\ZCS\Admin(ZIMBRA_SERVER, ZIMBRA_PORT);
$zimbra->auth(ZIMBRA_LOGIN, ZIMBRA_PASSWORD);

$accounts = $zimbra->count("bushfam.com", 'name');
// $accounts = $zimbra->count("9465", 'id');


var_dump($accounts);
