<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user\Entity\User;

$autoloader = require_once 'autoload.php';
chdir(__DIR__);
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();

$uids = [2, 116];
$users = User::loadMultiple($uids);

foreach ($users as $u) {
    echo "ID: " . $u->id() . "\n";
    echo "Name: " . $u->get('field_full_name')->getString() . "\n";
    echo "Phone: " . $u->get('field_phone')->getString() . "\n";
    echo "Account: " . $u->get('field_account_id')->getString() . "\n";
    echo "----------------\n";
}