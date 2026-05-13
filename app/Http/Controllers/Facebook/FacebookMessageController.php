<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\MetaBaseController;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacebookMessageController extends MetaBaseController
{
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        // TODO: delegar a MessageService::send($conversation, $validated['body'], $request->user())
        // El service encola el sendMessageJOB HACIA la graph api

        return $this->jsonSuccess([
            'queued' => true,
            'conversation_id' => $conversation->id,
            'preview' => $validated['body'],
        ], 202);
    }
}
