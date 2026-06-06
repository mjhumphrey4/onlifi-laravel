<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IotecApiProfile;
use App\Models\IotecAuditLog;
use App\Models\IotecCallbackEndpoint;
use App\Models\IotecSetting;
use App\Services\IotecTransactionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class IotecAdminController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $configuredToken = (string) env('IOTEC_ADMIN_TOKEN', '');

        if ($configuredToken === '' || ! hash_equals($configuredToken, (string) $request->input('token'))) {
            return response()->json(['message' => 'Invalid IOTEC admin token.'], 401);
        }

        return response()->json([
            'token' => $configuredToken,
            'admin' => [
                'name' => 'IOTEC Payments Admin',
                'role' => 'iotec_admin',
            ],
        ]);
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'name' => 'IOTEC Payments Admin',
            'role' => 'iotec_admin',
        ]);
    }

    public function dashboard(IotecTransactionRepository $transactions): JsonResponse
    {
        return response()->json([
            ...$transactions->dashboard(),
            'api_profiles' => IotecApiProfile::orderByDesc('status')->orderBy('name')->get()->map->toAdminArray(),
            'callbacks' => IotecCallbackEndpoint::orderByDesc('is_active')->orderBy('event')->get()->map->toAdminArray(),
            'settings' => IotecSetting::orderBy('group')->orderBy('key')->get()->map(fn ($setting) => $setting->toAdminArray()),
            'sms' => [
                'enabled' => IotecSetting::value('sms_functionality_enabled', false),
                'message' => 'SMS functionality is intentionally disabled until the new provider is supplied.',
            ],
        ]);
    }

    public function transactions(Request $request, IotecTransactionRepository $transactions): JsonResponse
    {
        return response()->json($transactions->list($request->only([
            'page',
            'per_page',
            'limit',
            'status',
            'search',
            'from',
            'to',
        ])));
    }

    public function apiProfiles(): JsonResponse
    {
        return response()->json([
            'data' => IotecApiProfile::orderByDesc('status')->orderBy('name')->get()->map->toAdminArray(),
        ]);
    }

    public function storeApiProfile(Request $request): JsonResponse
    {
        $data = $this->validateApiProfile($request);
        $profile = IotecApiProfile::create($data);
        $this->audit($request, 'api_profile.created', $profile);

        return response()->json(['data' => $profile->toAdminArray()], 201);
    }

    public function updateApiProfile(Request $request, IotecApiProfile $apiProfile): JsonResponse
    {
        $data = $this->validateApiProfile($request, $apiProfile);

        if (($data['client_secret'] ?? '') === '********') {
            unset($data['client_secret']);
        }

        $apiProfile->update($data);
        $this->audit($request, 'api_profile.updated', $apiProfile);

        return response()->json(['data' => $apiProfile->fresh()->toAdminArray()]);
    }

    public function destroyApiProfile(Request $request, IotecApiProfile $apiProfile): JsonResponse
    {
        $apiProfile->delete();
        $this->audit($request, 'api_profile.deleted', $apiProfile);

        return response()->json(['message' => 'IOTEC API profile deleted.']);
    }

    public function callbacks(): JsonResponse
    {
        return response()->json([
            'data' => IotecCallbackEndpoint::orderByDesc('is_active')->orderBy('event')->get()->map->toAdminArray(),
        ]);
    }

    public function storeCallback(Request $request): JsonResponse
    {
        $data = $this->validateCallback($request);
        $callback = IotecCallbackEndpoint::create($data);
        $this->audit($request, 'callback.created', $callback);

        return response()->json(['data' => $callback->toAdminArray()], 201);
    }

    public function updateCallback(Request $request, IotecCallbackEndpoint $callback): JsonResponse
    {
        $data = $this->validateCallback($request);
        $data['headers'] = $this->mergeSecrets($callback->headers ?? [], $data['headers'] ?? []);
        $callback->update($data);
        $this->audit($request, 'callback.updated', $callback);

        return response()->json(['data' => $callback->fresh()->toAdminArray()]);
    }

    public function destroyCallback(Request $request, IotecCallbackEndpoint $callback): JsonResponse
    {
        $callback->delete();
        $this->audit($request, 'callback.deleted', $callback);

        return response()->json(['message' => 'IOTEC callback deleted.']);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'data' => IotecSetting::orderBy('group')->orderBy('key')->get()->map(fn ($setting) => $setting->toAdminArray()),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'present|nullable',
            'settings.*.type' => 'nullable|in:string,integer,float,boolean,json,array',
            'settings.*.group' => 'nullable|string',
            'settings.*.label' => 'nullable|string',
            'settings.*.description' => 'nullable|string',
            'settings.*.is_sensitive' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        foreach ($request->input('settings') as $row) {
            $existing = IotecSetting::where('key', $row['key'])->first();
            $isSensitive = (bool) ($row['is_sensitive'] ?? $existing?->is_sensitive ?? false);
            $value = $row['value'];

            if ($isSensitive && $value === '********') {
                continue;
            }

            IotecSetting::put($row['key'], $value, [
                'type' => $row['type'] ?? $existing?->type ?? 'string',
                'group' => $row['group'] ?? $existing?->group ?? 'general',
                'label' => $row['label'] ?? $existing?->label ?? $row['key'],
                'description' => $row['description'] ?? $existing?->description,
                'is_sensitive' => $isSensitive,
            ]);
        }

        $this->audit($request, 'settings.updated');

        return $this->settings();
    }

    public function testLegacyDatabase(IotecTransactionRepository $transactions): JsonResponse
    {
        return response()->json($transactions->testConnection());
    }

    public function auditLogs(): JsonResponse
    {
        return response()->json([
            'data' => IotecAuditLog::latest()->limit(100)->get(),
        ]);
    }

    private function validateApiProfile(Request $request, ?IotecApiProfile $profile = null): array
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_\\-]+$/', Rule::unique('iotec_api_profiles', 'code')->ignore($profile?->id)],
            'status' => 'required|in:active,inactive,draft',
            'environment' => 'required|in:production,sandbox,staging,local',
            'auth_url' => 'required|string|max:255',
            'api_base_url' => 'required|string|max:255',
            'wallet_id' => 'nullable|string|max:120',
            'client_id' => 'nullable|string|max:160',
            'client_secret' => 'nullable|string',
            'callback_url' => 'nullable|string|max:255',
            'default_currency' => 'required|string|max:8',
            'default_category' => 'required|string|max:60',
            'settings' => 'nullable|array',
            'notes' => 'nullable|string',
        ])->validate();
    }

    private function validateCallback(Request $request): array
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'event' => 'required|string|max:100',
            'method' => 'required|in:GET,POST,PUT,PATCH',
            'url' => 'required|string|max:255',
            'expected_fields' => 'nullable|array',
            'headers' => 'nullable|array',
            'is_active' => 'required|boolean',
            'notes' => 'nullable|string',
        ])->validate();
    }

    private function mergeSecrets(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($value === '********') {
                $incoming[$key] = $current[$key] ?? '';
            }
        }

        return $incoming;
    }

    private function audit(Request $request, string $action, mixed $subject = null): void
    {
        IotecAuditLog::create([
            'action' => $action,
            'subject_type' => is_object($subject) ? $subject::class : null,
            'subject_id' => is_object($subject) && isset($subject->id) ? $subject->id : null,
            'metadata' => [
                'user_agent' => $request->userAgent(),
            ],
            'ip_address' => $request->ip(),
        ]);
    }
}
