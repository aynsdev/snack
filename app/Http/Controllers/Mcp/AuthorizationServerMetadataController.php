<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Server\Registrar;

/**
 * The OAuth authorization server metadata (RFC 8414) an MCP client reads to
 * learn how to sign a person in.
 *
 * laravel/mcp publishes this document too; this replaces it to add the one
 * field it leaves out: `token_endpoint_auth_methods_supported`. RFC 8414 says
 * a missing value means `client_secret_basic` only — but every client that
 * registers here is a public client authenticating with `none` and PKCE (see
 * OAuthRegisterController), so without the field the document contradicts
 * the registration it offers, and a strict client like Claude gives up.
 */
class AuthorizationServerMetadataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'issuer' => config('mcp.authorization_server') ?? url('/'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => url('oauth/register'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
        ]);
    }
}
