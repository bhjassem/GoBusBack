<?php
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'autoload.php';
chdir(__DIR__);
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle($request);

echo "Clearing Drupal caches (Services)...\n";
foreach (\Drupal::getContainer()->getServiceIds() as $id) {
    if (strpos($id, 'cache.') === 0 && $id !== 'cache.backend.apcu') {
        try {
            if (\Drupal::hasService($id)) {
                $service = \Drupal::service($id);
                if ($service instanceof \Drupal\Core\Cache\CacheBackendInterface) {
                    $service->deleteAll();
                }
            }
        } catch (\Exception $e) {}
    }
}
echo "Rebuilding router...\n";
\Drupal::service('router.builder')->rebuild();
echo "Clearing REST plugin cache...\n";
\Drupal::service('plugin.manager.rest')->clearCachedDefinitions();
echo "Resetting OpCache...\n";
if (function_exists('opcache_reset')) {
    opcache_reset();
}
echo "Done.\n";