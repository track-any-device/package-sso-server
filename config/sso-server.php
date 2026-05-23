<?php

return [
    /*
     * Which application surface is running.
     * Migrations are only loaded on the 'core' surface.
     * Set APP_SURFACE in your .env to one of: core | login | my | admin | tenant
     */
    'surface' => env('APP_SURFACE', 'core'),

    /*
     * Root domain used to derive sub-domain URLs (tenant subdomains, my., etc.).
     * Falls back to parsing APP_URL if APP_DOMAIN is not set.
     */
    'app_domain' => env('APP_DOMAIN', 'track-any-device.com'),

    /*
     * Hostname of the end-user "my" app.  Defaults to my.{app_domain}.
     */
    'my_domain' => env('MY_DOMAIN'),

    /*
     * Hostname of the dedicated identity / login surface.
     * When null the current host is assumed to serve the login routes.
     */
    'login_domain' => env('LOGIN_DOMAIN'),
];
