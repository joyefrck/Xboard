<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\TelegramGroupAccessService;
use App\Services\TelegramBindingService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function binding(Request $request, TelegramBindingService $service)
    {
        return $this->success($service->account($request->user()))->header('Cache-Control', 'no-store');
    }

    public function getBotInfo()
    {
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();
        $data = [
            'username' => $response->result->username
        ];
        return $this->success($data);
    }

    public function groupStatus(Request $request, TelegramGroupAccessService $service)
    {
        return $this->success($service->status($request->user()))->header('Cache-Control', 'no-store');
    }

    public function joinGroup(Request $request, TelegramGroupAccessService $service)
    {
        try {
            return $this->success($service->issue($request->user()))->header('Cache-Control', 'no-store');
        } catch (\Throwable) {
            return $this->fail([503, '入群服务暂时不可用，请稍后重试'])->header('Cache-Control', 'no-store');
        }
    }

    public function unbind(Request $request)
    {
        $user = User::where('user_id', $request->user()->id)->first();
    }
}
