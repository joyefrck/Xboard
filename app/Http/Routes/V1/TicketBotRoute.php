<?php

namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\TicketBotController;
use App\Http\Middleware\TicketBotToken;
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
    }
}
