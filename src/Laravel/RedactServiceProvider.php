<?php

declare(strict_types=1);

namespace RedactSdk\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use RedactSdk\Client;
use RedactSdk\Config;

class RedactServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/redact.php', 'redact');

        // Singleton: one warm transport/connection pool for the whole app
        // (and across requests under Octane).
        $this->app->singleton(Client::class, function (Container $app): Client {
            /** @var array<string, mixed> $config */
            $config = $app['config']['redact'];

            return new Client(new Config(
                baseUri: (string) ($config['base_uri'] ?? 'http://127.0.0.1:8080'),
                apiKey: $config['api_key'] ?? null,
                timeout: (float) ($config['timeout'] ?? 10.0),
                connectTimeout: (float) ($config['connect_timeout'] ?? 3.0),
                retries: (int) ($config['retries'] ?? 2),
                retryDelayMs: (int) ($config['retry_delay_ms'] ?? 100),
                defaultThreshold: isset($config['default_threshold']) ? (float) $config['default_threshold'] : null,
            ));
        });

        $this->app->alias(Client::class, 'redact');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/redact.php' => $this->app->configPath('redact.php'),
            ], 'redact-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [Client::class, 'redact'];
    }
}
