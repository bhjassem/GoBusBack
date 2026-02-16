<?php

namespace Drupal\gobus_api\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters REST resource routes to remove permission requirements.
 *
 * Simple OAuth 6.x with Drupal 11's PermissionChecker doesn't properly
 * grant permissions to TokenAuthUser objects (the token's getRoles()
 * returns empty when no OAuth2 scopes are configured, making
 * hasPermission() fail even for authenticated users).
 *
 * Instead, we set _access: 'TRUE' on these routes and let the resource
 * handlers perform their own authentication checks internally.
 */
class GobusRouteSubscriber extends RouteSubscriberBase
{

    /**
     * {@inheritdoc}
     */
    protected function alterRoutes(RouteCollection $collection)
    {
        // List of our REST resource route prefixes
        $gobus_routes = [
            'rest.gobus_api_stats.',
            'rest.gobus_api_transactions.',
            'rest.gobus_auth_me.',
            'rest.gobus_auth_logout.',
            'rest.gobus_auth_change_password.',
            'rest.gobus_auth_update_profile.',
            'rest.gobus_auth_refresh.',
        ];

        foreach ($collection->all() as $name => $route) {
            foreach ($gobus_routes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    // Remove the permission requirement so the router doesn't block access
                    $requirements = $route->getRequirements();
                    unset($requirements['_permission']);
                    $requirements['_access'] = 'TRUE';
                    $route->setRequirements($requirements);
                    break;
                }
            }
        }
    }

}