<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Scoped credential for the AI assistant: node automation only, never admin session power. */
class NodeAutomationBotToken
{
    public function handle(Request $request, Closure $next)
    {
        $digest = (string) config('node_automation.bot_token_sha256');
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            return response()->json(['message' => '节点自动化 Bot API 未启用'], 403);
        }

        $token = $request->bearerToken();
        if (!$token || !hash_equals($digest, hash('sha256', $token))) {
            return response()->json(['message' => '节点自动化 Bot 凭据无效'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
