<?php

namespace App\Services;

use App\Models\Site;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OmadaService
{
    public function status(Site $site): array
    {
        if ($site->site_type !== 'omada') {
            return [
                'enabled' => false,
                'linked' => false,
                'message' => 'The selected site is not an Omada site.',
            ];
        }

        if (!$site->omada_site_id) {
            return [
                'enabled' => true,
                'linked' => false,
                'link_status' => $site->omada_link_status ?: 'pending_admin',
                'omada_site_name' => $site->omada_site_name ?: $site->name,
                'message' => 'Waiting for an administrator to link the Omada Site ID.',
            ];
        }

        return [
            'enabled' => true,
            'linked' => true,
            'link_status' => $site->omada_link_status ?: 'linked',
            'omada_site_name' => $site->omada_site_name ?: $site->name,
            'omada_site_id' => $site->omada_site_id,
            'omada_controller_id' => $site->omada_controller_id ?: config('services.omada.controller_id'),
        ];
    }

    public function devices(Site $site): array
    {
        return $this->getSiteResource($site, 'devices');
    }

    public function clients(Site $site): array
    {
        return $this->getSiteResource($site, 'clients');
    }

    public function vouchers(Site $site): array
    {
        return $this->getSiteResource($site, 'vouchers');
    }

    private function getSiteResource(Site $site, string $resource): array
    {
        $this->ensureLinked($site);

        $controllerId = $site->omada_controller_id ?: config('services.omada.controller_id');
        $siteId = $site->omada_site_id;

        if (!$controllerId) {
            throw new RuntimeException('Omada controller ID is not configured.');
        }
        if (!config('services.omada.operator_username') || !config('services.omada.operator_password')) {
            throw new RuntimeException('Omada operator credentials are not configured.');
        }

        $path = match ($resource) {
            'devices' => "/{$controllerId}/api/v2/sites/{$siteId}/devices",
            'clients' => "/{$controllerId}/api/v2/sites/{$siteId}/clients",
            'vouchers' => "/{$controllerId}/api/v2/hotspot/sites/{$siteId}/vouchers",
            default => throw new RuntimeException('Unsupported Omada resource.'),
        };

        [$request, $csrfToken] = $this->authenticatedRequest($controllerId);
        $response = $request
            ->withHeaders(['Csrf-Token' => $csrfToken])
            ->get($this->baseUrl() . $path);

        if (!$response->successful()) {
            throw new RuntimeException("Omada {$resource} request failed with HTTP {$response->status()}.");
        }

        return $response->json() ?: [];
    }

    private function ensureLinked(Site $site): void
    {
        if ($site->site_type !== 'omada') {
            throw new RuntimeException('The selected site is not an Omada site.');
        }

        if (!$site->omada_site_id) {
            throw new RuntimeException('This Omada site is waiting for administrator linking.');
        }
    }

    private function http(): PendingRequest
    {
        $request = Http::timeout((int) config('services.omada.timeout', 10))
            ->acceptJson();

        if (!config('services.omada.verify_tls', false)) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    private function authenticatedRequest(string $controllerId): array
    {
        $cookieJar = new CookieJar();
        $request = $this->http()->withOptions(['cookies' => $cookieJar]);
        $loginUrl = $this->baseUrl() . "/{$controllerId}/api/v2/hotspot/login";

        $response = $request->post($loginUrl, [
            'name' => config('services.omada.operator_username'),
            'password' => config('services.omada.operator_password'),
        ]);

        if (!$response->successful()) {
            throw new RuntimeException("Omada login failed with HTTP {$response->status()}.");
        }

        $payload = $response->json() ?: [];
        if ((int) ($payload['errorCode'] ?? -1) !== 0) {
            throw new RuntimeException($payload['msg'] ?? 'Omada login failed.');
        }

        $token = (string) data_get($payload, 'result.token', '');
        if ($token === '') {
            throw new RuntimeException('Omada login did not return a CSRF token.');
        }

        return [$request, $token];
    }

    private function baseUrl(): string
    {
        $host = trim((string) config('services.omada.host', '10.200.1.253'));
        $port = (int) config('services.omada.port', 8043);

        return 'https://' . $host . ($port ? ":{$port}" : '');
    }
}
