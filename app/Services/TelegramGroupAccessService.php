<?php

namespace App\Services;

use App\Models\User;
use App\Models\TelegramGroupInvitation as Invitation;
use App\Models\TelegramGroupRequest as JoinRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramGroupAccessService
{
    public const INVITE_TTL = 600;
    public const MAX_ATTEMPTS = 5;

    public function __construct(private TelegramGroupEligibilityService $eligibility) {}

    public function chatId(): int
    {
        $value = (string) admin_setting('telegram_discuss_id', '');
        return preg_match('/^-\d{1,18}$/D', $value) ? (int) $value : 0;
    }

    public function enabled(): bool
    {
        return (bool) admin_setting('telegram_group_access_enable', false)
            && (bool) admin_setting('telegram_bot_enable', false) && $this->chatId() < 0;
    }

    public function status(User $user): array
    {
        if ($user->banned) return ['state' => 'blocked'];
        if (!$this->enabled()) return ['state' => 'unavailable'];
        if (!$this->eligibility->hasEntitlement($user)) return ['state' => 'ineligible'];
        if (!$user->telegram_id) return ['state' => 'binding_required'];
        if (User::where('telegram_id', $user->telegram_id)->count() !== 1) return ['state' => 'binding_conflict'];
        if (Invitation::where('user_id', $user->id)->whereNotNull('used_at')
            ->where('telegram_id', '!=', $user->telegram_id)->exists()) return ['state' => 'binding_conflict'];
        return ['state' => 'eligible'];
    }

    public function checkConfiguration(?int $chatId = null): array
    {
        $chatId ??= $this->chatId();
        if ($chatId >= 0) throw new \RuntimeException('请先填写正确的 Telegram 群 ID');
        $bot = app(TelegramService::class);
        $chat = $bot->getChat($chatId)->result;
        $me = $bot->getMe()->result;
        $member = $bot->getChatMember($chatId, (int) $me->id)->result;
        $hook = $bot->getWebhookInfo()->result;
        $expectedUrl = rtrim((string) admin_setting('app_url', ''), '/') . '/api/v1/guest/telegram/webhook';
        $actualUrl = explode('?', (string) ($hook->url ?? ''), 2)[0];
        parse_str((string) parse_url((string) ($hook->url ?? ''), PHP_URL_QUERY), $hookQuery);
        $updates = $hook->allowed_updates ?? null;
        $webhookReady = $actualUrl === $expectedUrl
            && hash_equals(md5((string) admin_setting('telegram_bot_token', '')), (string) ($hookQuery['access_token'] ?? ''))
            && (empty($updates) || in_array('chat_join_request', $updates, true));
        $private = in_array($chat->type ?? '', ['group', 'supergroup'], true) && empty($chat->username);
        $rights = ($member->status ?? '') === 'administrator' && !empty($member->can_invite_users);
        return [
            'ready' => $private && $rights && $webhookReady,
            'private_group' => $private, 'bot_can_approve' => $rights, 'webhook_ready' => $webhookReady,
            'chat_id' => (string) $chatId,
            'message' => '还需人工确认旧免审邀请已撤销、普通成员不能直接拉人。',
        ];
    }

    public static function isMember(object $member): bool
    {
        return in_array($member->status ?? '', ['creator', 'administrator', 'member'], true)
            || (($member->status ?? '') === 'restricted' && !empty($member->is_member));
    }

    public function issue(User $user): array
    {
        return Cache::lock('telegram-group-user:' . $user->id, 240)->block(5, function () use ($user) {
            $user->refresh();
            $status = $this->status($user);
            if ($status['state'] !== 'eligible') return $status;
            $chatId = $this->chatId();
            $bot = app(TelegramService::class);
            $member = $bot->getChatMember($chatId, (int) $user->telegram_id)->result;
            if (self::isMember($member)) return ['state' => 'already_member'];
            if (($member->status ?? '') === 'kicked') return ['state' => 'blocked'];
            // Never silently reuse a link belonging to a different Telegram identity or target group.
            $invite = Invitation::where('user_id', $user->id)->where('telegram_id', $user->telegram_id)
                ->where('chat_id', $chatId)->whereNull('used_at')->whereNull('revoked_at')
                ->where('expires_at', '>', time() + 30)->latest('id')->first();
            if (!$invite) {
                $configurationKey = hash('sha256', $chatId . '|' . admin_setting('telegram_bot_token', '') . '|' . admin_setting('app_url', ''));
                $ready = Cache::remember('telegram-group-ready:' . $configurationKey, 60, fn() => $this->checkConfiguration($chatId));
                if (!$ready['ready']) return ['state' => 'unavailable'];
                $expiresAt = time() + self::INVITE_TTL;
                $result = $bot->createChatInviteLink($chatId, $expiresAt)->result;
                $link = (string) ($result->invite_link ?? '');
                if (!preg_match('~^https://t\.me/(?:\+|joinchat/)[A-Za-z0-9_-]+$~D', $link)
                    || empty($result->creates_join_request)) {
                    throw new \RuntimeException('Invalid Telegram invitation');
                }
                try {
                    $invite = Invitation::create([
                        'user_id' => $user->id, 'telegram_id' => $user->telegram_id, 'chat_id' => $chatId,
                        'invite_link' => $link, 'link_hash' => hash('sha256', $link), 'expires_at' => $expiresAt,
                    ]);
                } catch (\Throwable $e) {
                    try { $bot->revokeChatInviteLink($chatId, $link); } catch (\Throwable) {}
                    throw new \RuntimeException('Unable to persist Telegram invitation');
                }
            }
            return ['state' => 'ready', 'invite_link' => $invite->invite_link, 'expires_at' => $invite->expires_at];
        });
    }

    public function record(array $update): ?JoinRequest
    {
        $data = $update['chat_join_request'] ?? null;
        if (!$this->enabled() || !is_array($data) || !isset($update['update_id'])) return null;
        if ((int) ($data['chat']['id'] ?? 0) !== $this->chatId()) return null;
        if ((int) ($data['from']['id'] ?? 0) <= 0 || (int) ($data['date'] ?? 0) <= 0) return null;
        $link = (string) ($data['invite_link']['invite_link'] ?? '');
        $invite = $link !== '' ? Invitation::where('link_hash', hash('sha256', $link))->first() : null;
        return JoinRequest::firstOrCreate(['update_id' => (int) $update['update_id']], [
            'invitation_id' => $invite?->id, 'telegram_id' => (int) $data['from']['id'],
            'chat_id' => (int) $data['chat']['id'], 'requested_at' => (int) $data['date'],
        ]);
    }

    public function process(int $requestId): void
    {
        $request = JoinRequest::find($requestId);
        if (!$request || !$this->enabled() || (int) $request->chat_id !== $this->chatId()) return;
        $invitation = $request->invitation_id ? Invitation::find($request->invitation_id) : null;
        $key = $invitation ? 'telegram-group-user:' . $invitation->user_id : 'telegram-group-applicant:' . $request->telegram_id;
        Cache::lock($key, 240)->block(5, function () use ($request) {
            $request->refresh();
            if ($request->status !== 'pending' || $request->next_attempt_at > time()) return;
            if ($request->attempts >= self::MAX_ATTEMPTS) {
                $request->update(['status' => 'failed', 'last_error' => 'retry_limit_reached']);
                return;
            }
            $request->increment('attempts');
            try {
                $invite = $request->invitation_id ? Invitation::find($request->invitation_id) : null;
                $user = $invite ? User::find($invite->user_id) : null;
                $valid = $invite && $user && !$invite->revoked_at && !$invite->used_at
                    && (int) $invite->chat_id === (int) $request->chat_id
                    && (int) $invite->telegram_id === (int) $request->telegram_id
                    && (int) $user->telegram_id === (int) $request->telegram_id
                    && $request->requested_at >= (int) $invite->created_at
                    && $request->requested_at <= $invite->expires_at
                    && $request->requested_at <= time() + 60
                    && $this->status($user)['state'] === 'eligible';
                $bot = app(TelegramService::class);
                // Telegram may have accepted an earlier call whose response was lost.
                if ($invite && $user && (int) $invite->chat_id === (int) $request->chat_id
                    && (int) $invite->telegram_id === (int) $request->telegram_id
                    && self::isMember($bot->getChatMember((int) $request->chat_id, (int) $request->telegram_id)->result)) {
                    $this->markApproved($request, $invite);
                    $this->revoke($invite);
                    return;
                }
                if ($valid) {
                    $bot->approveChatJoinRequest((int) $request->chat_id, (int) $request->telegram_id);
                    $this->markApproved($request, $invite);
                    $this->revoke($invite);
                } else {
                    $bot->declineChatJoinRequest((int) $request->chat_id, (int) $request->telegram_id);
                    $request->update(['status' => 'declined', 'last_error' => 'eligibility_or_invitation_mismatch']);
                }
            } catch (\Throwable) {
                // No raw Telegram exception, token, or invite URL is persisted in queue payloads/logs.
                $request->update([
                    'status' => $request->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'next_attempt_at' => time() + min(900, 30 * (2 ** $request->attempts)),
                    'last_error' => 'telegram_or_storage_unavailable',
                ]);
            }
        });
    }

    private function markApproved(JoinRequest $request, Invitation $invite): void
    {
        DB::transaction(function () use ($request, $invite) {
            $request->update(['status' => 'approved', 'last_error' => null]);
            $invite->update(['used_at' => $invite->used_at ?: time()]);
        });
    }

    public function revoke(Invitation $invite): void
    {
        if ($invite->revoked_at) return;
        try {
            app(TelegramService::class)->revokeChatInviteLink((int) $invite->chat_id, $invite->invite_link);
            $invite->update(['revoked_at' => time()]);
        } catch (\Throwable) {
            // used_at prevents reuse; the recovery command retries revocation separately.
        }
    }
}
