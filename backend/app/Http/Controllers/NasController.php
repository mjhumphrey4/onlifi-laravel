<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * NasController - Manages NAS (Network Access Server) entries for FreeRADIUS
 * 
 * Each MikroTik router needs a unique NAS entry that maps it to a tenant.
 * Since routers don't have public IPs, we use a unique router_identifier
 * (sent via NAS-Identifier attribute) to identify which tenant the router belongs to.
 */
class NasController extends Controller
{
    /**
     * List all NAS entries for the current tenant
     */
    public function index(Request $request)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $nasEntries = DB::connection('central')->table('nas')
            ->where('tenant_id', $tenant->id)
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'nas_entries' => $nasEntries,
            'radius_server' => config('radius.server_ip', '192.168.0.180'),
            'radius_port' => config('radius.auth_port', 1812),
            'radius_acct_port' => config('radius.acct_port', 1813),
        ]);
    }
    
    /**
     * Register a new router as a NAS
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:200',
            'router_id' => 'nullable|integer',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        // Generate unique router identifier
        $routerIdentifier = $this->generateRouterIdentifier($tenant->id);
        
        // Get shared RADIUS secret (or generate one per tenant)
        $radiusSecret = $tenant->radius_secret ?? config('radius.shared_secret', 'onlifi_radius_secret');
        
        // Insert NAS entry
        $nasId = DB::connection('central')->table('nas')->insertGetId([
            'nasname' => '0.0.0.0/0',  // Accept from any IP (we use NAS-Identifier)
            'router_identifier' => $routerIdentifier,
            'shortname' => $request->input('name'),
            'type' => 'other',
            'secret' => $radiusSecret,
            'server' => null,  // Use default virtual server
            'description' => $request->input('description'),
            'tenant_id' => $tenant->id,
            'router_id' => $request->input('router_id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Generate MikroTik configuration script
        $mikrotikScript = $this->generateMikrotikScript(
            $routerIdentifier,
            $radiusSecret,
            config('radius.server_ip', '192.168.0.180')
        );
        
        return response()->json([
            'message' => 'Router registered successfully',
            'nas_id' => $nasId,
            'router_identifier' => $routerIdentifier,
            'radius_config' => [
                'server' => config('radius.server_ip', '192.168.0.180'),
                'auth_port' => 1812,
                'acct_port' => 1813,
                'secret' => $radiusSecret,
                'nas_identifier' => $routerIdentifier,
            ],
            'mikrotik_script' => $mikrotikScript,
        ], 201);
    }
    
    /**
     * Get details of a specific NAS entry
     */
    public function show(Request $request, $id)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $nas = DB::connection('central')->table('nas')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$nas) {
            return response()->json(['error' => 'NAS entry not found'], 404);
        }
        
        // Generate MikroTik script for this NAS
        $mikrotikScript = $this->generateMikrotikScript(
            $nas->router_identifier,
            $nas->secret,
            config('radius.server_ip', '192.168.0.180')
        );
        
        return response()->json([
            'nas' => $nas,
            'mikrotik_script' => $mikrotikScript,
        ]);
    }
    
    /**
     * Update a NAS entry
     */
    public function update(Request $request, $id)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $nas = DB::connection('central')->table('nas')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$nas) {
            return response()->json(['error' => 'NAS entry not found'], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:200',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        DB::connection('central')->table('nas')
            ->where('id', $id)
            ->update([
                'shortname' => $request->input('name', $nas->shortname),
                'description' => $request->input('description', $nas->description),
                'updated_at' => now(),
            ]);
        
        return response()->json([
            'message' => 'NAS entry updated successfully',
        ]);
    }
    
    /**
     * Delete a NAS entry
     */
    public function destroy(Request $request, $id)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $deleted = DB::connection('central')->table('nas')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->delete();
        
        if (!$deleted) {
            return response()->json(['error' => 'NAS entry not found'], 404);
        }
        
        return response()->json([
            'message' => 'NAS entry deleted successfully',
        ]);
    }
    
    /**
     * Regenerate router identifier for a NAS
     */
    public function regenerateIdentifier(Request $request, $id)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $nas = DB::connection('central')->table('nas')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$nas) {
            return response()->json(['error' => 'NAS entry not found'], 404);
        }
        
        $newIdentifier = $this->generateRouterIdentifier($tenant->id);
        
        DB::connection('central')->table('nas')
            ->where('id', $id)
            ->update([
                'router_identifier' => $newIdentifier,
                'updated_at' => now(),
            ]);
        
        $mikrotikScript = $this->generateMikrotikScript(
            $newIdentifier,
            $nas->secret,
            config('radius.server_ip', '192.168.0.180')
        );
        
        return response()->json([
            'message' => 'Router identifier regenerated',
            'router_identifier' => $newIdentifier,
            'mikrotik_script' => $mikrotikScript,
        ]);
    }
    
    /**
     * Get MikroTik RADIUS configuration script
     */
    public function getMikrotikScript(Request $request, $id)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $nas = DB::connection('central')->table('nas')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$nas) {
            return response()->json(['error' => 'NAS entry not found'], 404);
        }
        
        $script = $this->generateMikrotikScript(
            $nas->router_identifier,
            $nas->secret,
            config('radius.server_ip', '192.168.0.180')
        );
        
        return response($script)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', "attachment; filename=\"radius-config-{$nas->shortname}.rsc\"");
    }
    
    /**
     * Generate unique router identifier
     * Format: ONLIFI-{tenant_id}-{timestamp}-{random}
     */
    private function generateRouterIdentifier(int $tenantId): string
    {
        $timestamp = now()->format('ymd');
        $random = strtoupper(Str::random(8));
        return "ONLIFI-{$tenantId}-{$timestamp}-{$random}";
    }
    
    /**
     * Generate MikroTik RADIUS configuration script
     */
    private function generateMikrotikScript(string $routerIdentifier, string $secret, string $serverIp): string
    {
        return <<<RSC
# ============================================
# Onlifi RADIUS Configuration for MikroTik
# ============================================
# Router Identifier: {$routerIdentifier}
# Generated: {now()->toIso8601String()}
#
# IMPORTANT: This script configures your MikroTik router
# to authenticate hotspot users via the Onlifi RADIUS server.
#
# Run this script on your MikroTik router via:
# - Winbox: System > Scripts > New > Paste & Run
# - Terminal: /import file-name=radius-config.rsc
# ============================================

# Remove existing RADIUS configuration (optional - uncomment if needed)
# /radius remove [find]

# Add RADIUS server
/radius add \\
    service=hotspot,login \\
    address={$serverIp} \\
    secret="{$secret}" \\
    timeout=3000ms \\
    authentication-port=1812 \\
    accounting-port=1813 \\
    comment="Onlifi RADIUS Server"

# Set NAS-Identifier (CRITICAL - must match the registered identifier)
# This is how the RADIUS server identifies which tenant this router belongs to
/system identity set name="{$routerIdentifier}"

# Configure Hotspot to use RADIUS
/ip hotspot profile set [find] \\
    use-radius=yes \\
    radius-accounting=yes \\
    radius-interim-update=5m \\
    nas-port-type=wireless-802.11

# Enable RADIUS for login (optional - for admin authentication)
# /user aaa set use-radius=yes

:log info "Onlifi RADIUS configuration applied successfully"
:put "============================================"
:put "Onlifi RADIUS Configuration Complete!"
:put "============================================"
:put "Router Identifier: {$routerIdentifier}"
:put "RADIUS Server: {$serverIp}"
:put "Auth Port: 1812"
:put "Acct Port: 1813"
:put ""
:put "Your hotspot users can now authenticate"
:put "using voucher codes from the Onlifi dashboard."
:put "============================================"
RSC;
    }
    
    /**
     * Get tenant from request
     */
    private function getTenant(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return null;
        }
        
        // If user has tenant_id, get tenant from central database
        if (isset($user->tenant_id)) {
            return DB::connection('central')->table('tenants')
                ->where('id', $user->tenant_id)
                ->where('is_active', true)
                ->first();
        }
        
        // For super admin, get tenant from request parameter
        $tenantId = $request->input('tenant_id');
        if ($tenantId) {
            return DB::connection('central')->table('tenants')
                ->where('id', $tenantId)
                ->first();
        }
        
        return null;
    }
}
