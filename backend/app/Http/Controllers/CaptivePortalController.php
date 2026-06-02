<?php

namespace App\Http\Controllers;

use App\Models\CaptivePortalTemplate;
use App\Services\CaptivePaymentService;
use App\Services\CaptivePortalService;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use ZipArchive;

class CaptivePortalController extends Controller
{
    public function templates(CaptivePortalService $portal)
    {
        $tenant = request()->user()?->tenant;
        $site = SiteScope::selectedOrDefaultSite(request());
        $templateQuery = CaptivePortalTemplate::where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('captive_portal_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id));

        return response()->json([
            'base_templates' => $portal->templates(),
            'templates' => $templateQuery->latest()->get(),
            'active_template' => $portal->activeTemplateForTenant($tenant, $site),
            'active_site' => $site ? ['id' => $site->id, 'name' => $site->name, 'slug' => $site->slug] : null,
        ]);
    }

    public function saveTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'theme' => 'required|string|max:50',
            'design' => 'required|array',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $tenant = $request->user()->tenant;
        $site = SiteScope::selectedOrDefaultSite($request);

        return DB::connection('central')->transaction(function () use ($request, $tenant, $site) {
            if ($request->boolean('is_active')) {
                CaptivePortalTemplate::where('tenant_id', $tenant->id)
                    ->when($site && Schema::connection('central')->hasColumn('captive_portal_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
                    ->update(['is_active' => false]);
            }

            $template = CaptivePortalTemplate::create([
                'tenant_id' => $tenant->id,
                ...(Schema::connection('central')->hasColumn('captive_portal_templates', 'site_id') && $site ? ['site_id' => $site->id] : []),
                'name' => $request->name,
                'theme' => $request->theme,
                'design' => $request->design,
                'is_active' => $request->boolean('is_active'),
            ]);

            return response()->json([
                'message' => 'Captive portal template saved',
                'template' => $template,
            ], 201);
        });
    }

    public function preview(Request $request, CaptivePortalService $portal)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:100',
            'theme' => 'nullable|string|max:50',
            'design' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $tenant = $request->user()->tenant;
        $site = SiteScope::selectedOrDefaultSite($request);
        $template = [
            'id' => null,
            'name' => $request->input('name', 'Classic OLD-Flow'),
            'theme' => $request->input('theme', 'old-flow'),
            'design' => $request->input('design', []),
        ];

        return response($portal->previewLoginHtml($tenant, $site, $template))
            ->header('Content-Type', 'text/html');
    }

    public function download(Request $request, CaptivePortalService $portal)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:100',
            'theme' => 'nullable|string|max:50',
            'design' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $tenant = $request->user()->tenant;
        $site = SiteScope::selectedOrDefaultSite($request);
        $template = [
            'id' => null,
            'name' => $request->input('name', 'Default Theme'),
            'theme' => $request->input('theme', 'default-theme'),
            'design' => $request->input('design', []),
        ];
        $siteSlug = $site?->slug ?: \Illuminate\Support\Str::slug($site?->name ?: $tenant->name);
        $logoAsset = $this->uploadedLogoAsset($template['design']['logo_url'] ?? null);

        if ($logoAsset && class_exists(ZipArchive::class)) {
            $logoName = 'logo.' . pathinfo($logoAsset['path'], PATHINFO_EXTENSION);
            $template['design']['logo_url'] = $logoName;
            $html = $portal->downloadLoginHtml($tenant, $site, $template);
            $zipPath = storage_path('app/' . Str::random(32) . '-captive-page.zip');
            $zip = new ZipArchive();

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFromString('login.html', $html);
                $zip->addFromString($logoName, $logoAsset['contents']);
                $zip->close();

                return response()->download($zipPath, ($siteSlug ?: 'site') . '-hotspot.zip')->deleteFileAfterSend(true);
            }
        }

