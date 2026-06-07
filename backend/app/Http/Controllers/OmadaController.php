<?php

namespace App\Http\Controllers;

use App\Services\OmadaService;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use RuntimeException;

class OmadaController extends Controller
{
    public function __construct(private OmadaService $omada)
    {
    }

    public function status(Request $request)
    {
        $site = $this->omadaSite($request);
        if (!$site) {
            return response()->json([
                'enabled' => false,
                'linked' => false,
                'message' => 'Select an Omada site first.',
            ], 404);
        }

        return response()->json($this->omada->status($site));
    }

    public function devices(Request $request)
    {
        return $this->resourceResponse($request, 'devices');
    }

    public function clients(Request $request)
    {
        return $this->resourceResponse($request, 'clients');
    }

    public function vouchers(Request $request)
    {
        return $this->resourceResponse($request, 'vouchers');
    }

    private function resourceResponse(Request $request, string $resource)
    {
        $site = $this->omadaSite($request);
        if (!$site) {
            return response()->json([
                'error' => 'Omada site required',
                'message' => 'Select an Omada site before loading Omada data.',
                $resource => [],
            ], 404);
        }

        if (!$site->omada_site_id) {
            return response()->json([
                'error' => 'Omada site pending admin linking',
                'message' => 'An administrator must link the Omada Site ID before API data can be loaded.',
                'link_status' => $site->omada_link_status ?: 'pending_admin',
                'omada_site_name' => $site->omada_site_name ?: $site->name,
                $resource => [],
            ], 409);
        }

        try {
            $data = match ($resource) {
                'devices' => $this->omada->devices($site),
                'clients' => $this->omada->clients($site),
                'vouchers' => $this->omada->vouchers($site),
            };

            return response()->json([
                $resource => $data['result'] ?? $data['data'] ?? $data,
                'raw' => $data,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => 'Omada API request failed',
                'message' => $e->getMessage(),
                $resource => [],
            ], 503);
        }
    }

    private function omadaSite(Request $request)
    {
        $site = SiteScope::selectedOrDefaultSite($request);

        return $site?->site_type === 'omada' ? $site : null;
    }
}
