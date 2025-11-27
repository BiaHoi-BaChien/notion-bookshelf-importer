<?php

namespace Tests\Unit;

use App\Services\NotionService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class NotionServiceTest extends TestCase
{
    public function test_build_properties_throws_when_mapping_is_empty(): void
    {
        config(['notion.property_mapping' => []]);

        Log::shouldReceive('error')->once();

        $service = new NotionService();

        $this->expectException(InvalidArgumentException::class);
        $this->invokeBuildProperties($service, ['name' => 'Example']);
    }

    public function test_build_properties_throws_when_no_payload_built(): void
    {
        config(['notion.property_mapping' => [
            'name' => ['name' => 'Title', 'type' => 'title'],
        ]]);

        Log::shouldReceive('warning')->once();

        $service = new NotionService();

        $this->expectException(InvalidArgumentException::class);
        $this->invokeBuildProperties($service, ['name' => null]);
    }

    private function invokeBuildProperties(NotionService $service, array $properties): array
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildProperties');
        $method->setAccessible(true);

        return $method->invoke($service, $properties);
    }
}
