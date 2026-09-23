<?php

namespace Tests;

use App\Contracts\Outbound\HostResolver;
use App\Models\Admin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeHostResolver;

/**
 * 测试基类：Feature 测试如需数据库可在用例中 use {@see RefreshDatabase}。
 */
abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        if ($guard === 'admin' && $user instanceof Admin) {
            $this->app['session']->put(
                Admin::AUTH_VERSION_SESSION_KEY,
                (int) $user->auth_version
            );
        }

        return $this;
    }

    public function createApplication()
    {
        $this->forceTestingEnvironment();

        $app = parent::createApplication();

        if (env('GEOFLOW_TEST_PGSQL') === '1') {
            // 本机/CI 用真 PostgreSQL 跑迁移（sqlite 无法承受 ->change() 迁移）：
            // docker run ... -e GEOFLOW_TEST_PGSQL=1 -e DB_HOST=... -e DB_DATABASE=geo_flow_test ...
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql.url', null);
        } else {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.database', ':memory:');
            $app['config']->set('database.connections.pgsql.url', null);
        }
        $app->singleton(HostResolver::class, FakeHostResolver::class);

        return $app;
    }

    private function forceTestingEnvironment(): void
    {
        $variables = [
            'ADMIN_BASE_PATH' => 'geo_admin',
            'APP_ENV' => 'testing',
            'SITE_NAME' => 'GEOFlow',
        ];
        if (env('GEOFLOW_TEST_PGSQL') !== '1') {
            $variables['DB_CONNECTION'] = 'sqlite';
            $variables['DB_DATABASE'] = ':memory:';
            $variables['DB_URL'] = '';
        } else {
            $variables['DB_CONNECTION'] = 'pgsql';
            $variables['DB_URL'] = '';
        }

        foreach ($variables as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }
}
