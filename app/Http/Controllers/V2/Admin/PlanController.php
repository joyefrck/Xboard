<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserTrafficPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $timestamp = time();

        $currentPlanHolders = DB::table('v2_user')
            ->select('plan_id')
            ->selectRaw('id AS user_id')
            ->selectRaw(
                'CASE WHEN banned = 0'
                . ' AND COALESCE(transfer_enable, 0) > 0'
                . ' AND COALESCE(u, 0) + COALESCE(d, 0) < COALESCE(transfer_enable, 0)'
                . ' AND (expired_at IS NULL OR expired_at > ?)'
                . ' THEN 1 ELSE 0 END AS is_active',
                [$timestamp]
            )
            ->whereNotNull('plan_id');

        $packageHolders = DB::table('v2_user_traffic_packages as package_balance')
            ->join('v2_user as package_user', 'package_user.id', '=', 'package_balance.user_id')
            ->select('package_balance.plan_id', 'package_balance.user_id')
            ->selectRaw(
                'CASE WHEN package_user.banned = 0'
                . ' AND package_balance.status = ?'
                . ' AND package_balance.remaining_bytes > 0'
                . ' THEN 1 ELSE 0 END AS is_active',
                [UserTrafficPackage::STATUS_ACTIVE]
            )
            ->whereNotNull('package_balance.plan_id');

        $planHolders = $currentPlanHolders->unionAll($packageHolders);
        $statistics = DB::query()
            ->fromSub($planHolders, 'plan_holders')
            ->select('plan_id')
            ->selectRaw('COUNT(DISTINCT user_id) AS users_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN is_active = 1 THEN user_id END) AS active_users_count')
            ->groupBy('plan_id')
            ->get()
            ->keyBy('plan_id');

        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->get();

        $plans->each(function (Plan $plan) use ($statistics): void {
            $planStatistics = $statistics->get($plan->id);
            $plan->setAttribute('users_count', (int) ($planStatistics->users_count ?? 0));
            $plan->setAttribute('active_users_count', (int) ($planStatistics->active_users_count ?? 0));
        });

        return $this->success($plans);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }
            
            DB::beginTransaction();
            try {
                if ($request->input('force_update')) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'speed_limit' => $params['speed_limit'],
                        'device_limit' => $params['device_limit'],
                    ]);
                }
                $plan->update($params);
                DB::commit();
                return $this->success(true);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }
        if (!Plan::create($params)) {
            return $this->fail([500, '创建失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }
        
        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }
        
        return $this->success($plan->delete());
    }

    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }
}
