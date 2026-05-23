<?php

namespace TrackAnyDevice\SsoServer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the authenticated user's profile for the SSO token exchange.
 *
 * Called by SsoProvider::getUserByToken() after Socialite exchanges the
 * authorization code for a Passport access token:
 *
 *   GET /api/sso/user
 *   Authorization: Bearer {passport_access_token}
 *
 * The response is mapped by SsoProvider::mapUserToObject() to a
 * Socialite User with id, name, and email.
 */
class SsoUserController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role?->value,
        ]);
    }
}
