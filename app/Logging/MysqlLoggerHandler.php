<?php
namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;
use Monolog\LogRecord;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $record = $record->toArray();
        try {
            $requestData = request()->all();
            $secrets = array_merge(SensitiveData::secrets($requestData), SensitiveData::secrets($record['context']));
            
            $log = [
                'title' => SensitiveData::redact($record['message'], $secrets),
                'level' => $record['level_name'],
                'host' => $record['extra']['request_host'] ?? request()->getSchemeAndHttpHost(),
                'uri' => SensitiveData::uri($record['extra']['request_uri'] ?? request()->getRequestUri(), $secrets),
                'method' => $record['extra']['request_method'] ?? request()->getMethod(),
                'ip' => request()->getClientIp(),
                'data' => json_encode(SensitiveData::redact($requestData, $secrets)),
                'context' => json_encode(SensitiveData::redact($record['context'], $secrets)),
                'created_at' => $record['datetime']->getTimestamp(),
                'updated_at' => $record['datetime']->getTimestamp(),
            ];
            
            LogModel::insert($log);
        } catch (\Exception $e) {
            // Log::channel('daily')->error($e->getMessage().$e->getFile().$e->getTraceAsString());
        }
    }
}
