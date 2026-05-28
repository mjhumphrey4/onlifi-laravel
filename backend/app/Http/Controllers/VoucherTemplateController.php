<?php

namespace App\Http\Controllers;

use App\Models\VoucherTemplate;
use App\Support\SiteScope;
use Illuminate\Http\Request;
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
            'layout' => 'required|in:single,grid-2x2,grid-2x4,grid-3x3',
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

        $template = VoucherTemplate::create([
            'tenant_id' => $tenant->id,
            ...($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id') ? ['site_id' => $site->id] : []),
            ...$request->only([
                'name', 'description', 'layout', 'paper_size', 'logo_url',
                'background_color', 'text_color', 'accent_color',
                'show_voucher_code', 'show_voucher_type', 'show_sales_point',
                'show_duration', 'show_price', 'show_expiry', 'show_qr_code',
                'header_text', 'footer_text', 'instructions', 'is_default',
            ]),
        ]);

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
            'layout' => 'in:single,grid-2x2,grid-2x4,grid-3x3',
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

        $template->update($request->only([
            'name', 'description', 'layout', 'paper_size', 'logo_url',
            'background_color', 'text_color', 'accent_color',
            'show_voucher_code', 'show_voucher_type', 'show_sales_point',
            'show_duration', 'show_price', 'show_expiry', 'show_qr_code',
            'header_text', 'footer_text', 'instructions', 'is_default', 'is_active',
        ]));

        return response()->json([
            'message' => 'Template updated successfully',
            'template' => $template->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tenant = app('tenant');
        $site = $this->resolveTemplateSite($request);
        
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
                    'layout' => 'grid-2x4',
                    'paper_size' => 'A4',
                    'background_color' => '#ffffff',
                    'text_color' => '#000000',
                    'accent_color' => '#3b82f6',
                    'show_voucher_code' => true,
                    'show_voucher_type' => true,
                    'show_sales_point' => true,
                    'show_duration' => true,
                    'show_price' => true,
                    'show_expiry' => false,
                    'show_qr_code' => false,
                ],
            ]);
        }

        return response()->json([
            'template' => $template,
        ]);
    }
}
