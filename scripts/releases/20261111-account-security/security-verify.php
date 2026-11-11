<?php
declare(strict_types=1);

require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $columns = ['login_ip', 'last_ip', 'client_label', 'client_type', 'client_os', 'metadata_seen_at'];
    foreach ($columns as $column) {
        if (!Illuminate\Support\Facades\Schema::hasColumn('personal_access_tokens', $column)) throw new RuntimeException('missing_column');
    }
    if (!Illuminate\Support\Facades\Schema::hasTable('v2_user_trusted_ips')) throw new RuntimeException('missing_trust_table');
    if (!Illuminate\Support\Facades\DB::table('migrations')->where('migration', '2026_11_11_000001_add_account_session_security')->exists()) throw new RuntimeException('migration_not_registered');
    $expected = ['getActiveSession', 'removeActiveSession', 'removeAllActiveSessions', 'sendPasswordChangeCode', 'changePassword'];
    $found = [];
    foreach (app('router')->getRoutes() as $route) {
        foreach ($expected as $method) {
            if ($route->getActionName() === App\Http\Controllers\V1\User\UserController::class . '@' . $method
                && in_array('user', $route->gatherMiddleware(), true)) $found[$method] = true;
        }
    }
    if (count($found) !== count($expected)) throw new RuntimeException('security_route_missing');
    $user = App\Models\User::whereHas('tokens', fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
    $sampled = 0;
    if ($user) {
        $token = $user->tokens()->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })->first();
        $user->withAccessToken($token);
        $request = Illuminate\Http\Request::create('/release-check', 'GET');
        $request->setUserResolver(fn () => $user);
        $rows = (new App\Services\Auth\SessionSecurityService())->sessions($user, $request);
        $allowed = ['id', 'is_current', 'client_label', 'client_type', 'client_os', 'login_ip', 'last_ip', 'created_at', 'last_used_at'];
        foreach ($rows as $row) if (array_diff(array_keys($row), $allowed)) throw new RuntimeException('session_response_leaks_fields');
        if (count(array_filter($rows, fn ($r) => $r['is_current'])) !== 1) throw new RuntimeException('current_session_not_identified');
        $sampled = count($rows);
    }
    $request = Illuminate\Http\Request::create('/release-check', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9']);
    if (App\Services\Auth\SessionSecurityService::metadata($request)['login_ip'] !== '203.0.113.10') throw new RuntimeException('untrusted_forwarded_source');
    if (App\Logging\SensitiveData::redact(['nested' => ['password' => 'fixture-only']])['nested']['password'] !== '[REDACTED]') throw new RuntimeException('redaction_failed');
    echo json_encode(['ok' => true, 'sample_at' => date('c'), 'schema_columns' => $columns, 'protected_routes' => array_keys($found), 'sampled_sessions' => $sampled, 'writes' => 0], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error_class' => get_class($e), 'reason' => $e instanceof RuntimeException ? $e->getMessage() : 'verification_error']), PHP_EOL;
    exit(1);
}
