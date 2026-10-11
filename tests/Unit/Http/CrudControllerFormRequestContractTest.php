<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\Api\AbstractApiCrudController;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use Tests\TestCase;

/**
 * Every CRUD controller must declare FormRequests so the base class never persists raw input.
 */
class CrudControllerFormRequestContractTest extends TestCase
{
    /**
     * Controllers that legitimately lack hooks.
     * CallDetailRecordController has GET-only routes.
     */
    private const ALLOWLIST = [
        'CallDetailRecordController',
    ];

    public function test_every_crud_controller_declares_store_and_update_form_requests(): void
    {
        $checked = 0;

        foreach (glob(app_path('Http/Controllers/Api/*.php')) as $file) {
            $class = 'App\\Http\\Controllers\\Api\\'.basename($file, '.php');
            $ref = new ReflectionClass($class);

            if ($ref->isAbstract() || ! $ref->isSubclassOf(AbstractApiCrudController::class)) {
                continue;
            }
            if (in_array($ref->getShortName(), self::ALLOWLIST, true)) {
                continue;
            }

            $controller = $ref->newInstanceWithoutConstructor();
            foreach (['getStoreRequestClass', 'getUpdateRequestClass'] as $hook) {
                $method = $ref->getMethod($hook);
                $method->setAccessible(true);
                $result = $method->invoke($controller);

                $this->assertIsString($result, "{$class}::{$hook}() must return a FormRequest class");
                $this->assertTrue(is_subclass_of($result, FormRequest::class), "{$class}::{$hook}() must return a FormRequest subclass");
            }
            $checked++;
        }

        $this->assertGreaterThanOrEqual(8, $checked);
    }
}
