<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\MetaBaseController;
use App\Services\Meta\FacebookAccountService;
use App\Services\Meta\FacebookAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacebookAuthController extends MetaBaseController
{
    public function __construct(
        private FacebookAuthService $authService,
        private FacebookAccountService $accountService,
    ) {}

    public function authUrl(): JsonResponse
    {
        $appId = (string) config('services.meta.app_id');
        $redirectUri = (string) config('services.meta.facebook.redirect_uri');
        $permissions = (string) env('FACEBOOK_PERMISSIONS', 'pages_show_list,pages_messaging,pages_read_engagement,pages_manage_metadata');

        if ($appId === '' || $redirectUri === '') {
            return $this->jsonError('META_APP_ID or FACEBOOK_REDIRECT_URI is not configured.', 500);
        }

        $url = 'https://www.facebook.com/dialog/oauth?'.http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'scope' => $permissions,
            'response_type' => 'code',
        ]);

        return $this->jsonSuccess(['auth_url' => $url]);
    }

    public function callback(Request $request): JsonResponse
    {
        $error = (string) $request->query('error', '');
        if ($error !== '') {
            return $this->jsonError('Authorization canceled by user.', 400);
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $this->jsonError('Missing OAuth code.', 422);
        }

        $shortToken = $this->authService->exchangeCodeForToken($code);
        if ($shortToken === null || $shortToken === '') {
            return $this->jsonError('Failed to exchange code for token.', 502);
        }

        $longToken = $this->authService->exchangeForLongLivedToken($shortToken);
        if ($longToken === null || $longToken === '') {
            return $this->jsonError('Failed to exchange long-lived token.', 502);
        }

        $pages = $this->accountService->fetchPages($longToken);

        return $this->jsonSuccess([
            'access_token' => $longToken,
            'pages' => $pages,
        ]);
    }
}
