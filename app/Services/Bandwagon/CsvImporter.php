<?php

namespace App\Services\Bandwagon;

class CsvImporter
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 262144;

    /** Credentials stay internal: callers must use preview() for browser responses. */
    public function parse(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES) throw new BandwagonException('CSV 不能超过 256 KiB');
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', trim($text)));
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!$header) throw new BandwagonException('请粘贴含表头的搬瓦工 CSV');
            $header = array_map(fn ($v) => strtoupper(trim((string) $v)), $header);
            if (count($header) !== count(array_unique($header)) ||
                array_diff(['VEID', 'API_KEY', 'IS_TERMINATED'], $header)) {
                throw new BandwagonException('CSV 表头须包含 VEID、API_KEY、IS_TERMINATED，且不能有重复列');
            }
            $rows = []; $seen = []; $line = 1;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                if (count($cells) === 1 && trim((string) $cells[0]) === '') continue;
                if (count($rows) >= self::MAX_ROWS) throw new BandwagonException('每次最多识别 500 条，请分批导入');
                $row = ['line' => $line, 'veid' => '', 'hostname' => '', 'vm_type' => '', 'ip' => '', 'status' => 'invalid', 'reason' => '列数与表头不一致'];
                if (count($cells) !== count($header)) { $rows[] = $row; continue; }
                $data = array_combine($header, array_map(fn ($v) => trim((string) $v), $cells));
                $row = array_merge($row, ['veid' => $data['VEID'], 'hostname' => $data['HOSTNAME'] ?? '',
                    'vm_type' => $data['VM_TYPE'] ?? '', 'ip' => $data['PRIMARY_IP'] ?? '']);
                if (!preg_match('/^[1-9][0-9]{0,19}$/D', $row['veid'])) $row['reason'] = 'VEID 格式不正确';
                elseif ($data['IS_TERMINATED'] === '1') { $row['status'] = 'terminated'; $row['reason'] = '已终止，跳过导入'; }
                elseif ($data['IS_TERMINATED'] !== '0') $row['reason'] = 'IS_TERMINATED 只接受 0 或 1';
                elseif (!preg_match('/^private_[A-Za-z0-9_-]{8,500}$/D', $data['API_KEY'])) $row['reason'] = 'API Key 格式不正确';
                elseif (strlen($row['hostname']) > 255 || preg_match('/[\x00-\x1F\x7F]/', $row['hostname']) || strlen($row['vm_type']) > 16) $row['reason'] = '主机名或类型格式不正确';
                elseif ($row['ip'] !== '' && !filter_var($row['ip'], FILTER_VALIDATE_IP)) $row['reason'] = 'PRIMARY_IP 格式不正确';
                elseif (isset($seen[$row['veid']])) { $row['status'] = 'duplicate'; $row['reason'] = '本次重复，保留首条有效记录'; }
                else {
                    $seen[$row['veid']] = true;
                    $row['status'] = 'valid'; $row['reason'] = '可导入'; $row['api_key'] = $data['API_KEY'];
                }
                // Invalid input might itself contain credentials in another column.
                if ($row['status'] === 'invalid') $row = array_merge($row, ['veid' => '', 'hostname' => '', 'vm_type' => '', 'ip' => '']);
                $rows[] = $row;
            }
            if (!$rows) throw new BandwagonException('CSV 中没有服务器记录');
            return $rows;
        } finally { fclose($stream); }
    }

    public function preview(array $rows): array
    {
        return array_map(function ($row) { unset($row['api_key']); return $row; }, $rows);
    }
}
