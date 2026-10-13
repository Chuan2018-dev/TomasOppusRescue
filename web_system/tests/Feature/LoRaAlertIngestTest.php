<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoRaAlertIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_lora_hardware_alert_creates_responder_visible_report(): void
    {
        Event::fake([IncidentFeedUpdated::class]);
        config(['services.lora_ingest.token' => 'test-lora-token']);

        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])
            ->assertCreated()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('data.transmission_type', 'lora')
            ->assertJsonPath('data.reporter_name', 'Juan Dela Cruz')
            ->assertJsonPath('data.lora_sender_id', 'S01')
            ->assertJsonPath('data.lora_sequence', '42');

        $report = IncidentReport::query()->firstOrFail();

        $this->assertSame('Juan Dela Cruz', $report->reporter_name);
        $this->assertSame('LoRa Emergency Alert', $report->incident_type);
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('received', $report->status);
        $this->assertSame('LoRa Mesh', $report->channel);
        $this->assertSame('lora', $report->transmission_type);
        $this->assertSame('arduino_lora_receiver', $report->hardware_source);
        $this->assertNotNull($report->ingest_key);
        $this->assertSame('not_applicable', $report->ai_status);
        $this->assertNull($report->ai_source);
        $this->assertNull($report->ai_confidence);
        $this->assertSame('G1', $report->lora_gateway_id);
        $this->assertSame(-70, $report->lora_gateway_rssi);
        $this->assertSame('9.50', $report->lora_gateway_snr);
        $this->assertSame(-65, $report->lora_receiver_rssi);
        $this->assertSame('8.10', $report->lora_receiver_snr);
        $this->assertSame(7, $report->lora_satellites);
        $this->assertSame('Motorcycle crash, one injured person.', $report->description);
        $this->assertNotNull($report->transmitted_at);
        $this->assertStringContainsString('LoRa gateway G1 GPS: 10.307156, 124.980718', $report->location_text);

        Event::assertDispatched(IncidentFeedUpdated::class);
    }

    public function test_lora_hardware_alert_rejects_invalid_token(): void
    {
        config(['services.lora_ingest.token' => 'test-lora-token']);

        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'wrong-token',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_lora_hardware_alert_is_idempotent_by_sender_and_sequence(): void
    {
        config(['services.lora_ingest.token' => 'test-lora-token']);

        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])->assertCreated();

        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.lora_sender_id', 'S01')
            ->assertJsonPath('data.lora_sequence', '42');

        $this->assertDatabaseCount('incident_reports', 1);
    }

    public function test_lora_requires_full_name_and_limits_optional_description(): void
    {
        config(['services.lora_ingest.token' => 'test-lora-token']);

        $missingName = $this->validPayload();
        unset($missingName['reporter_name']);

        $this->postJson('/api/v1/lora/alerts', $missingName, [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reporter_name');

        $longDescription = $this->validPayload();
        $longDescription['description'] = str_repeat('x', 81);

        $this->postJson('/api/v1/lora/alerts', $longDescription, [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');

        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_lora_report_is_unified_on_admin_pages_but_hidden_from_civilian_history(): void
    {
        config(['services.lora_ingest.token' => 'test-lora-token']);

        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])->assertCreated();

        $report = IncidentReport::query()->firstOrFail();
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'is_admin' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee($report->report_code)
            ->assertSee('Juan Dela Cruz')
            ->assertSee('Gateway G1')
            ->assertSee('Review');

        $this->actingAs($admin)
            ->get(route('monitoring'))
            ->assertOk()
            ->assertSee($report->report_code)
            ->assertSee('LoRa reports')
            ->assertSee('Gateway');

        $this->actingAs($admin)
            ->get(route('reports.index', [
                'search' => $report->report_code,
                'review' => $report->id,
            ]))
            ->assertOk()
            ->assertSee('id="incident-report-'.$report->id.'"', false)
            ->assertSee('data-report-open-on-load', false)
            ->assertSee('LoRa compact payload')
            ->assertSee('Full name')
            ->assertSee('No photo attached');

        $this->actingAs($civilian)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee($report->report_code);
    }

    private function validPayload(): array
    {
        return [
            'sender_id' => 'S01',
            'sequence' => '42',
            'reporter_name' => 'Juan Dela Cruz',
            'gateway_id' => 'G1',
            'latitude' => 10.307156,
            'longitude' => 124.980718,
            'satellites' => 7,
            'gateway_rssi' => -70,
            'gateway_snr' => 9.5,
            'receiver_rssi' => -65,
            'receiver_snr' => 8.1,
            'severity' => 'Serious',
            'incident_type' => 'LoRa Emergency Alert',
            'description' => 'Motorcycle crash, one injured person.',
        ];
    }
}
