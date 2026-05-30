<?php

namespace App\Http\Controllers;

use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PppoeClientController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureTable();
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$site) {
            return response()->json([
                'clients' => [],
                'site' => null,
                'message' => 'Select a site before managing PPPoE clients.',
            ]);
        }

        $clients = DB::connection('tenant')->table('pppoe_clients')
            ->where('site_id', $site->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'clients' => $clients,
            'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureTable();
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$site) {
            return response()->json(['message' => 'Select a site before creating PPPoE clients.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'username' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tenant.pppoe_clients', 'username')->where(fn ($query) => $query->where('site_id', $site?->id)),
            ],
            'password' => 'nullable|string|max:255',
            'profile' => 'nullable|string|max:100',
            'service' => 'nullable|string|max:100',
            'remote_address' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $id = DB::connection('tenant')->table('pppoe_clients')->insertGetId([
            'site_id' => $site?->id,
            'name' => $request->name,
            'username' => $request->username,
            'password' => $request->password,
            'profile' => $request->profile,
            'service' => $request->service,
            'remote_address' => $request->remote_address,
            'phone' => $request->phone,
            'notes' => $request->notes,
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'PPPoE client created',
            'client' => DB::connection('tenant')->table('pppoe_clients')->where('id', $id)->first(),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->ensureTable();
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$site) {
            return response()->json(['message' => 'Select a site before updating PPPoE clients.'], 422);
        }

        $client = $this->clientQuery($site?->id)->where('id', $id)->first();

        if (!$client) {
            return response()->json(['message' => 'PPPoE client not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'username' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('tenant.pppoe_clients', 'username')
                    ->ignore($id)
                    ->where(fn ($query) => $query->where('site_id', $site?->id)),
            ],
            'password' => 'nullable|string|max:255',
            'profile' => 'nullable|string|max:100',
            'service' => 'nullable|string|max:100',
            'remote_address' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'username', 'password', 'profile', 'service', 'remote_address', 'phone', 'notes']);
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        $data['updated_at'] = now();

        DB::connection('tenant')->table('pppoe_clients')->where('id', $id)->update($data);

        return response()->json([
            'message' => 'PPPoE client updated',
            'client' => DB::connection('tenant')->table('pppoe_clients')->where('id', $id)->first(),
        ]);
    }

    public function enable(Request $request, int $id)
    {
        return $this->setActive($request, $id, true);
    }

    public function disable(Request $request, int $id)
    {
        return $this->setActive($request, $id, false);
    }

    public function destroy(Request $request, int $id)
    {
        $this->ensureTable();
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$site) {
            return response()->json(['message' => 'Select a site before deleting PPPoE clients.'], 422);
        }

        $deleted = $this->clientQuery($site?->id)->where('id', $id)->delete();

        return $deleted
            ? response()->json(['message' => 'PPPoE client deleted'])
            : response()->json(['message' => 'PPPoE client not found'], 404);
    }

    private function setActive(Request $request, int $id, bool $active)
    {
        $this->ensureTable();
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$site) {
            return response()->json(['message' => 'Select a site before changing PPPoE client status.'], 422);
        }

        $updated = $this->clientQuery($site?->id)->where('id', $id)->update([
            'is_active' => $active,
            'updated_at' => now(),
        ]);

        return $updated
            ? response()->json(['message' => $active ? 'PPPoE client enabled' : 'PPPoE client disabled'])
            : response()->json(['message' => 'PPPoE client not found'], 404);
    }

    private function clientQuery(?int $siteId)
    {
        return DB::connection('tenant')->table('pppoe_clients')
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId));
    }

    private function ensureTable(): void
    {
        if (Schema::connection('tenant')->hasTable('pppoe_clients')) {
            return;
        }

        Schema::connection('tenant')->create('pppoe_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('name', 100);
            $table->string('username', 100);
            $table->string('password')->nullable();
            $table->string('profile', 100)->nullable();
            $table->string('service', 100)->nullable();
            $table->string('remote_address', 64)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'username']);
        });
    }
}
