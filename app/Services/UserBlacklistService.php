<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserBlacklistEvent;
use Illuminate\Support\Facades\DB;

class UserBlacklistService
{
    public const MUTE_SECONDS = 7 * 86400;

    public function assertCanSubmit(User $user): void
    {
        if ($user->banned) {
            throw new ApiException('账号已被封禁', 403);
        }
        if ((int) $user->ticket_muted_until > time()) {
            throw new ApiException($this->muteMessage((int) $user->ticket_muted_until), 403);
        }
    }

    public function muteMessage(int $until): string
    {
        $date = (new \DateTimeImmutable('@' . $until))->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        return '因辱骂或恶意攻击，工单已禁言至 ' . $date->format('Y-m-d H:i') . '（北京时间）。解禁后再次违规将永久封禁账号。';
    }

    /** Caller holds the user row lock. Return after commit before reporting the violation. */
    public function punish(User $user, array $review, array $snapshot): string
    {
        $this->assertCanSubmit($user);
        $repeat = (int) $user->ticket_abuse_count > 0;
        $user->ticket_abuse_count = (int) $user->ticket_abuse_count + 1;
        if ($repeat) {
            $user->banned = true;
        } else {
            $user->ticket_muted_until = time() + self::MUTE_SECONDS;
        }
        $user->saveOrFail();
        if ($repeat) {
            (new AuthService($user))->removeAllSessions();
        }
        $this->record($user, $repeat ? 'account_ban' : 'ticket_mute', 'ai', $review['reason'], null,
            array_merge($snapshot, [
                'category' => $review['category'], 'evidence' => $review['evidence'],
                'model' => $review['model'], 'muted_until' => $repeat ? null : $user->ticket_muted_until,
            ]));
        return $repeat ? '工单解禁后再次辱骂或恶意攻击，账号已被永久封禁。' : $this->muteMessage($user->ticket_muted_until);
    }

    public function manualBan(int $userId, string $reason, int $operatorId): void
    {
        DB::transaction(function () use ($userId, $reason, $operatorId) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            if (!$user->banned) {
                $user->banned = true;
                $user->saveOrFail();
                $this->record($user, 'account_ban', 'manual', $reason, $operatorId);
            }
            // Also revoke sessions for accounts banned through legacy paths.
            (new AuthService($user))->removeAllSessions();
        });
    }

    public function manualUnban(int $userId, int $operatorId): void
    {
        DB::transaction(function () use ($userId, $operatorId) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($user->banned) {
                $user->banned = false;
                $user->saveOrFail();
                $this->record($user, 'account_unban', 'manual', '管理员解除账号封禁', $operatorId);
            }
        });
    }

    public function setAccountStatus(int $userId, string $status, int $operatorId): void
    {
        if (!in_array($status, ['normal', 'banned', 'ticket_muted'], true)) {
            throw new ApiException('无效的账户状态', 422);
        }
        DB::transaction(function () use ($userId, $status, $operatorId) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($status === 'banned') {
                $this->manualBan($userId, '管理员在用户管理中手动封禁', $operatorId);
                return;
            }
            $this->manualUnban($userId, $operatorId);
            $user->refresh();
            if ($status === 'ticket_muted') {
                // Editing other fields or resubmitting the same status must not renew the penalty.
                if ((int) $user->ticket_muted_until > time()) return;
                $user->ticket_muted_until = time() + self::MUTE_SECONDS;
                $user->ticket_abuse_count = max(1, (int) $user->ticket_abuse_count);
                $user->saveOrFail();
                $this->record($user, 'ticket_mute', 'manual', '管理员在用户管理中设置工单禁言7天', $operatorId,
                    ['muted_until' => $user->ticket_muted_until]);
            } elseif ($user->ticket_muted_until !== null) {
                $wasMuted = (int) $user->ticket_muted_until > time();
                $user->ticket_muted_until = null;
                $user->saveOrFail();
                if ($wasMuted) {
                    $this->record($user, 'ticket_unmute', 'manual', '管理员在用户管理中解除工单禁言', $operatorId);
                }
            }
        });
    }

    private function record(User $user, string $action, string $source, string $reason, ?int $operatorId, array $snapshot = []): void
    {
        UserBlacklistEvent::create(array_merge($snapshot, [
            'user_id' => $user->id, 'email' => $user->email, 'action' => $action,
            'source' => $source, 'operator_id' => $operatorId, 'reason' => $reason,
        ]));
    }
}
