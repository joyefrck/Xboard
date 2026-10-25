<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\NodeAutomationSetting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AccountDeletionService
{
    public function delete(int $userId, string $password): void
    {
        [$user, $messageIds] = DB::transaction(function () use ($userId, $password) {
            // Order creation and traffic consumption also lock this row.
            $user = User::whereKey($userId)->lockForUpdate()->first();
            if (!$user) {
                throw new ApiException('账号不存在或已注销', 403);
            }
            if (!Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password)) {
                throw new ApiException('当前密码错误，账号未注销');
            }

            $ticketIds = Ticket::where('user_id', $userId)->orderBy('id')->lockForUpdate()->pluck('id');
            $messages = TicketMessage::whereIn('ticket_id', $ticketIds)->orWhere('user_id', $userId);
            $messageIds = (clone $messages)->pluck('id')->all();
            $messages->delete();
            Ticket::whereIn('id', $ticketIds)->delete();
            // Attachment metadata remains a durable outbox until file deletion succeeds after commit.

            $invitationIds = DB::table('v2_telegram_group_invitations')->where('user_id', $userId)->select('id');
            DB::table('v2_telegram_group_requests')->whereIn('invitation_id', $invitationIds)->delete();

            foreach ([
                'v2_user_traffic_packages', 'v2_traffic_reset_logs', 'v2_stat_user',
                'v2_invite_code', 'v2_gift_card_usage', 'v2_telegram_group_entitlements',
                'v2_telegram_group_invitations', 'v2_user_blacklist_event',
            ] as $table) {
                DB::table($table)->where('user_id', $userId)->delete();
            }
            DB::table('v2_commission_log')->where('user_id', $userId)->orWhere('invite_user_id', $userId)->delete();
            $user->orders()->delete();
            $user->tokens()->delete();
            DB::table('v2_mail_log')->where('email', $user->email)->delete();

            // Detach references without deleting another user's account, order or redeemed card.
            DB::table('v2_user')->where('invite_user_id', $userId)->update(['invite_user_id' => null]);
            DB::table('v2_order')->where('invite_user_id', $userId)->update(['invite_user_id' => null]);
            DB::table('v2_gift_card_usage')->where('invite_user_id', $userId)->update(['invite_user_id' => null]);
            DB::table('v2_gift_card_code')->where('user_id', $userId)->update(['user_id' => null]);
            DB::table('v2_plan')->where('owner_user_id', $userId)->update([
                'owner_user_id' => null, 'show' => false, 'renew' => false,
            ]);
            foreach (NodeAutomationSetting::orderBy('id')->lockForUpdate()->get() as $setting) {
                $probes = $setting->probe_users_by_group ?? [];
                $remaining = array_filter($probes, fn ($id) => (int) $id !== $userId);
                if ($remaining !== $probes || (int) $setting->probe_user_id === $userId) {
                    $setting->probe_users_by_group = $remaining;
                    if ((int) $setting->probe_user_id === $userId) $setting->probe_user_id = null;
                    $setting->saveOrFail();
                }
            }

            if (!$user->delete()) {
                throw new \RuntimeException('Account deletion failed');
            }
            return [$user, $messageIds];
        }, 3);

        // A failed external cleanup must not report a committed deletion as a failure.
        try {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $user->email));
            Cache::forget(CacheKey::get('USER_SESSIONS', $userId));
            if ($user->telegram_id) Cache::forget('telegram-binding-account:' . $userId . ':' . $user->telegram_id);
        } catch (Throwable $error) {
            Log::warning('Account deletion cache cleanup failed', ['error_type' => get_class($error)]);
        }
        try {
            app(TicketRetentionService::class)->prunePendingAttachments($messageIds);
        } catch (Throwable $error) {
            Log::warning('Account deletion attachment cleanup will retry', ['error_type' => get_class($error)]);
        }
    }
}
