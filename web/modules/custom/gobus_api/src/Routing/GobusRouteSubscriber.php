<?php

namespace Drupal\gobus_api\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters REST resource routes to enforce proper access control.
 *
 * Routes are split into two categories:
 *
 * PUBLIC — Authentication endpoints that must be accessible without a token
 * (login, register, refresh, reset-password, send-code, verify-code).
 * These keep _access: 'TRUE'.
 *
 * PROTECTED — All other endpoints that require a valid OAuth2 token.
 * These use _user_is_logged_in: 'TRUE' which checks $account->id() > 0.
 * We cannot use _permission because Simple OAuth 6.x TokenAuthUser has
 * empty getRoles(), making hasPermission() always fail without scopes.
 * The _user_is_logged_in check works because the user ID IS properly
 * set by the OAuth2 auth provider.
 *
 * Each protected resource also keeps its own isAnonymous() check as
 * defense-in-depth.
 */
class GobusRouteSubscriber extends RouteSubscriberBase
{

    /**
     * Public routes — no token required.
     *
     * These are authentication/onboarding endpoints that must be
     * callable by unauthenticated clients.
     */
    private const PUBLIC_ROUTES = [
        'rest.gobus_auth_login.',
        'rest.gobus_auth_register.',
        'rest.gobus_auth_agent_login.',
        'rest.gobus_auth_agent_register.',
        'rest.gobus_auth_captain_login.',
        'rest.gobus_auth_captain_register.',
        'rest.gobus_auth_refresh.',
        'rest.gobus_auth_reset_password.',
        'rest.gobus_auth_send_code.',
        'rest.gobus_auth_verify_code.',
    ];

    /**
     * Protected routes — valid OAuth2 token required.
     *
     * Drupal's router will reject anonymous requests with 403 before
     * the resource handler is even called.
     */
    private const PROTECTED_ROUTES = [
        'rest.gobus_auth_me.',
        'rest.gobus_auth_logout.',
        'rest.gobus_auth_change_password.',
        'rest.gobus_auth_update_profile.',
        'rest.gobus_api_reload.',
        'rest.gobus_api_client_find.',
        'rest.gobus_api_transactions.',
        'rest.gobus_api_stats.',
    ];

    /**
     * {@inheritdoc}
     */
    protected function alterRoutes(RouteCollection $collection)
    {
        foreach ($collection->all() as $name => $route) {
            // --- PUBLIC routes: anyone can access ---
            if ($this->matchesPrefix($name, self::PUBLIC_ROUTES)) {
                $requirements = $route->getRequirements();
                unset($requirements['_permission']);
                $requirements['_access'] = 'TRUE';
                $route->setRequirements($requirements);
                continue;
            }

            // --- PROTECTED routes: authenticated users only ---
            if ($this->matchesPrefix($name, self::PROTECTED_ROUTES)) {
                $requirements = $route->getRequirements();
                unset($requirements['_permission']);
                unset($requirements['_access']);
                $requirements['_user_is_logged_in'] = 'TRUE';
                $route->setRequirements($requirements);
                continue;
            }
        }
    }

    /**
     * Checks if a route name starts with any of the given prefixes.
     */
    private function matchesPrefix(string $routeName, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }
        return false;
    }

}