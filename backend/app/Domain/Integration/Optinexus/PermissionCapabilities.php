<?php

namespace App\Domain\Integration\Optinexus;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Which module or feature a permission belongs to, read from the route gates themselves (`access:<permission>,module=..,feature=..`),
 * the only place where the entitlement requirement of a permission is declared. The manifest sends it as `capability_code`, so the
 * OptiNexus delegable set follows the tenant's entitlements and cannot drift from what the API enforces. A permission that is
 * gated by exactly one feature maps to that feature; one gated by several features of one module maps to the module; a permission
 * that needs no entitlement (access.*, organization.*, account.*, audit.view) or whose routes disagree maps to null.
 */
final class PermissionCapabilities
{
    /** @return array<string,?string> permission code => capability code (null: no single capability) */
    public static function map(): array
    {
        $seen = [];

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'access:')) {
                    continue;
                }

                $permission = null;
                $module = null;
                $feature = null;
                foreach (explode(',', substr($middleware, 7)) as $part) {
                    if (str_starts_with($part, 'module=')) {
                        $module = substr($part, 7);
                    } elseif (str_starts_with($part, 'feature=')) {
                        $feature = substr($part, 8);
                    } elseif ($part !== '') {
                        $permission = $part;
                    }
                }

                if ($permission !== null) {
                    $seen[$permission]['modules'][(string) $module] = true;
                    $seen[$permission]['features'][(string) $feature] = true;
                }
            }
        }

        $map = [];
        foreach ($seen as $permission => $found) {
            $features = array_keys(array_filter($found['features'], fn ($key) => $key !== '', ARRAY_FILTER_USE_KEY));
            $modules = array_keys(array_filter($found['modules'], fn ($key) => $key !== '', ARRAY_FILTER_USE_KEY));
            $ungated = isset($found['modules']['']) || isset($found['features']['']);

            $map[$permission] = match (true) {
                $ungated => null, // some route needs no entitlement, so none can be demanded of the permission
                count($features) === 1 => $features[0],
                count($modules) === 1 => $modules[0],
                default => null,
            };
        }

        ksort($map);

        return $map;
    }
}
