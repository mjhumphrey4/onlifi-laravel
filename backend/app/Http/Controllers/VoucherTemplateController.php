<?php

namespace App\Http\Controllers;

use App\Models\VoucherTemplate;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class VoucherTemplateController extends Controller
{
    private function resolveTemplateSite(Request $request)
    {
        $site = SiteScope::selectedOrDefaultSite($request);
        SiteScope::backfillLegacyCentralSite(SiteScope::defaultSite($request), ['voucher_templates']);
        return $site;
    }

    public function index(Request $request)
    {
        $tenant = app('tenant');
        $site = $this->resolveTemplateSite($request);
        $this->ensurePresetTemplates($tenant->id, $site);
        
        $templates = VoucherTemplate::where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'templates' => $templates,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'layout' => 'required|in:single,grid-2x2,grid-2x4,grid-3x3,grid-4x5,grid-5x8,grid-8x10',
            'paper_size' => 'required|string',
            'logo_url' => 'nullable|string',
            'background_color' => 'nullable|string|max:7',
            'text_color' => 'nullable|string|max:7',
            'accent_color' => 'nullable|string|max:7',
            'show_voucher_code' => 'boolean',
            'show_voucher_type' => 'boolean',
            'show_sales_point' => 'boolean',
            'show_duration' => 'boolean',
            'show_price' => 'boolean',
            'show_expiry' => 'boolean',
            'show_qr_code' => 'boolean',
            'design' => 'nullable|array',
            'header_text' => 'nullable|string',
            'footer_text' => 'nullable|string',
            'instructions' => 'nullable|string',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenant = app('tenant');
        $site = $this->resolveTemplateSite($request);

        // If this is set as default, unset other defaults
        if ($request->is_default) {
            VoucherTemplate::where('tenant_id', $tenant->id)
                ->when($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
                ->update(['is_default' => false]);
        }

        try {
            $template = VoucherTemplate::create([
                'tenant_id' => $tenant->id,
                ...($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id') ? ['site_id' => $site->id] : []),
                ...$request->only([
                    'name', 'description', 'layout', 'paper_size', 'logo_url',
                    'background_color', 'text_color', 'accent_color',
                    'design',
                    'show_voucher_code', 'show_voucher_type', 'show_sales_point',
                    'show_duration', 'show_price', 'show_expiry', 'show_qr_code',
                    'header_text', 'footer_text', 'instructions', 'is_default',
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('Voucher template create failed', [
                'tenant_id' => $tenant->id,
                'site_id' => $site?->id,
                'layout' => $request->input('layout'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Template save failed',
                'message' => 'Unable to save template: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Template created successfully',
            'template' => $template,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $tenant = app('tenant');
        $site = $this->resolveTemplateSite($request);
        
        $template = VoucherTemplate::where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
            ->findOrFail($id);

        return response()->json($template);
    }

    public function update(Request $request, $id)
    {
        $tenant = app('tenant');
        $site = $this->resolveTemplateSite($request);
        
        $template = VoucherTemplate::where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'layout' => 'in:single,grid-2x2,grid-2x4,grid-3x3,grid-4x5,grid-5x8,grid-8x10',
            'paper_size' => 'string',
            'logo_url' => 'nullable|string',
            'background_color' => 'nullable|string|max:7',
            'text_color' => 'nullable|string|max:7',
            'accent_color' => 'nullable|string|max:7',
            'show_voucher_code' => 'boolean',
            'show_voucher_type' => 'boolean',
            'show_sales_point' => 'boolean',
            'show_duration' => 'boolean',
            'show_price' => 'boolean',
            'show_expiry' => 'boolean',
            'show_qr_code' => 'boolean',
            'design' => 'nullable|array',
            'header_text' => 'nullable|string',
            'footer_text' => 'nullable|string',
            'instructions' => 'nullable|string',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If this is set as default, unset other defaults
        if ($request->is_default) {
            VoucherTemplate::where('tenant_id', $tenant->id)
                ->when($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        try {
            $template->update($request->only([
                'name', 'description', 'layout', 'paper_size', 'logo_url',
                'background_color', 'text_color', 'accent_color',
                'design',
                'show_voucher_code', 'show_voucher_type', 'show_sales_point',
                'show_duration', 'show_price', 'show_expiry', 'show_qr_code',
                'header_text', 'footer_text', 'instructions', 'is_default', 'is_active',
            ]));
        } catch (\Throwable $e) {
            Log::error('Voucher template update failed', [
                'tenant_id' => $tenant->id,
                'site_id' => $site?->id,
                'template_id' => $template->id,
                'layout' => $request->input('layout'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Template save failed',
                'message' => 'Unable to save template: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Template updated successfully',
            'template' => $template->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tenant = app('tenant');
        $site = $this->resolveTemplateSite($request);
        $this->ensurePresetTemplates($tenant->id, $site);
        
        $template = VoucherTemplate::where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
            ->findOrFail($id);

        $template->delete();

        return response()->json([
            'message' => 'Template deleted successfully',
        ]);
    }

    public function setDefault(Request $request, $id)
    {
        $tenant = app('tenant');
        $site = $this->resolveTemplateSite($request);
        
        // Unset all defaults
        VoucherTemplate::where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
            ->update(['is_default' => false]);

        // Set this one as default
        $template = VoucherTemplate::where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
            ->findOrFail($id);
        
        $template->update(['is_default' => true]);

        return response()->json([
            'message' => 'Default template updated',
            'template' => $template,
        ]);
    }

    public function getDefault(Request $request)
    {
        $tenant = app('tenant');
        $site = $this->resolveTemplateSite($request);
        
        $templateQuery = VoucherTemplate::where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id'), fn ($query) => $query->where('site_id', $site->id));

        $template = (clone $templateQuery)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first()
            ?: (clone $templateQuery)
                ->where('is_active', true)
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->first();

        if (!$template) {
            // Return a default template structure if none exists
            return response()->json([
                'template' => [
                    'name' => 'Default',
                    'description' => 'Default OnLiFi voucher template',
                    'layout' => 'grid-2x4',
                    'paper_size' => 'A4',
                    'design' => ['style' => 'blue-strip', 'numbering' => true],
                    'background_color' => '#ffffff',
                    'text_color' => '#000000',
                    'accent_color' => '#3b82f6',
                    'show_voucher_code' => true,
                    'show_voucher_type' => true,
                    'show_sales_point' => false,
                    'show_duration' => false,
                    'show_price' => true,
                    'show_expiry' => false,
                    'show_qr_code' => false,
                    'header_text' => $site?->name ?: 'WIFI NAME',
                    'footer_text' => '',
                    'instructions' => '',
                ],
            ]);
        }

        return response()->json([
            'template' => $template,
        ]);
    }

    private function ensurePresetTemplates(int $tenantId, $site): void
    {
        $hasSiteColumn = Schema::connection('central')->hasColumn('voucher_templates', 'site_id');
        $query = VoucherTemplate::where('tenant_id', $tenantId)
            ->when($site && $hasSiteColumn, fn ($query) => $query->where('site_id', $site->id));

        $hasDefault = (clone $query)->where('is_default', true)->exists();

        foreach ($this->presetTemplates($site?->name ?: 'WIFI NAME') as $index => $preset) {
            $existing = (clone $query)->where('name', $preset['name'])->first();

            if ($existing) {
                $updates = [];

                if (in_array($existing->header_text, ['WIFI NAME', 'STK WIFI POINT', null, ''], true)) {
                    $updates['header_text'] = $site?->name ?: 'WIFI NAME';
                }

                if (in_array($existing->footer_text, ['Support: +256 700 000 000', 'Powered by onlifi.net'], true)) {
                    $updates['footer_text'] = '';
                }

                if (in_array($existing->instructions, ['One device per voucher.', 'Use this voucher on one device only.', 'Connect to WiFi and enter the code.', 'Terms apply. One device per voucher.'], true)) {
                    $updates['instructions'] = '';
                }

                $updates['show_voucher_code'] = true;
                $updates['show_voucher_type'] = true;
                $updates['show_sales_point'] = false;
                $updates['show_duration'] = false;
                $updates['show_price'] = true;
                $updates['show_expiry'] = false;
                $updates['show_qr_code'] = false;

                if ($updates) {
                    $existing->update($updates);
                }

                continue;
            }

            VoucherTemplate::create([
                'tenant_id' => $tenantId,
                ...($site && $hasSiteColumn ? ['site_id' => $site->id] : []),
                ...$preset,
                'is_default' => !$hasDefault && $index === 0,
                'is_active' => true,
            ]);

            if (!$hasDefault && $index === 0) {
                $hasDefault = true;
            }
        }
    }

    private function presetTemplates(string $siteName): array
    {
        return [
            [
                'name' => 'Default Blue Strip',
                'description' => 'Compact blue voucher with numbered header.',
                'layout' => 'grid-2x4',
                'paper_size' => 'A4',
                'design' => ['style' => 'blue-strip', 'numbering' => true],
                'background_color' => '#ffffff',
                'text_color' => '#1f2937',
                'accent_color' => '#0444cf',
                'show_voucher_code' => true,
                'show_voucher_type' => true,
                'show_sales_point' => false,
                'show_duration' => false,
                'show_price' => true,
                'show_expiry' => false,
                'show_qr_code' => false,
                'header_text' => $siteName,
                'footer_text' => '',
                'instructions' => '',
            ],
            [
                'name' => 'Green Numbered',
                'description' => 'Soft green voucher with number on the left.',
                'layout' => 'grid-2x4',
                'paper_size' => 'A4',
                'design' => ['style' => 'green-numbered', 'numbering' => true],
                'background_color' => '#ffffff',
                'text_color' => '#14532d',
                'accent_color' => '#2ecc71',
                'show_voucher_code' => true,
                'show_voucher_type' => true,
                'show_sales_point' => false,
                'show_duration' => false,
                'show_price' => true,
                'show_expiry' => false,
                'show_qr_code' => false,
                'header_text' => $siteName,
                'footer_text' => '',
                'instructions' => '',
            ],
            [
                'name' => 'Standard',
                'description' => 'Clean standard voucher without decorative icons.',
                'layout' => 'grid-2x4',
                'paper_size' => 'A4',
                'design' => ['style' => 'wifi-icon', 'numbering' => true],
                'background_color' => '#ffffff',
                'text_color' => '#164e63',
                'accent_color' => '#2563eb',
                'show_voucher_code' => true,
                'show_voucher_type' => true,
                'show_sales_point' => false,
                'show_duration' => false,
                'show_price' => true,
                'show_expiry' => false,
                'show_qr_code' => false,
                'header_text' => $siteName,
                'footer_text' => '',
                'instructions' => '',
            ],
            [
                'name' => 'Modern Blue Card',
                'description' => 'Larger modern voucher with gradient header.',
                'layout' => 'grid-2x2',
                'paper_size' => 'A4',
                'design' => ['style' => 'modern-blue', 'numbering' => true],
                'background_color' => '#ffffff',
                'text_color' => '#111827',
                'accent_color' => '#0444cf',
                'show_voucher_code' => true,
                'show_voucher_type' => true,
                'show_sales_point' => false,
                'show_duration' => false,
                'show_price' => true,
                'show_expiry' => false,
                'show_qr_code' => false,
                'header_text' => $siteName,
                'footer_text' => '',
                'instructions' => '',
            ],
        ];
    }
}
