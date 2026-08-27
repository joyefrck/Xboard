<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficPackage;
use App\Models\User;
use App\Models\UserTrafficPackage;
use Illuminate\Database\Eloquent\Collection;

class TrafficPackageService
{
    private const BYTES_PER_GB = 1073741824;

    public function getAvailablePackages(): Collection
    {
        return TrafficPackage::where('show', true)
            ->where('sell', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    public function createFromOrder(Order $order, User $user, TrafficPackage $trafficPackage): UserTrafficPackage
    {
        $totalBytes = (int) ($trafficPackage->transfer_enable * self::BYTES_PER_GB);

        $package = UserTrafficPackage::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'plan_id' => null,
            'traffic_package_id' => $trafficPackage->id,
            'total_bytes' => $totalBytes,
            'remaining_bytes' => $totalBytes,
            'status' => UserTrafficPackage::STATUS_ACTIVE,
        ]);

        $this->syncAccessProfile($user);
        return $package;
    }

    public function createFromLegacyPlanOrder(Order $order, User $user, Plan $plan): UserTrafficPackage
    {
        $totalBytes = (int) ($plan->transfer_enable * self::BYTES_PER_GB);

        $package = UserTrafficPackage::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'plan_id' => $plan->id,
            'traffic_package_id' => null,
            'total_bytes' => $totalBytes,
            'remaining_bytes' => $totalBytes,
            'status' => UserTrafficPackage::STATUS_ACTIVE,
        ]);

        $this->syncAccessProfile($user);
        return $package;
    }

    public function grantByAdmin(
        User $user,
        TrafficPackage $trafficPackage,
        int $amountGb
    ): UserTrafficPackage {
        $maxAmountGb = intdiv(PHP_INT_MAX, self::BYTES_PER_GB);
        if ($amountGb < 1 || $amountGb > $maxAmountGb) {
            throw new \InvalidArgumentException('Traffic package grant is outside the supported range.');
        }

        $totalBytes = $amountGb * self::BYTES_PER_GB;
        $package = UserTrafficPackage::create([
            'user_id' => $user->id,
            'order_id' => null,
            'plan_id' => null,
            'traffic_package_id' => $trafficPackage->id,
            'total_bytes' => $totalBytes,
            'remaining_bytes' => $totalBytes,
            'status' => UserTrafficPackage::STATUS_ACTIVE,
        ]);

        $this->syncAccessProfile($user);
        return $package;
    }

    public function hasActivePlan(User $user): bool
    {
        $planTransferEnable = (int) ($user->transfer_enable ?? 0);

        return !$user->banned
            && $user->plan_id !== null
            && $planTransferEnable > 0
            && ($user->expired_at === null || (int) $user->expired_at > time());
    }

    public function hasUsablePlanBalance(User $user): bool
    {
        return $this->getActivePlanRemainingBytes($user) > 0;
    }

    public function hasActivePackageBalance(int $userId): bool
    {
        return UserTrafficPackage::where('user_id', $userId)
            ->where('status', UserTrafficPackage::STATUS_ACTIVE)
            ->where('remaining_bytes', '>', 0)
            ->exists();
    }

    public function getRemainingBytes(int $userId): int
    {
        return (int) UserTrafficPackage::where('user_id', $userId)
            ->where('status', UserTrafficPackage::STATUS_ACTIVE)
            ->where('remaining_bytes', '>', 0)
            ->sum('remaining_bytes');
    }

    public function getActiveTotalBytes(int $userId): int
    {
        return (int) UserTrafficPackage::where('user_id', $userId)
            ->where('status', UserTrafficPackage::STATUS_ACTIVE)
            ->where('remaining_bytes', '>', 0)
            ->sum('total_bytes');
    }

    public function consume(int $userId, int $uploadBytes, int $downloadBytes): array
    {
        $remainingUpload = max(0, $uploadBytes);
        $remainingDownload = max(0, $downloadBytes);
        $planUpload = 0;
        $planDownload = 0;
        $packageUpload = 0;
        $packageDownload = 0;
        $planAvailable = 0;

        $user = User::where('id', $userId)
            ->lockForUpdate()
            ->first();

        if ($user) {
            $planAvailable = $this->getActivePlanRemainingBytes($user);

            $planUpload = min($planAvailable, $remainingUpload);
            $planAvailable -= $planUpload;
            $remainingUpload -= $planUpload;

            $planDownload = min($planAvailable, $remainingDownload);
            $planAvailable -= $planDownload;
            $remainingDownload -= $planDownload;
        }

        if ($remainingUpload <= 0 && $remainingDownload <= 0) {
            if ($user) {
                $this->syncAccessProfile($user, null, $planAvailable);
            }

            return [
                'package_upload' => $packageUpload,
                'package_download' => $packageDownload,
                'plan_upload' => $planUpload,
                'plan_download' => $planDownload,
            ];
        }

        $packages = UserTrafficPackage::with(['trafficPackage', 'plan'])
            ->where('user_id', $userId)
            ->where('status', UserTrafficPackage::STATUS_ACTIVE)
            ->where('remaining_bytes', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($packages as $package) {
            if ($remainingUpload <= 0 && $remainingDownload <= 0) {
                break;
            }

            $available = (int) $package->remaining_bytes;
            $usedUpload = min($available, $remainingUpload);
            $available -= $usedUpload;
            $remainingUpload -= $usedUpload;
            $packageUpload += $usedUpload;

            $usedDownload = min($available, $remainingDownload);
            $available -= $usedDownload;
            $remainingDownload -= $usedDownload;
            $packageDownload += $usedDownload;

            $package->remaining_bytes = $available;
            if ($available <= 0) {
                $package->status = UserTrafficPackage::STATUS_DEPLETED;
                $package->depleted_at = time();
            }
            $package->save();
        }

        if ($user) {
            $this->syncAccessProfile($user, $packages, $planAvailable);
        }

        return [
            'package_upload' => $packageUpload,
            'package_download' => $packageDownload,
            'plan_upload' => $planUpload + ($user ? 0 : $remainingUpload),
            'plan_download' => $planDownload + ($user ? 0 : $remainingDownload),
        ];
    }

    private function getActivePlanRemainingBytes(User $user): int
    {
        if (!$this->hasActivePlan($user)) {
            return 0;
        }

        $planTransferEnable = (int) ($user->transfer_enable ?? 0);
        if ($planTransferEnable <= 0) {
            return 0;
        }

        $planUsedTraffic = (int) ($user->u + $user->d);
        return max(0, $planTransferEnable - $planUsedTraffic);
    }

    private function getFirstActivePackage(User $user, ?Collection $packages = null): ?UserTrafficPackage
    {
        if ($packages !== null) {
            return $packages->first(fn(UserTrafficPackage $package): bool =>
                $package->status === UserTrafficPackage::STATUS_ACTIVE
                && (int) $package->remaining_bytes > 0
            );
        }

        return UserTrafficPackage::with(['trafficPackage', 'plan'])
            ->where('user_id', $user->id)
            ->where('status', UserTrafficPackage::STATUS_ACTIVE)
            ->where('remaining_bytes', '>', 0)
            ->orderBy('id')
            ->first();
    }

    public function syncAccessProfile(
        User $user,
        ?Collection $packages = null,
        ?int $planRemainingBytes = null
    ): void {
        if ($user->banned) {
            return;
        }

        $planRemainingBytes ??= $this->getActivePlanRemainingBytes($user);
        $source = null;

        if ($planRemainingBytes > 0 && $user->plan_id) {
            $source = Plan::find($user->plan_id);
        } else {
            $package = $this->getFirstActivePackage($user, $packages);
            $source = $package?->trafficPackage ?? $package?->plan;
            $source ??= $user->plan_id ? Plan::find($user->plan_id) : null;
        }

        $attributes = $source ? [
            'group_id' => $source->group_id,
            'speed_limit' => $source->speed_limit,
            'device_limit' => $source->device_limit,
        ] : [
            'group_id' => null,
            'speed_limit' => null,
            'device_limit' => null,
        ];

        $user->fill($attributes);
        $dirtyAttributes = array_intersect_key($user->getDirty(), $attributes);
        if ($dirtyAttributes) {
            User::whereKey($user->getKey())->update($dirtyAttributes);
            $user->syncOriginalAttributes(array_keys($dirtyAttributes));
        }
    }

    public function getLatestActivePackage(User $user): ?array
    {
        if (!$user->id) {
            return null;
        }

        $package = UserTrafficPackage::with(['trafficPackage:id,name', 'plan:id,name'])
            ->where('user_id', $user->id)
            ->where('status', UserTrafficPackage::STATUS_ACTIVE)
            ->where('remaining_bytes', '>', 0)
            ->orderByDesc('id')
            ->first();

        if (!$package) {
            return null;
        }

        return [
            'name' => $package->trafficPackage?->name ?? $package->plan?->name ?? __('Traffic Package'),
            'total_bytes' => (int) $package->total_bytes,
            'remaining_bytes' => (int) $package->remaining_bytes,
            'source' => $package->traffic_package_id ? 'traffic_package' : 'legacy_plan',
        ];
    }

    public function getTrafficSummary(User $user): array
    {
        $hasActivePlan = $this->hasActivePlan($user);
        $planTransferEnable = $hasActivePlan ? (int) ($user->transfer_enable ?? 0) : 0;
        $planUsedTraffic = $hasActivePlan ? (int) (($user->u ?? 0) + ($user->d ?? 0)) : 0;
        $planRemainingTraffic = max(0, $planTransferEnable - $planUsedTraffic);
        $trafficPackageTotal = $user->id ? $this->getActiveTotalBytes($user->id) : 0;
        $trafficPackageRemaining = $user->id ? $this->getRemainingBytes($user->id) : 0;
        $latestTrafficPackage = $this->getLatestActivePackage($user);
        $activeProductType = $hasActivePlan
            ? 'plan'
            : ($trafficPackageRemaining > 0 ? 'traffic_package' : 'none');
        $activeProductName = match ($activeProductType) {
            'plan' => $user->plan?->name ?? ($user->plan_id ? Plan::find($user->plan_id)?->name : null),
            'traffic_package' => $latestTrafficPackage['name'] ?? null,
            default => null,
        };

        return [
            'has_active_plan' => $hasActivePlan,
            'active_product_type' => $activeProductType,
            'active_product_name' => $activeProductName,
            'latest_traffic_package' => $latestTrafficPackage,
            'effective_expired_at' => $hasActivePlan ? $user->expired_at : null,
            'plan_transfer_enable' => $planTransferEnable,
            'plan_used_traffic' => $planUsedTraffic,
            'plan_remaining_traffic' => $planRemainingTraffic,
            'traffic_package_total' => $trafficPackageTotal,
            'traffic_package_remaining' => $trafficPackageRemaining,
            'effective_transfer_enable' => $planTransferEnable + $trafficPackageRemaining,
            'effective_remaining_traffic' => $planRemainingTraffic + $trafficPackageRemaining,
        ];
    }
}
