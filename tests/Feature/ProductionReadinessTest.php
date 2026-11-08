<?php

use Dotenv\Dotenv;

test('production environment enforces HTTPS URLs secure httpOnly lax cookies and InnoDB UTC READ COMMITTED database config', function () {
    expect(config('database.connections.mysql.engine'))->toBe('InnoDB')
        ->and(config('database.connections.mysql.timezone'))->toBe('+00:00')
        ->and(config('database.connections.mysql.isolation_level'))->toBe('READ COMMITTED')
        ->and(config('database.connections.mysql.strict'))->toBeTrue()
        ->and(config('database.connections.mysql.collation'))->toBe('utf8mb4_unicode_ci')
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('session.serialization'))->toBe('json');

    $sessionConfigSource = file_get_contents(config_path('session.php'));
    expect($sessionConfigSource)->toContain("env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production')");

    $providerSource = file_get_contents(app_path('Providers/AppServiceProvider.php'));
    expect($providerSource)->toContain("URL::forceScheme('https')");
});

test('private_uploads storage disk is strictly isolated from public symlinks and direct serving', function () {
    $privateDisk = config('filesystems.disks.private_uploads');
    $links = config('filesystems.links');

    expect($privateDisk['driver'])->toBe('local')
        ->and($privateDisk['visibility'])->toBe('private')
        ->and($privateDisk['serve'])->toBeFalse()
        ->and($privateDisk['throw'])->toBeTrue();

    foreach ($links as $publicLink => $targetPath) {
        expect($targetPath)->not->toContain('private_uploads')
            ->and($targetPath)->not->toContain('app/private');
    }
});

test('environment template documents required keys and safe non-live defaults without secrets', function () {
    $template = Dotenv::parse(file_get_contents(base_path('.env.example')));

    expect($template)->toHaveKeys([
        'APP_KEY',
        'APP_PREVIOUS_KEYS',
        'MIDTRANS_IS_PRODUCTION',
        'MIDTRANS_SERVER_KEY',
        'NOTIFICATION_DELIVERY_ENABLED',
        'NOTIFICATION_RECIPIENT_HMAC_KEY',
        'NOTIFICATION_PREVIOUS_RECIPIENT_HMAC_KEYS',
        'FONNTE_TOKEN',
        'SESSION_HTTP_ONLY',
        'SESSION_SAME_SITE',
    ])
        ->and($template['APP_KEY'])->toBe('')
        ->and($template['MIDTRANS_SERVER_KEY'])->toBe('')
        ->and($template['FONNTE_TOKEN'])->toBe('')
        ->and($template['NOTIFICATION_RECIPIENT_HMAC_KEY'])->toBe('')
        ->and($template['NOTIFICATION_DELIVERY_ENABLED'])->toBe('false');
});