        return response($portal->downloadLoginHtml($tenant, $site, $template))
            ->header('Content-Type', 'text/html')
            ->header('Content-Disposition', 'attachment; filename="' . ($siteSlug ?: 'site') . '-login.html"');
    }

    public function uploadLogo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,svg', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $tenant = $request->user()->tenant;
        $site = SiteScope::selectedOrDefaultSite($request);
        $directory = 'captive-logos/tenant-' . $tenant->id . ($site ? '/site-' . $site->id : '');
        $path = $request->file('logo')->store($directory, 'public');
        $logoUrl = rtrim($request->getSchemeAndHttpHost(), '/') . '/storage/' . ltrim($path, '/');

        return response()->json([
            'message' => 'Logo uploaded',
            'logo_url' => $logoUrl,
            'path' => $path,
        ]);
    }

    private function uploadedLogoAsset(?string $logoUrl): ?array
    {
        $logoUrl = trim((string) $logoUrl);
        if ($logoUrl === '') {
            return null;
        }

        $path = parse_url($logoUrl, PHP_URL_PATH) ?: $logoUrl;
        $storagePosition = strpos($path, '/storage/');
        if ($storagePosition !== false) {
            $path = substr($path, $storagePosition + strlen('/storage/'));
        }
        $path = ltrim($path, '/');

        if (!Str::startsWith($path, 'captive-logos/') || !Storage::disk('public')->exists($path)) {
            return null;
        }

        return [
            'path' => $path,
            'contents' => Storage::disk('public')->get($path),
        ];
    }

    public function activateTemplate(Request $request, CaptivePortalTemplate $template)
    {
        $tenant = $request->user()->tenant;
        $site = SiteScope::selectedOrDefaultSite($request);

        if ((int) $template->tenant_id !== (int) $tenant->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }
        if ($site && Schema::connection('central')->hasColumn('captive_portal_templates', 'site_id') && $template->site_id && (int) $template->site_id !== (int) $site->id) {
            return response()->json(['message' => 'Template not found for this site'], 404);
        }

        CaptivePortalTemplate::where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('captive_portal_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
            ->update(['is_active' => false]);
        $template->update(['is_active' => true]);

        return response()->json(['message' => 'Captive portal template activated', 'template' => $template->fresh()]);
    }

    public function config(string $token, CaptivePortalService $portal)
    {
        $config = $portal->configForToken($token);

        return $config
            ? response()->json($config)
            : response()->json(['message' => 'Captive portal not found'], 404);
    }

    public function hotspotFile(string $token, string $file, CaptivePortalService $portal)
    {
        $contents = $portal->hotspotFile($token, $file);
        $contentType = $file === 'md5.js' ? 'application/javascript' : 'text/html';

        return $contents
            ? response($contents)->header('Content-Type', $contentType)
            : response('Not found', 404);
    }

    public function pay(Request $request, CaptivePaymentService $payments)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'msisdn' => ['required', 'string', 'regex:/^\+?(256|0)?(7[0-9]{8})$/'],
            'amount' => 'required|numeric|min:100',
            'voucher_type' => 'nullable|string|max:100',
            'client_mac' => 'nullable|string|max:32',
            'origin_url' => 'nullable|string|max:1000',
            'email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $result = $payments->initiate($request->all());

        return response()->json($result, ($result['status'] ?? -1) === 1 ? 200 : 422);
    }

    public function paymentStatus(Request $request, CaptivePaymentService $payments)
    {
        $request->validate(['ref' => 'required|string']);

        return response()->json($payments->status($request->query('ref')));
    }

    public function ipn(Request $request, CaptivePaymentService $payments)
    {
        $processed = $payments->handleIpn($request->all());

        return response()->json(['processed' => $processed], $processed ? 200 : 400);
    }

    public function failure(Request $request, CaptivePaymentService $payments)
    {
        $processed = $payments->handleFailure($request->all());

        return response()->json(['processed' => $processed], $processed ? 200 : 400);
    }
}
