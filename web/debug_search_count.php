<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user\Entity\User;

$autoloader = require_once 'autoload.php';
chdir(__DIR__);
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();

$query_str = '50';

echo "Analyzing users matching phone/name/account containing '$query_str'...\n\n";

$storage = \Drupal::entityTypeManager()->getStorage('user');
$query = $storage->getQuery()->accessCheck(FALSE);

$group = $query->orConditionGroup()
    ->condition('field_full_name', $query_str, 'CONTAINS')
    ->condition('field_phone', $query_str, 'CONTAINS')
    ->condition('field_account_id', $query_str, 'CONTAINS');

$query->condition($group);
// No range limit for debug
$uids = $query->execute();

echo "Total raw matches in DB: " . count($uids) . "\n";

if (!empty($uids)) {
    $users = User::loadMultiple($uids);
    $clients = 0;
    $others = 0;

    echo str_pad("ID", 5) . " | " . str_pad("Role", 10) . " | Phone\n";
    echo "--------------------------------\n";

    foreach ($users as $u) {
        if ($u->id() == 0)
            continue; // Skip anonymous

        $is_client = $u->hasRole('client');
        $roles = implode(',', $u->getRoles(true)); // exclude authenticated
        $phone = $u->hasField('field_phone') ? $u->get('field_phone')->getString() : '';

        if ($is_client)
            $clients++;
        else
            $others++;

        // Only print first 20 to avoid spam
        if ($clients + $others <= 20) {
            echo str_pad($u->id(), 5) . " | " . str_pad($roles, 10) . " | $phone\n";
        }
    }

    echo "...\n";
    echo "Total Clients: $clients\n";
    echo "Total Others: $others\n";
}