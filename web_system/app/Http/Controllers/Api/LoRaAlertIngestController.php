<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Support\IncidentFeedBroadcaster;
use App\Support\IncidentReportIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LoRaAlertIngestController extends Controller
{
    public function __invoke(Request $request, IncidentReportIngestor $ingestor): JsonResponse
    {
        $this->authorizeHardwareGateway($request);

        $validated = Validator::make($request->all(), [
            'sender_id' => ['required', 'string', 'max:40'],
            'sequence' => ['required', 'string', 'max:40'],
            'reporter_name' => ['required', 'string', 'max:48'],
            'gateway_id' => ['nullable', 'string', 'max:40'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'satellites' => ['nullable', 'integer', 'min:0', 'max:99'],
            'gateway_rssi' => ['nullable', 'numeric'],
            'gateway_snr' => ['nullable', 'numeric'],
            'receiver_rssi' => ['nullable', 'numeric'],
            'receiver_snr' => ['nullable', 'numeric'],
            'severity' => ['nullable', 'in:Minor,Serious,Fatal'],
            'incident_type' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:80'],
        ])->validate();

        $senderId = trim((string) $validated['sender_id']);
        $sequence = trim((string) $validated['sequence']);
        $reporterName = trim((string) $validated['reporter_name']);
        $gatewayId = trim((string) ($validated['gateway_id'] ?? 'LoRa Gateway'));

        $ingestKey = $ingestor->loraKey($senderId, $sequence);

        if ($existing = $ingestor->existing($ingestKey)) {
            return response()->json([
                'message' => 'LoRa alert already exists.',
                'duplicate' => true,
                'data' => $this->reportPayload($existing),
            ]);
        }

        $latitude = $this->coordinateOrNull($validated['latitude'] ?? null);
        $longitude = $this->coordinateOrNull($validated['longitude'] ?? null);
        $satellites = (int) ($validated['satellites'] ?? 0);
        $locationText = $this->locationText($latitude, $longitude, $satellites, $gatewayId);
        $description = $this->descriptionFor($validated, $senderId, $sequence, $gatewayId);

        $result = $ingestor->persist($ingestKey, [
            'report_code' => 'LORA-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
            'reported_by' => null,
            'reporter_name' => $reporterName,
            'reporter_contact' => null,
            'incident_type' => $validated['incident_type'] ?? 'LoRa Emergency Alert',
            'severity' => $validated['severity'] ?? 'Serious',
            'status' => 'received',
            'channel' => 'LoRa Mesh',
            'transmission_type' => 'lora',
            'location_text' => $locationText,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'description' => $description,
            'ai_summary' => null,
            'ai_confidence' => null,
            'ai_source' => null,
            'ai_status' => 'not_applicable',
            'ai_model_name' => null,
            'ai_model_version' => null,
            'ai_review_required' => false,
            'ai_probabilities' => null,
            'ai_processed_at' => null,
            'ai_error_message' => null,
            'evidence_type' => 'none',
            'coordination_log' => [
                [
                    'at' => now()->toIso8601String(),
                    'event' => 'lora_alert_ingested',
                    'sender_id' => $senderId,
                    'reporter_name' => $reporterName,
                    'sequence' => $sequence,
                    'gateway_id' => $gatewayId,
                ],
            ],
            'transmitted_at' => now(),
            'status_updated_at' => now(),
            'hardware_source' => 'arduino_lora_receiver',
            'lora_sender_id' => $senderId,
            'lora_sequence' => $sequence,
            'lora_gateway_id' => $gatewayId,
            'lora_gateway_rssi' => $this->integerOrNull($validated['gateway_rssi'] ?? null),
            'lora_gateway_snr' => $this->floatOrNull($validated['gateway_snr'] ?? null),
            'lora_receiver_rssi' => $this->integerOrNull($validated['receiver_rssi'] ?? null),
            'lora_receiver_snr' => $this->floatOrNull($validated['receiver_snr'] ?? null),
            'lora_satellites' => $satellites,
        ]);
        $report = $result['report'];

        if (! $result['created']) {
            return response()->json([
                'message' => 'LoRa alert already exists.',
                'duplicate' => true,
                'data' => $this->reportPayload($report),
            ]);
        }

        IncidentFeedBroadcaster::dispatch($report, 'created');

        return response()->json([
            'message' => 'LoRa alert ingested successfully.',
            'duplicate' => false,
            'data' => $this->reportPayload($report),
        ], 201);
    }

    private function authorizeHardwareGateway(Request $request): void
    {
        $expectedToken = (string) config('services.lora_ingest.token');

        abort_if(blank($expectedToken), 503, 'LoRa ingest token is not configured.');

        $providedToken = (string) (
            $request->bearerToken()
            ?: $request->header('X-STITCH-LORA-TOKEN')
            ?: $request->input('token')
        );

        abort_unless(
            $providedToken !== '' && hash_equals($expectedToken, $providedToken),
            401,
            'Invalid LoRa ingest token.'
        );
    }

    private function coordinateOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return abs($coordinate) < 0.000001 ? null : $coordinate;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function locationText(?float $latitude, ?float $longitude, int $satellites, string $gatewayId): string
    {
        if ($latitude !== null && $longitude !== null) {
            return 'LoRa gateway '.$gatewayId.' GPS: '.$latitude.', '.$longitude.' | satellites '.$satellites;
        }

        return 'LoRa gateway '.$gatewayId.' GPS unavailable | satellites '.$satellites;
    }

    private function descriptionFor(array $payload, string $senderId, string $sequence, string $gatewayId): string
    {
        if (filled($payload['description'] ?? null)) {
            return trim((string) $payload['description']);
        }

        return 'SOS received from '.$senderId.' via '.$gatewayId.' (message '.$sequence.'). No optional description.';
    }

    private function reportPayload(IncidentReport $report): array
    {
        return [
            'id' => $report->id,
            'report_code' => $report->report_code,
            'reporter_name' => $report->reporter_name,
            'incident_type' => $report->incident_type,
            'severity' => $report->severity,
            'status' => $report->status,
            'transmission_type' => $report->transmission_type,
            'location_text' => $report->location_text,
            'latitude' => $report->latitude,
            'longitude' => $report->longitude,
            'description' => $report->description,
            'lora_sender_id' => $report->lora_sender_id,
            'lora_sequence' => $report->lora_sequence,
            'lora_gateway_id' => $report->lora_gateway_id,
        ];
    }
}
