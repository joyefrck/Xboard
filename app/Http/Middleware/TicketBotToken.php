<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TicketBotToken
{
    public function handle(Request $request, Closure $next)
    {
        $digest = (string) config('ticket_bot.token_sha256');
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            return response()->json(['message' => '工单 Bot API 未启用'], 403);
        }

        $token = $request->bearerToken();
        if (!$token || !hash_equals($digest, hash('sha256', $token))) {
            return response()->json(['message' => '工单 Bot 凭据无效'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
