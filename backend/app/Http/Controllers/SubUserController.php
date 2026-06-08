<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubUserController extends Controller
{
    private const PERMISSIONS = [
        'view_clients',
        'view_routers',
        'view_transactions',
        'manage_vouchers',
        'installer:devices:create',
    ];

    public function index(Request $request)
    {
        $tenantUser = $request->user();
        abort_unless($tenantUser?->isAdmin(), 403);

        $users = TenantUser::where('tenant_id', $tenantUser->tenant_id)
            ->whereIn('role', ['sub_user', 'installer'])
            ->latest()
            ->get()
            ->map(fn (TenantUser $user) => $this->serialize($user));

        return response()->json(['sub_users' => $users]);
    }

    public function store(Request $request)
    {
        $tenantUser = $request->user();
        abort_unless($tenantUser?->isAdmin(), 403);

        $data = $this->validateInput($request);

        $user = TenantUser::create([
            'tenant_id' => $tenantUser->tenant_id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'allowed_site_ids' => array_values($data['allowed_site_ids']),
            'permissions' => $data['role'] === 'installer' ? ['installer:devices:create'] : array_values($data['permissions']),
            'created_by' => $tenantUser->id,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return response()->json([
            'message' => 'Sub-user created successfully',
            'sub_user' => $this->serialize($user),
        ], 201);
    }

    public function update(Request $request, TenantUser $subUser)
    {
        $tenantUser = $request->user();
        abort_unless($tenantUser?->isAdmin() && (int) $subUser->tenant_id === (int) $tenantUser->tenant_id && in_array($subUser->role, ['sub_user', 'installer'], true), 404);

        $data = $this->validateInput($request, $subUser);
        $updates = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'allowed_site_ids' => array_values($data['allowed_site_ids']),
            'permissions' => $data['role'] === 'installer' ? ['installer:devices:create'] : array_values($data['permissions']),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if (!empty($data['password'])) {
            $updates['password'] = Hash::make($data['password']);
        }

        $subUser->update($updates);

        return response()->json([
            'message' => 'Sub-user updated successfully',
            'sub_user' => $this->serialize($subUser->fresh()),
        ]);
    }

    public function destroy(Request $request, TenantUser $subUser)
    {
        $tenantUser = $request->user();
        abort_unless($tenantUser?->isAdmin() && (int) $subUser->tenant_id === (int) $tenantUser->tenant_id && in_array($subUser->role, ['sub_user', 'installer'], true), 404);

        $subUser->tokens()->delete();
        $subUser->delete();

        return response()->json(['message' => 'Sub-user deleted successfully']);
    }

    private function validateInput(Request $request, ?TenantUser $subUser = null): array
    {
        $tenantId = $request->user()->tenant_id;
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('central.tenant_users', 'email')->ignore($subUser?->id),
            ],
            'password' => [$subUser ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(['sub_user', 'installer'])],
            'allowed_site_ids' => ['required', 'array', 'min:1'],
            'allowed_site_ids.*' => [
                'integer',
                Rule::exists('central.sites', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'permissions' => ['required_if:role,sub_user', 'array'],
            'permissions.*' => ['string', Rule::in(self::PERMISSIONS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validator->validate();

        $data = $validator->validated();
        if (($data['role'] ?? 'sub_user') === 'installer') {
            $data['allowed_site_ids'] = [array_values($data['allowed_site_ids'])[0]];
            $data['permissions'] = ['installer:devices:create'];
        }

        return $data;
    }

    private function serialize(TenantUser $user): array
    {
        $siteIds = $user->allowed_site_ids ?: [];
        $sites = Site::whereIn('id', $siteIds)->get(['id', 'name', 'assigned_device_ip_range']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'allowed_site_ids' => $siteIds,
            'allowed_sites' => $sites,
            'permissions' => $user->permissions ?: [],
            'created_at' => $user->created_at,
        ];
    }
}
