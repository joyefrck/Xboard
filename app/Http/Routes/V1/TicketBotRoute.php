<?php

namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\TicketBotController;
use App\Http\Middleware\TicketBotToken;
use App\Http\Middleware\TicketServiceToken;
use App\Http\Controllers\V1\TicketServiceActionController;
use Illuminate\Contracts\Routing\Registrar;

class TicketBotRoute
{
    public function map(Registrar $router): void
    {
        $router->group([
            'prefix' => 'ticket-bot/tickets',
            'middleware' => [TicketBotToken::class, 'throttle:ticket-bot'],
            'where' => ['ticket' => '[1-9][0-9]{0,9}', 'attachment' => '[1-9][0-9]{0,9}'],
        ], function ($router) {
            $router->get('/', [TicketBotController::class, 'index']);
            $router->get('{ticket}', [TicketBotController::class, 'show']);
            $router->get('{ticket}/user-context', [TicketBotController::class, 'userContext']);
            $router->get('{ticket}/attachments/{attachment}', [TicketBotController::class, 'attachment']);
            $router->post('{ticket}/replies', [TicketBotController::class, 'reply']);
            $router->post('{ticket}/notes', [TicketBotController::class, 'note']);
            $router->post('{ticket}/close', [TicketBotController::class, 'close']);
        });
        $router->group([
            'prefix' => 'ticket-bot/tickets/{ticket}',
            'where' => ['ticket' => '[1-9][0-9]{0,9}'],
            'middleware' => ['throttle:ticket-bot'],
        ], function ($router) {
            $router->middleware(TicketServiceToken::class . ':refund')->group(function ($router) {
                $router->get('orders', [TicketServiceActionController::class, 'orders']);
                $router->post('refunds/preview', [TicketServiceActionController::class, 'refundPreview']);
                $router->post('refunds', [TicketServiceActionController::class, 'refund']);
            });
            $router->middleware(TicketServiceToken::class . ':plan_access')->group(function ($router) {
                $router->get('plan-access', [TicketServiceActionController::class, 'access']);
                $router->post('plan-access/preview', [TicketServiceActionController::class, 'accessPreview']);
                $router->post('plan-access/changes', [TicketServiceActionController::class, 'changeAccess']);
            });
        });
    }
}
