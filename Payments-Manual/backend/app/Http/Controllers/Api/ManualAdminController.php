<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManualCallbackEndpoint;
use App\Models\ManualPaymentAuditLog;
use App\Models\ManualPaymentProvider;
use App\Models\ManualPaymentSetting;
use App\Models\ManualWithdrawalApi;
use App\Services\LegacyTransactionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ManualAdminController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $configuredToken = (string) env('PAYMENTS_MANUAL_ADMIN_TOKEN', '');

        if ($configuredToken === '' || ! hash_equals($configuredToken, (string) $request->input('token'))) {
            return response()->json(['message' => 'Invalid admin token.'], 401);
        }

        return response()->json([
            'token' => $configuredToken,
            'admin' => [
                'name' => 'Payments Manual Admin',
                'role' => 'system_admin',
            ],
        ]);
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'name' => 'Payments Manual Admin',
            'role' => 'system_admin',
        ]);
    }

    public function dashboard(LegacyTransactionRepository $transactions): JsonResponse
    {
        return response()->json([
            ...$transactions->dashboard(),
            'providers' => ManualPaymentProvider::orderBy('priority')->get()->map->toAdminArray(),
            'callbacks' => ManualCallbackEndpoint::orderByDesc('is_active')->orderBy('event')->get()->map->toAdminArray(),
            'withdrawal_apis' => ManualWithdrawalApi::orderBy('name')->get()->map->toAdminArray(),
            'settings' => ManualPaymentSetting::orderBy('group')->orderBy('key')->get()->map(fn ($setting) => $setting->toAdminArray()),
            'sms' => [
                'enabled' => ManualPaymentSetting::value('sms_functionality_enabled', false),
                'message' => 'SMS functionality is intentionally disabled until the new provider is supplied.',
            ],
        ]);
    }

    public function transactions(Request $request, LegacyTransactionRepository $transactions): JsonResponse
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

    public function providers(): JsonResponse
    {
        return response()->json([
            'data' => ManualPaymentProvider::orderBy('priority')->orderBy('name')->get()->map->toAdminArray(),
        ]);
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $data = $this->validateProvider($request);
        $provider = ManualPaymentProvider::create($data);
        $this->audit($request, 'provider.created', $provider);

        return response()->json(['data' => $provider->toAdminArray()], 201);
    }

    public function updateProvider(Request $request, ManualPaymentProvider $provider): JsonResponse
    {
        $data = $this->validateProvider($request, $provider);
        $data['credentials'] = $this->mergeSecrets($provider->credentials ?? [], $data['credentials'] ?? []);
        $provider->update($data);
        $this->audit($request, 'provider.updated', $provider);

        return response()->json(['data' => $provider->fresh()->toAdminArray()]);
    }

    public function destroyProvider(Request $request, ManualPaymentProvider $provider): JsonResponse
    {
        $provider->delete();
        $this->audit($request, 'provider.deleted', $provider);

        return response()->json(['message' => 'Provider deleted.']);
    }

    public function callbacks(): JsonResponse
    {
        return response()->json([
            'data' => ManualCallbackEndpoint::orderByDesc('is_active')->orderBy('event')->get()->map->toAdminArray(),
        ]);
    }

    public function storeCallback(Request $request): JsonResponse
    {
        $data = $this->validateCallback($request);
        $callback = ManualCallbackEndpoint::create($data);
        $this->audit($request, 'callback.created', $callback);

        return response()->json(['data' => $callback->toAdminArray()], 201);
    }

    public function updateCallback(Request $request, ManualCallbackEndpoint $callback): JsonResponse
    {
        $data = $this->validateCallback($request);
        $data['headers'] = $this->mergeSecrets($callback->headers ?? [], $data['headers'] ?? []);
        if (($data['signing_secret'] ?? '') === '********') {
            unset($data['signing_secret']);
        }

        $callback->update($data);
        $this->audit($request, 'callback.updated', $callback);

        return response()->json(['data' => $callback->fresh()->toAdminArray()]);
    }

    public function destroyCallback(Request $request, ManualCallbackEndpoint $callback): JsonResponse
    {
        $callback->delete();
        $this->audit($request, 'callback.deleted', $callback);

        return response()->json(['message' => 'Callback deleted.']);
    }

    public function withdrawalApis(): JsonResponse
    {
        return response()->json([
            'data' => ManualWithdrawalApi::orderBy('name')->get()->map->toAdminArray(),
        ]);
    }

    public function storeWithdrawalApi(Request $request): JsonResponse
    {
        $data = $this->validateWithdrawalApi($request);
        $api = ManualWithdrawalApi::create($data);
        $this->audit($request, 'withdrawal_api.created', $api);

        return response()->json(['data' => $api->toAdminArray()], 201);
    }

    public function updateWithdrawalApi(Request $request, ManualWithdrawalApi $withdrawalApi): JsonResponse
    {
        $data = $this->validateWithdrawalApi($request, $withdrawalApi);
        $data['credentials'] = $this->mergeSecrets($withdrawalApi->credentials ?? [], $data['credentials'] ?? []);
        $withdrawalApi->update($data);
        $this->audit($request, 'withdrawal_api.updated', $withdrawalApi);

        return response()->json(['data' => $withdrawalApi->fresh()->toAdminArray()]);
    }

    public function destroyWithdrawalApi(Request $request, ManualWithdrawalApi $withdrawalApi): JsonResponse
    {
        $withdrawalApi->delete();
        $this->audit($request, 'withdrawal_api.deleted', $withdrawalApi);

        return response()->json(['message' => 'Withdrawal API deleted.']);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'data' => ManualPaymentSetting::orderBy('group')->orderBy('key')->get()->map(fn ($setting) => $setting->toAdminArray()),
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
            $existing = ManualPaymentSetting::where('key', $row['key'])->first();
            $isSensitive = (bool) ($row['is_sensitive'] ?? $existing?->is_sensitive ?? false);
            $value = $row['value'];

            if ($isSensitive && $value === '********') {
                continue;
            }

            ManualPaymentSetting::put($row['key'], $value, [
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

    public function testLegacyDatabase(LegacyTransactionRepository $transactions): JsonResponse
    {
        return response()->json($transactions->testConnection());
    }

    public function auditLogs(): JsonResponse
    {
        return response()->json([
            'data' => ManualPaymentAuditLog::latest()->limit(100)->get(),
        ]);
    }

    private function validateProvider(Request $request, ?ManualPaymentProvider $provider = null): array
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_\\-]+$/', Rule::unique('manual_payment_providers', 'code')->ignore($provider?->id)],
            'provider_type' => 'required|in:collection,fallback,verification,other',
            'status' => 'required|in:active,inactive,draft',
            'priority' => 'required|integer|min:1|max:999',
            'base_url' => 'nullable|string|max:255',
            'callback_url' => 'nullable|string|max:255',
            'credentials' => 'nullable|array',
            'settings' => 'nullable|array',
            'notes' => 'nullable|string',
        ])->validate();
    }

    private function validateCallback(Request $request): array
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'event' => 'required|string|max:80',
            'method' => 'required|in:GET,POST,PUT,PATCH',
            'url' => 'required|string|max:255',
            'headers' => 'nullable|array',
            'signing_secret' => 'nullable|string',
            'is_active' => 'required|boolean',
            'notes' => 'nullable|string',
        ])->validate();
    }

    private function validateWithdrawalApi(Request $request, ?ManualWithdrawalApi $api = null): array
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'provider_code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_\\-]+$/', Rule::unique('manual_withdrawal_apis', 'provider_code')->ignore($api?->id)],
            'status' => 'required|in:active,inactive,draft',
            'base_url' => 'nullable|string|max:255',
            'credentials' => 'nullable|array',
            'settings' => 'nullable|array',
            'daily_limit' => 'nullable|numeric|min:0',
            'minimum_amount' => 'nullable|numeric|min:0',
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
        ManualPaymentAuditLog::create([
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
