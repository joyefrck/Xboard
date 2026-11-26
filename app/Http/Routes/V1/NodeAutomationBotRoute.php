<?php

namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\NodeAutomationBotController;
use App\Http\Middleware\NodeAutomationBotToken;
use Illuminate\Contracts\Routing\Registrar;

class NodeAutomationBotRoute
{
    public function map(Registrar $router): void
    {
        $router->group([
            'prefix' => 'node-automation-bot',
            'middleware' => [NodeAutomationBotToken::class, 'throttle:node-automation-bot'],
            'where' => [
                'domain' => '[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9.])?',
                'ip' => '(?:[0-9]{1,3}\.){3}[0-9]{1,3}',
                'operation' => '[1-9][0-9]{0,9}',
            ],
        ], function ($router) {
            $router->get('domains', [NodeAutomationBotController::class, 'index']);
            $router->get('domains/{domain}', [NodeAutomationBotController::class, 'show']);
            $router->post('domains/{domain}/backups', [NodeAutomationBotController::class, 'storeBackup']);
            $router->post('domains/{domain}/backups/{ip}/test', [NodeAutomationBotController::class, 'test']);
            $router->post('domains/{domain}/enable', [NodeAutomationBotController::class, 'enable']);
            $router->post('domains/{domain}/pause', [NodeAutomationBotController::class, 'pause']);
            $router->get('operations/{operation}', [NodeAutomationBotController::class, 'operation']);
        });
    }
}
