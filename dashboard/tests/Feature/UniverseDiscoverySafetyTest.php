<?php

namespace Tests\Feature;

use App\Models\IdxCompanyRegistry;
use App\Services\IdxEdgeClient;
use App\Services\UniverseDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UniverseDiscoverySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_discovery_does_not_promote_rows_or_mark_existing_tickers_unavailable(): void
    {
        $existing = IdxCompanyRegistry::create([
            'ticker' => 'KEEP',
            'company_name' => 'Keep Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'identity_status' => 'PROVIDER_DISCOVERED',
            'snapshot_date' => '2026-11-01',
        ]);
        $client = Mockery::mock(IdxEdgeClient::class);
        $client->shouldReceive('marketCap')->with(1, 50)->once()->andReturn([
            'success' => true,
            'data' => [
                'total' => 2,
                'total_pages' => 2,
                'items' => [['code' => 'NEW1', 'name' => 'New One Tbk', 'listed_shares' => 1000]],
            ],
        ]);
        $client->shouldReceive('marketCap')->with(2, 50)->times(4)->andReturn([
            'success' => false,
            'error_type' => 'PROVIDER_CONNECTION_FAILED',
        ]);

        $result = (new UniverseDiscoveryService($client))->reconcileUniverse();

        $this->assertFalse($result['success']);
        $this->assertSame([2], $result['failed_pages']);
        $this->assertSame(0, $result['marked_unavailable']);
        $this->assertDatabaseMissing('idx_company_registries', ['ticker' => 'NEW1']);
        $this->assertSame('AVAILABLE', $existing->fresh()->provider_availability);
    }

    public function test_complete_validated_discovery_promotes_atomically_and_preserves_existing_ids(): void
    {
        $present = IdxCompanyRegistry::create([
            'ticker' => 'PRES',
            'company_name' => 'Present Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'identity_status' => 'PROVIDER_DISCOVERED',
            'snapshot_date' => '2026-11-01',
        ]);
        IdxCompanyRegistry::create([
            'ticker' => 'GONE',
            'company_name' => 'Gone Tbk',
            'instrument_type' => 'EQUITY',
            'provider_availability' => 'AVAILABLE',
            'identity_status' => 'PROVIDER_DISCOVERED',
            'snapshot_date' => '2026-11-01',
        ]);
        $client = Mockery::mock(IdxEdgeClient::class);
        $client->shouldReceive('marketCap')->with(1, 50)->once()->andReturn([
            'success' => true,
            'data' => [
                'total' => 2,
                'total_pages' => 1,
                'items' => [
                    ['code' => 'PRES', 'name' => 'Present Tbk', 'listed_shares' => 1000],
                    ['code' => 'NEW2', 'name' => 'New Two Tbk', 'listed_shares' => 2000],
                ],
            ],
        ]);

        $result = (new UniverseDiscoveryService($client))->reconcileUniverse();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['marked_unavailable']);
        $this->assertSame($present->id, IdxCompanyRegistry::where('ticker', 'PRES')->value('id'));
        $this->assertDatabaseHas('idx_company_registries', ['ticker' => 'NEW2', 'provider_availability' => 'AVAILABLE']);
        $this->assertDatabaseHas('idx_company_registries', ['ticker' => 'GONE', 'provider_availability' => 'UNAVAILABLE']);
    }

    public function test_partial_discovery_resumes_from_checkpoint_and_promotes_same_run(): void
    {
        $firstClient = Mockery::mock(IdxEdgeClient::class);
        $firstClient->shouldReceive('marketCap')->with(1, 50)->once()->andReturn([
            'success' => true,
            'data' => [
                'total' => 2,
                'total_pages' => 2,
                'items' => [['code' => 'ONE', 'name' => 'One Tbk', 'listed_shares' => 1000]],
            ],
        ]);
        $firstClient->shouldReceive('marketCap')->with(2, 50)->times(4)->andReturn([
            'success' => false,
            'error_type' => 'LOCAL_RATE_LIMIT',
        ]);

        $partial = (new UniverseDiscoveryService($firstClient))->reconcileUniverse();

        $this->assertFalse($partial['success']);
        $this->assertDatabaseHas('universe_discovery_runs', [
            'run_id' => $partial['run_id'],
            'status' => 'PARTIAL',
            'next_page' => 2,
        ]);

        $resumeClient = Mockery::mock(IdxEdgeClient::class);
        $resumeClient->shouldReceive('marketCap')->with(2, 50)->once()->andReturn([
            'success' => true,
            'data' => [
                'total' => 2,
                'total_pages' => 2,
                'items' => [['code' => 'TWO', 'name' => 'Two Tbk', 'listed_shares' => 2000]],
            ],
        ]);

        $complete = (new UniverseDiscoveryService($resumeClient))->reconcileUniverse($partial['run_id']);

        $this->assertTrue($complete['success']);
        $this->assertSame('PROMOTED', $complete['promotion_status']);
        $this->assertDatabaseHas('idx_company_registries', ['ticker' => 'ONE', 'provider_availability' => 'AVAILABLE']);
        $this->assertDatabaseHas('idx_company_registries', ['ticker' => 'TWO', 'provider_availability' => 'AVAILABLE']);
        $this->assertDatabaseHas('universe_discovery_runs', ['run_id' => $partial['run_id'], 'status' => 'PROMOTED']);
    }
}
