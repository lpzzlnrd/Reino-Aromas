<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\MetaBaseController;
use App\Jobs\ProcessMetaWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends MetaBaseController
{
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $expected = (string) config('services.meta.webhook_verify_token');

        if ($mode === 'subscribe' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        if (! $this->isValidSignature($request)) {
            Log::channel('stack')->warning('Meta webhook rejected: invalid signature');

            return response()->json(['status' => 'invalid_signature'], 403);
        }

        ProcessMetaWebhookJob::dispatch($request->all());

        return response()->json(['status' => 'queued'], 202);
    }

    private function isValidSignature(Request $request): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $appSecret = (string) config('services.meta.app_secret');

        if ($signature === '' || $appSecret === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
