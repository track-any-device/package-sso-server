<?php

return [
    /*
     * Which application surface is running.
     * Migrations are only loaded on the 'core' surface.
     * Set APP_SURFACE in your .env to one of: core | login | my | admin | tenant
     */
    'surface' => env('APP_SURFACE', 'core'),

    /*
     * Root domain, e.g. track-any-device.com.
     */
    'app_domain' => env('APP_DOMAIN', 'track-any-device.com'),

    /*
     * Central website domain — the Next.js app on Cloudflare Pages.
     * The /my portal lives at {central_domain}/my (not a separate subdomain).
     */
    'central_domain' => env('CENTRAL_DOMAIN', 'track-any-device.com'),

    /*
     * Hostname of the Filament admin panel.  Defaults to null (falls back to /admin on current host).
     */
    'admin_domain' => env('ADMIN_DOMAIN'),

    /*
     * Hostname of the dedicated identity / login surface.
     * When null the current host is assumed to serve the login routes.
     */
    'login_domain' => env('LOGIN_DOMAIN'),
];
