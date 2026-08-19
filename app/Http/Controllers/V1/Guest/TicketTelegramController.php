<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramUpdateParser;
use App\Services\Telegram\TicketTelegramHandler;
use App\Services\TelegramCredentialService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TicketTelegramController extends Controller
{
    public function __construct(
        private readonly TelegramCredentialService $credentials,
        private readonly TelegramUpdateParser $parser,
        private readonly TicketTelegramHandler $handler
    ) {
    }

    public function webhook(Request $request): void
    {
        $expected = $this->credentials->getTicketWebhookSecret();
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            throw new HttpException(401, 'Telegram webhook authorization failed');
        }

        $message = $this->parser->parse($request->json()->all());
        if ($message) {
            $this->handler->handle($message);
        }
    }
}
