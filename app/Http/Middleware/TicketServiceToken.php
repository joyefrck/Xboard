<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TicketServiceToken
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        $operators = config('ticket_bot.operators');
        abort_unless(is_array($operators) && $operators, 403, 'service_actions_disabled');
        $token = $request->bearerToken();
        abort_unless($token, 401, 'invalid_service_token');
        $digest = hash('sha256', $token);
        abort_if(hash_equals((string) config('ticket_bot.token_sha256'), $digest), 403, 'scope_denied');

        $matches = [];
        foreach ($operators as $actor => $operator) {
            if (is_string($actor) && preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $actor)
                && is_array($operator) && hash_equals((string) ($operator['sha256'] ?? ''), $digest)) {
                $matches[$actor] = $operator['scopes'] ?? [];
            }
        }
        abort_unless(count($matches) === 1, 401, 'invalid_service_token');
        $scopes = reset($matches);
        abort_unless(is_array($scopes) && in_array($scope, $scopes, true), 403, 'scope_denied');
        $request->attributes->set('ticket_service_actor', array_key_first($matches));
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
