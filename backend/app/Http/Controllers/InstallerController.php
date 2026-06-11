<?php

namespace App\Http\Controllers;

use App\Models\InstallerDeviceSubmission;
use App\Models\Site;
use App\Models\SystemSetting;
use App\Models\TenantUser;
use App\Support\TenantRouterSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class InstallerController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid login details.'], 422);
        }

        $installer = TenantUser::with('tenant')
            ->where('email', $request->email)
            ->first();

        if (!$installer || !Hash::check($request->password, $installer->password)) {
            return response()->json(['message' => 'Invalid installer credentials.'], 401);
        }

        if ($installer->role !== 'installer' || !$installer->is_active || !$installer->tenant?->canAccess()) {
            return response()->json(['message' => 'This account is not allowed to use the installer app.'], 403);
        }

        $site = $this->assignedSite($installer);
        if (!$site) {
            return response()->json(['message' => 'No site is assigned to this installer.'], 403);
        }

        $installer->tokens()->where('name', 'installer-mobile')->delete();
        $token = $installer->createToken('installer-mobile', ['installer:devices:create'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'installer_id' => (string) $installer->id,
            'installer_name' => $installer->name,
            'site_id' => $site->id,
            'site_name' => $site->name,
            'assigned_device_ip_range' => $site->assigned_device_ip_range,
        ]);
    }

    public function storeDevice(Request $request)
    {
        $installer = $request->user();
        if (!$installer || $installer->role !== 'installer' || !$request->user()->tokenCan('installer:devices:create')) {
            return response()->json(['message' => 'Installer token required.'], 403);
        }

        $site = $this->assignedSite($installer);
        if (!$site) {
            return response()->json(['message' => 'No site is assigned to this installer.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'local_id' => 'required|string|max:100',
            'device_name' => 'required|string|max:100',
            'ip_address' => 'required|ipv4',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'notes' => 'nullable|string|max:5000',
            'created_at_device' => 'nullable|numeric',
            'front_photo' => 'required|image|max:8192',
            'back_photo' => 'required|image|max:8192',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $existing = InstallerDeviceSubmission::where('installer_user_id', $installer->id)
            ->where('local_id', $request->local_id)
            ->first();
        if ($existing) {
            return response()->json([
                'id' => $existing->id,
                'router_id' => $existing->router_id,
                'status' => 'exists',
            ]);
        }

        if ($site->assigned_device_ip_range && !$this->ipInCidr($request->ip_address, $site->assigned_device_ip_range)) {
            return response()->json([
                'message' => 'The device IP is outside the assigned device IP range for this site.',
                'assigned_device_ip_range' => $site->assigned_device_ip_range,
            ], 422);
        }

        $ipConflict = InstallerDeviceSubmission::where('tenant_id', $installer->tenant_id)
            ->where('ip_address', $request->ip_address)
            ->exists();
        if ($ipConflict) {
            return response()->json(['message' => 'This IP address already belongs to another submitted device.'], 409);
        }

        $tenant = $installer->tenant;
        $site->configureTenantConnection($tenant);

        if (!Schema::connection('tenant')->hasTable('mikrotik_routers')) {
            return response()->json(['message' => 'The tenant device table is not ready yet.'], 503);
        }

        TenantRouterSchema::ensureForSite($site);

        $routerQuery = DB::connection('tenant')->table('mikrotik_routers')->where('ip_address', $request->ip_address);
        $routerConflict = (clone $routerQuery)->first();
        if ($routerConflict) {
            return response()->json(['message' => 'This IP address already belongs to another device.'], 409);
        }

        $frontPath = $request->file('front_photo')->store('installer-submissions/front');
        $backPath = $request->file('back_photo')->store('installer-submissions/back');

        $submission = DB::connection('central')->transaction(function () use ($request, $installer, $site, $frontPath, $backPath) {
            return InstallerDeviceSubmission::create([
                'tenant_id' => $installer->tenant_id,
                'site_id' => $site->id,
                'installer_user_id' => $installer->id,
                'local_id' => $request->local_id,
                'device_name' => $request->device_name,
                'ip_address' => $request->ip_address,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'front_photo_path' => $frontPath,
                'back_photo_path' => $backPath,
                'notes' => $request->notes,
                'mobile_created_at' => $this->mobileCreatedAt($request->created_at_device),
            ]);
        });

        $routerId = $this->createTenantDevice($site, $installer, $submission);
        $submission->update(['router_id' => $routerId]);

        return response()->json([
            'id' => $submission->id,
            'router_id' => $routerId,
            'status' => 'created',
        ], 201);
    }

    private function assignedSite(TenantUser $installer): ?Site
    {
        $siteId = collect($installer->allowed_site_ids ?: [])->first();

        return $siteId
            ? Site::where('tenant_id', $installer->tenant_id)->where('id', $siteId)->first()
            : null;
    }

    private function createTenantDevice(Site $site, TenantUser $installer, InstallerDeviceSubmission $submission): int
    {
        TenantRouterSchema::ensureForSite($site);

        $columns = Schema::connection('tenant')->getColumnListing('mikrotik_routers');
        $data = [
            'name' => $submission->device_name,
            'ip_address' => $submission->ip_address,
            'api_port' => 8728,
            'username' => SystemSetting::get('router_admin_username', 'onlifi'),
            'password' => SystemSetting::get('router_admin_password', 'onlifi-router-admin-change-me'),
            'location' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $optional = [
            'site_id' => $site->id,
            'latitude' => $submission->latitude,
            'longitude' => $submission->longitude,
            'installed_by_user_id' => $installer->id,
            'installed_at' => now(),
            'installer_submission_id' => $submission->id,
        ];

        foreach ($optional as $column => $value) {
            if (in_array($column, $columns, true)) {
                $data[$column] = $value;
            }
        }

        return (int) DB::connection('tenant')->table('mikrotik_routers')->insertGetId($data);
    }

    private function mobileCreatedAt($value)
    {
        if (!$value) {
            return null;
        }

        $timestamp = (int) $value;
        if ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        return now()->setTimestamp($timestamp);
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $bits = (int) $bits;

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
