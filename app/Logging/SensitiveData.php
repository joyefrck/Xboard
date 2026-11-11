<?php

namespace App\Logging;

use Throwable;

class SensitiveData
{
    private static function sensitive(string $key): bool
    {
        return (bool) preg_match('/password|passphrase|token|auth_data|authorization|cookie|secret|email_code|verification_code|(^|_)otp($|_)|api_key|^verify$|^code$/i', $key);
    }

    public static function secrets(array $data): array
    {
        $values = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) $values = array_merge($values, self::secrets($value));
            elseif (self::sensitive((string) $key) && is_scalar($value) && strlen((string) $value) >= 4) {
                $values[] = (string) $value;
            }
        }
        return array_values(array_unique($values));
    }

    public static function redact(mixed $value, array $secrets = [], int $depth = 0): mixed
    {
        if ($depth > 12) return '[omitted]';
        if ($value instanceof Throwable) return ['exception_type' => get_class($value), 'code' => $value->getCode()];
        if (is_object($value) || is_resource($value)) return '[omitted]';
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::sensitive((string) $key) ? '[REDACTED]' : self::redact($item, $secrets, $depth + 1);
            }
            return $out;
        }
        if (!is_string($value)) return $value;
        $json = json_decode($value, true);
        if (is_array($json)) return json_encode(self::redact($json, $secrets, $depth + 1), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        foreach ($secrets as $secret) {
            $value = str_replace([$secret, rawurlencode($secret), urlencode($secret)], '[REDACTED]', $value);
        }
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value);
        return preg_replace('/((?:password|old_password|new_password|email_code|token|auth_data|api_key|verify|secret)\s*[=:]\s*)[^\s&,;}]+/i', '$1[REDACTED]', $value);
    }

    public static function uri(string $uri, array $secrets = []): string
    {
        $parts = explode('?', $uri, 2);
        $path = preg_replace('~/[a-f0-9]{32,64}(?=/|$)~i', '/[REDACTED]', $parts[0]);
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
            $path .= '?' . http_build_query(self::redact($query, $secrets));
        }
        return self::redact($path, $secrets);
    }
}
