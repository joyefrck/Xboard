<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserBlacklistEvent;
use Illuminate\Http\Request;

class BlacklistController extends Controller
{
    public function fetch(Request $request)
    {
        $data = $request->validate([
            'search' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|in:all,muted,expired,banned',
            'page' => 'sometimes|integer|min:1',
        ]);
        $now = time();
        $query = User::query()->where(fn ($q) => $q->where('banned', true)
            ->orWhere('ticket_abuse_count', '>', 0)->orWhereNotNull('ticket_muted_until'));
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', '%' . $search . '%');
                if (ctype_digit($search)) $q->orWhere('id', (int) $search);
            });
        }
        match ($data['status'] ?? 'all') {
            'banned' => $query->where('banned', true),
            'muted' => $query->where('banned', false)->where('ticket_muted_until', '>', $now),
            'expired' => $query->where('banned', false)->where('ticket_abuse_count', '>', 0)
                ->where(fn ($q) => $q->whereNull('ticket_muted_until')->orWhere('ticket_muted_until', '<=', $now)),
            default => null,
        };
        $page = $query->select(['id', 'email', 'banned', 'ticket_muted_until', 'ticket_abuse_count'])
            ->withMax('blacklistEvents', 'id')
            ->withMax(['blacklistEvents as latest_restriction_at' => fn ($q) => $q
                ->whereIn('action', ['account_ban', 'ticket_mute'])], 'created_at')
            ->orderByDesc('latest_restriction_at')->orderByDesc('id')
            ->paginate(20);
        $events = UserBlacklistEvent::whereIn('id', $page->getCollection()->pluck('blacklist_events_max_id')->filter())
            ->get(['id', 'reason', 'source', 'created_at', 'operator_id'])->keyBy('id');
        $page->getCollection()->transform(function ($user) use ($events, $now) {
            return [
                'id' => $user->id, 'email' => $user->email, 'banned' => (bool) $user->banned,
                'ticket_muted_until' => $user->ticket_muted_until, 'ticket_abuse_count' => $user->ticket_abuse_count,
                'status' => $user->banned ? 'banned' : ($user->ticket_muted_until > $now ? 'muted' : 'expired'),
                'latest' => $events->get($user->blacklist_events_max_id),
            ];
        });
        return $this->success([
            'data' => $page->items(), 'total' => $page->total(), 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'review' => [
                'enabled' => (bool) config('ticket_moderation.enabled'),
                'configured' => (string) config('ticket_moderation.api_key') !== '',
                'model' => config('ticket_moderation.model'),
            ],
        ]);
    }

    public function detail(Request $request)
    {
        $data = $request->validate(['user_id' => 'required|integer|min:1', 'page' => 'sometimes|integer|min:1']);
        $events = UserBlacklistEvent::where('user_id', $data['user_id'])->orderByDesc('id')->paginate(10);
        return $this->success([
            'data' => $events->items(), 'total' => $events->total(),
            'current_page' => $events->currentPage(), 'last_page' => $events->lastPage(),
        ]);
    }

}
