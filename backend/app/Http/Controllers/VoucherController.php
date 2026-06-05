<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\VoucherGroup;
use App\Models\VoucherTemplate;
use App\Models\VoucherType;
use App\Services\FreeRadiusService;
use App\Services\VoucherService;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class VoucherController extends Controller
{
    private $voucherService;

    public function __construct(VoucherService $voucherService)
    {
        $this->voucherService = $voucherService;
    }

    private function resolveVoucherSite(Request $request)
    {
        $site = SiteScope::selectedOrDefaultSite($request);
        $legacySite = SiteScope::defaultSite($request);

        SiteScope::backfillLegacyTenantSite($legacySite, [
            'voucher_types',
            'voucher_sales_points',
            'voucher_groups',
            'vouchers',
            'transactions',
        ]);

        return $site;
    }

    public function index(Request $request)
    {
        $query = Voucher::with(['group', 'salesPoint']);
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'total' => 0,
                'per_page' => (int) ($request->per_page ?? 50),
            ]);
        }
        SiteScope::applyToTenantTable($query, 'vouchers', $site);

        if ($request->status === 'consumed') {
            $query->whereIn('status', ['in_use', 'used', 'expired']);
        } elseif ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('group_id')) {
            $query->where('group_id', $request->group_id);
        }

        $vouchers = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json($vouchers);
    }

    public function show($id)
    {
        $site = $this->resolveVoucherSite(request());
        if (!$site) {
            abort(404);
        }
        $query = Voucher::with(['group', 'salesPoint', 'transactions']);
        SiteScope::applyToTenantTable($query, 'vouchers', $site);
        $voucher = $query->findOrFail($id);
        return response()->json($voucher);
    }

    public function generateBatch(Request $request)
    {
        // Increase execution time for large batches
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '512M');
        
        $validator = Validator::make($request->all(), [
            'group_name' => 'required|string|max:100',
            'profile_name' => 'required|string|max:64',
            'validity_hours' => 'required|integer|min:1',
            'validity_minutes' => 'nullable|integer|min:1',
            'price' => 'required|numeric|min:0',
            'count' => 'required|integer|min:1|max:1000',
            'description' => 'nullable|string',
            'data_limit_mb' => 'nullable|integer',
            'speed_limit_kbps' => 'nullable|integer',
            'sales_point_id' => 'nullable|integer',
            'code_format' => 'nullable|string|in:mixed,numbers,letters',
            'code_length' => 'nullable|integer|min:6|max:16',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $request->all();
            $site = $this->resolveVoucherSite($request);
            if (!$site) {
                return response()->json([
                    'error' => 'Site required',
                    'message' => 'Select a site before creating vouchers.',
                ], 422);
            }

            foreach (['voucher_groups', 'vouchers'] as $table) {
                if (!Schema::connection('tenant')->hasColumn($table, 'site_id')) {
                    return response()->json([
                        'error' => 'Tenant database needs migration',
                        'message' => "The {$table}.site_id column is required before site-specific vouchers can be created.",
                    ], 500);
                }
            }

            if ($site) {
                $data['site_id'] = $site->id;
                $data['site_name'] = $site->name;
            }

            if (!empty($data['sales_point_id']) && Schema::connection('tenant')->hasColumn('voucher_sales_points', 'site_id')) {
                $belongsToSite = DB::connection('tenant')->table('voucher_sales_points')
                    ->where('id', $data['sales_point_id'])
                    ->where('site_id', $site->id)
                    ->exists();

                if (!$belongsToSite) {
                    return response()->json([
                        'error' => 'Invalid sales point',
                        'message' => 'The selected sales point does not belong to the active site.',
                    ], 422);
                }
            }

            $result = $this->voucherService->generateVoucherBatch($data);
            return response()->json($result);
        } catch (\Exception $e) {
            \Log::error('Voucher generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'error' => 'Failed to generate vouchers',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:pdf|max:10240',
            'site' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Upload a valid PDF voucher file.',
                'errors' => $validator->errors()->all(),
            ], 422);
        }

        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json([
                'message' => 'Select a site before importing vouchers.',
                'errors' => ['No active site selected.'],
            ], 422);
        }

        foreach (['voucher_groups', 'vouchers'] as $table) {
            if (!Schema::connection('tenant')->hasTable($table)) {
                return response()->json([
                    'message' => "The {$table} table is missing. Run tenant migrations first.",
                    'errors' => ["Missing tenant table: {$table}."],
                ], 500);
            }
        }

        $file = $request->file('file');
        $text = $this->extractVoucherPdfText($file->getRealPath());
        $codes = $this->extractVoucherCodes($text);
        $errors = [];

        if (empty($codes)) {
            return response()->json([
                'imported' => 0,
                'skipped' => 0,
                'type_detected' => 'unknown',
                'errors' => ['No voucher codes were found in the PDF. Install poppler-utils/pdftotext on the server if this PDF text is compressed.'],
            ], 422);
        }

        $package = $this->detectImportedVoucherPackage($text . ' ' . $file->getClientOriginalName(), $site);
        $existing = Voucher::query()
            ->whereIn('voucher_code', $codes)
            ->pluck('voucher_code')
            ->map(fn ($code) => strtoupper((string) $code))
            ->all();
        $existingSet = array_fill_keys($existing, true);
        $newCodes = [];

        foreach ($codes as $code) {
            if (isset($existingSet[$code])) {
                $errors[] = "Skipped duplicate {$code}";
                continue;
            }
            $newCodes[] = $code;
        }

        if (empty($newCodes)) {
            return response()->json([
                'imported' => 0,
                'skipped' => count($codes),
                'type_detected' => $package['key'],
                'errors' => array_slice($errors, 0, 20),
            ]);
        }

        $now = now();
        $groupData = [
            'group_name' => 'Imported ' . $package['label'] . ' ' . $now->format('Y-m-d H:i'),
            'description' => 'Imported from ' . $file->getClientOriginalName(),
            'profile_name' => $package['profile_name'],
            'validity_hours' => $package['validity_hours'],
            'validity_minutes' => $package['validity_minutes'],
            'data_limit_mb' => null,
            'speed_limit_kbps' => null,
            'price' => $package['price'],
            'site_id' => $site->id,
            'created_by' => 'import',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $groupData = SiteScope::tenantCompatColumns('voucher_groups', $groupData);
        if (!Schema::connection('tenant')->hasColumn('voucher_groups', 'site_id')) {
            unset($groupData['site_id']);
        }
        if (!Schema::connection('tenant')->hasColumn('voucher_groups', 'validity_minutes')) {
            unset($groupData['validity_minutes']);
        }

        $radiusSynced = 0;

        try {
            $group = null;
            DB::connection('tenant')->transaction(function () use (&$group, $groupData, $newCodes, $package, $site, $now) {
                $group = VoucherGroup::create($groupData);
                $rows = [];

                foreach ($newCodes as $code) {
                    $row = [
                        'voucher_code' => $code,
                        'password' => $code,
                        'group_id' => $group->id,
                        'profile_name' => $group->profile_name,
                        'validity_hours' => $group->validity_hours,
                        'validity_minutes' => $package['validity_minutes'],
                        'data_limit_mb' => null,
                        'speed_limit_kbps' => null,
                        'price' => $group->price,
                        'sales_point_id' => null,
                        'site_id' => $site->id,
                        'status' => 'unused',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $row = SiteScope::tenantCompatColumns('vouchers', $row);
                    if (!Schema::connection('tenant')->hasColumn('vouchers', 'site_id')) {
                        unset($row['site_id']);
                    }
                    if (!Schema::connection('tenant')->hasColumn('vouchers', 'validity_minutes')) {
                        unset($row['validity_minutes']);
                    }
                    $rows[] = $row;
                }

                foreach (array_chunk($rows, 250) as $chunk) {
                    Voucher::insert($chunk);
                }
            });

            $importedVouchers = Voucher::where('group_id', $group->id)->get();

            try {
                $sync = app(FreeRadiusService::class)->syncBatchToRadius($importedVouchers);
                $radiusSynced = (int) ($sync['synced'] ?? 0);
            } catch (\Throwable $e) {
                $errors[] = 'Imported into voucher stock, but RADIUS sync failed: ' . $e->getMessage();
                Log::warning('Imported vouchers RADIUS sync failed', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'imported' => $importedVouchers->count(),
                'skipped' => count($codes) - count($newCodes),
                'type_detected' => $package['key'],
                'errors' => array_slice($errors, 0, 20),
                'radius_synced' => $radiusSynced,
                'group_id' => $group->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Voucher import failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Voucher import failed: ' . $e->getMessage(),
                'errors' => [$e->getMessage()],
            ], 500);
        }
    }

    private function extractVoucherPdfText(string $path): string
    {
        $text = '';

        if (function_exists('proc_open')) {
            $command = ['pdftotext', '-layout', $path, '-'];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = @proc_open($command, $descriptors, $pipes);

            if (is_resource($process)) {
                fclose($pipes[0]);
                $text = stream_get_contents($pipes[1]) ?: '';
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
        }

        if (trim($text) !== '') {
            return $text;
        }

        $raw = @file_get_contents($path) ?: '';
        $decoded = $raw;

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                $inflated = @gzuncompress(trim($stream));
                if ($inflated !== false) {
                    $decoded .= "\n" . $inflated;
                }
            }
        }

        return $decoded;
    }

    private function extractVoucherCodes(string $text): array
    {
        preg_match_all('/\b[A-Z0-9]{4,12}\b/i', strtoupper($text), $matches);

        $blocked = array_fill_keys([
            'ONLIFI', 'WIFI', 'VOUCHER', 'CODE', 'PRICE', 'UGX', 'VALID', 'HOURS',
            'HOUR', 'DAYS', 'DAY', 'MONTH', 'WEEK', 'POWERED', 'SUPPORT', 'LOGIN',
            'PASSWORD', 'USERNAME', 'PACKAGE', 'SALES', 'POINT', 'DEFAULT',
            '1000', '2000', '5000', '6000', '25000', '40000', '2025', '2026',
            '1440', '10080', '43200',
        ], true);

        return collect($matches[0] ?? [])
            ->map(fn ($code) => strtoupper(trim($code)))
            ->filter(fn ($code) => preg_match('/\d/', $code) === 1)
            ->reject(fn ($code) => isset($blocked[$code]))
            ->reject(fn ($code) => preg_match('/^0+$/', $code) === 1)
            ->unique()
            ->values()
            ->all();
    }

    private function detectImportedVoucherPackage(string $text, $site): array
    {
        $normalized = strtolower($text);
        $detected = match (true) {
            str_contains($normalized, '30days') || str_contains($normalized, '30 days') || str_contains($normalized, 'monthly') => '30days',
            str_contains($normalized, '7days') || str_contains($normalized, '7 days') || str_contains($normalized, 'week') => '7days',
            str_contains($normalized, '24hours') || str_contains($normalized, '24 hours') || str_contains($normalized, 'daily') => '24hours',
            str_contains($normalized, '12hours') || str_contains($normalized, '12 hours') => '12hours',
            str_contains($normalized, '3hours') || str_contains($normalized, '3 hours') => '3hours',
            str_contains($normalized, '2hours') || str_contains($normalized, '2 hours') => '2hours',
            default => 'unknown',
        };

        $fallback = [
            '2hours' => ['label' => '2 Hours', 'profile_name' => '2hours', 'validity_hours' => 2, 'validity_minutes' => 120, 'price' => 500],
            '3hours' => ['label' => '3 Hours', 'profile_name' => '3hours', 'validity_hours' => 3, 'validity_minutes' => 180, 'price' => 1000],
            '12hours' => ['label' => '12 Hours', 'profile_name' => '12hours', 'validity_hours' => 12, 'validity_minutes' => 720, 'price' => 1000],
            '24hours' => ['label' => '24 Hours', 'profile_name' => 'Daily', 'validity_hours' => 24, 'validity_minutes' => 1440, 'price' => 1000],
            '7days' => ['label' => '7 Days', 'profile_name' => 'week', 'validity_hours' => 168, 'validity_minutes' => 10080, 'price' => 6000],
            '30days' => ['label' => '30 Days', 'profile_name' => 'Monthly', 'validity_hours' => 720, 'validity_minutes' => 43200, 'price' => 25000],
            'unknown' => ['label' => 'Imported', 'profile_name' => 'default', 'validity_hours' => 24, 'validity_minutes' => 1440, 'price' => 0],
        ];

        $package = $fallback[$detected];

        if (Schema::connection('tenant')->hasTable('voucher_types')) {
            $typeQuery = VoucherType::query();
            SiteScope::applyToTenantTable($typeQuery, 'voucher_types', $site);
            $type = $typeQuery
                ->where(function ($query) use ($package, $detected) {
                    $query->where('type_name', 'like', '%' . $package['label'] . '%')
                        ->orWhere('type_name', 'like', '%' . $detected . '%')
                        ->orWhere('duration_hours', $package['validity_hours']);
                })
                ->orderByDesc('is_active')
                ->first();

            if ($type) {
                $package['label'] = $type->type_name;
                $package['profile_name'] = $type->type_name;
                $package['validity_hours'] = (int) $type->duration_hours;
                $package['validity_minutes'] = $type->validity_minutes ?: ((int) $type->duration_hours * 60);
                $package['price'] = (float) $type->base_amount;
            }
        }

        return ['key' => $detected] + $package;
    }

    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'voucher_code' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'valid' => false,
                'error' => 'Validation failed',
            ], 422);
        }

        $voucher = Voucher::where('voucher_code', $request->voucher_code)
            ->where('password', $request->password)
            ->first();

        if (!$voucher) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid voucher code or password',
            ]);
        }

        if (in_array($voucher->status, ['used', 'expired']) || ($voucher->expires_at && $voucher->expires_at->isPast())) {
            return response()->json([
                'valid' => false,
                'message' => 'Voucher has expired',
            ]);
        }

        if ($voucher->status === 'disabled') {
            return response()->json([
                'valid' => false,
                'message' => 'Voucher has been disabled',
            ]);
        }

        return response()->json([
            'valid' => true,
            'voucher' => $voucher,
        ]);
    }

    public function getTypes(Request $request)
    {
        $query = VoucherType::query();
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json(['types' => []]);
        }
        SiteScope::applyToTenantTable($query, 'voucher_types', $site);

        if (Schema::connection('tenant')->hasColumn('voucher_types', 'tenant_id') && app()->bound('tenant')) {
            $query->where(function ($q) {
                $q->where('tenant_id', app('tenant')->id)->orWhereNull('tenant_id');
            });
        }

        $types = $query->orderBy('type_name')->get()->map(function ($type) use ($site) {
            $groupQuery = VoucherGroup::query();
            SiteScope::applyToTenantTable($groupQuery, 'voucher_groups', $site);
            $groupQuery
                ->where('validity_hours', $type->duration_hours)
                ->where('price', $type->base_amount);

            if (Schema::connection('tenant')->hasColumn('voucher_groups', 'validity_minutes') && Schema::connection('tenant')->hasColumn('voucher_types', 'validity_minutes')) {
                $groupQuery->where(function ($query) use ($type) {
                    if ($type->validity_minutes) {
                        $query->where('validity_minutes', $type->validity_minutes)
                            ->orWhereNull('validity_minutes');
                    } else {
                        $query->whereNull('validity_minutes');
                    }
                });
            }

            foreach (['data_limit_mb', 'speed_limit_kbps'] as $column) {
                if ($type->{$column} === null) {
                    $groupQuery->whereNull($column);
                } else {
                    $groupQuery->where($column, $type->{$column});
                }
            }

            $groupIds = $groupQuery->pluck('id');
            $voucherQuery = Voucher::query()->whereIn('group_id', $groupIds);
            SiteScope::applyToTenantTable($voucherQuery, 'vouchers', $site);

            $type->total_vouchers = (clone $voucherQuery)->count();
            $type->unused_count = (clone $voucherQuery)->where('status', 'unused')->count();
            $type->used_count = (clone $voucherQuery)->whereIn('status', ['in_use', 'used', 'expired'])->count();

            return $type;
        });
        return response()->json(['types' => $types]);
    }

    public function storeType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type_name' => 'required|string|max:100',
            'duration_hours' => 'required|integer|min:1',
            'validity_minutes' => 'nullable|integer|min:1',
            'base_amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
            'data_limit_mb' => 'nullable|integer|min:0',
            'speed_limit_kbps' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = [
            'type_name' => $request->type_name,
            'duration_hours' => $request->duration_hours,
            'validity_minutes' => $request->validity_minutes,
            'base_amount' => $request->base_amount,
            'description' => $request->description,
            'data_limit_mb' => $request->data_limit_mb,
            'speed_limit_kbps' => $request->speed_limit_kbps,
            'is_active' => true,
        ];

        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json([
                'error' => 'Site required',
                'message' => 'Select a site before creating voucher types.',
            ], 422);
        }
        if (!Schema::connection('tenant')->hasColumn('voucher_types', 'site_id')) {
            return response()->json([
                'error' => 'Tenant database needs migration',
                'message' => 'The voucher_types.site_id column is required before site-specific voucher types can be created.',
            ], 500);
        }

        if (Schema::connection('tenant')->hasColumn('voucher_types', 'tenant_id') && app()->bound('tenant')) {
            $data['tenant_id'] = app('tenant')->id;
        }
        $data = SiteScope::withSiteColumn('voucher_types', $data, $site);
        if (!Schema::connection('tenant')->hasColumn('voucher_types', 'validity_minutes')) {
            unset($data['validity_minutes']);
        }

        $type = VoucherType::create($data);

        return response()->json([
            'message' => 'Voucher type created successfully',
            'type' => $type,
        ], 201);
    }

    public function updateType(Request $request, $id)
    {
        $query = VoucherType::query();
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            abort(404);
        }
        SiteScope::applyToTenantTable($query, 'voucher_types', $site);
        $type = $query->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'type_name' => 'sometimes|string|max:100',
            'duration_hours' => 'sometimes|integer|min:1',
            'validity_minutes' => 'nullable|integer|min:1',
            'base_amount' => 'sometimes|numeric|min:0',
            'description' => 'nullable|string|max:255',
            'data_limit_mb' => 'nullable|integer|min:0',
            'speed_limit_kbps' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only([
            'type_name', 'duration_hours', 'validity_minutes', 'base_amount', 'description',
            'data_limit_mb', 'speed_limit_kbps', 'is_active'
        ]);
        if (!Schema::connection('tenant')->hasColumn('voucher_types', 'validity_minutes')) {
            unset($updateData['validity_minutes']);
        }

        $type->update($updateData);

        return response()->json([
            'message' => 'Voucher type updated successfully',
            'type' => $type->fresh(),
        ]);
    }

    public function destroyType(Request $request, $id)
    {
        $query = VoucherType::query();
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            abort(404);
        }
        SiteScope::applyToTenantTable($query, 'voucher_types', $site);
        $type = $query->findOrFail($id);
        $type->delete();

        return response()->json([
            'message' => 'Voucher type deleted successfully',
        ]);
    }

    public function getGroups(Request $request)
    {
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json([]);
        }
        $query = VoucherGroup::with('salesPoint');
        SiteScope::applyToTenantTable($query, 'voucher_groups', $site);

        if (Schema::connection('tenant')->hasColumn('voucher_groups', 'tenant_id') && app()->bound('tenant')) {
            $query->where(function ($q) {
                $q->where('tenant_id', app('tenant')->id)->orWhereNull('tenant_id');
            });
        }

        $groups = $query
            ->withCount([
                'vouchers as total_vouchers' => function ($query) use ($site) {
                    SiteScope::applyToTenantTable($query, 'vouchers', $site);
                },
                'vouchers as unused_count' => function ($query) use ($site) {
                    SiteScope::applyToTenantTable($query, 'vouchers', $site);
                    $query->where('status', 'unused');
                },
                'vouchers as used_count' => function ($query) use ($site) {
                    SiteScope::applyToTenantTable($query, 'vouchers', $site);
                    $query->where('status', 'used');
                },
                'vouchers as in_use_count' => function ($query) use ($site) {
                    SiteScope::applyToTenantTable($query, 'vouchers', $site);
                    $query->whereIn('status', ['in_use', 'used', 'expired']);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($group) {
                $group->sales_point_name = $group->salesPoint?->name;
                return $group;
            });
        return response()->json($groups);
    }

    public function exportGroupPdf(Request $request, $id)
    {
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            abort(404);
        }

        $groupQuery = VoucherGroup::with('salesPoint');
        SiteScope::applyToTenantTable($groupQuery, 'voucher_groups', $site);
        $group = $groupQuery->findOrFail($id);

        $voucherQuery = Voucher::with(['group', 'salesPoint'])->where('group_id', $group->id);
        SiteScope::applyToTenantTable($voucherQuery, 'vouchers', $site);

        $status = $request->query('status', 'unused');
        if ($status === 'consumed') {
            $voucherQuery->whereIn('status', ['in_use', 'used', 'expired']);
        } elseif ($status && $status !== 'all') {
            $voucherQuery->where('status', $status);
        }

        $vouchers = $voucherQuery->orderBy('id')->limit(5000)->get();
        $template = $this->activeVoucherTemplate($site);
        $html = $this->voucherPdfHtml($group, $vouchers, $template);

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($template['paper_size'] ?? 'A4', 'landscape');
        $dompdf->render();

        $filename = Str::slug($group->group_name ?: 'vouchers') . '-' . ($status ?: 'all') . '.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function activeVoucherTemplate($site): array
    {
        $tenant = app('tenant');
        $query = VoucherTemplate::where('tenant_id', $tenant->id)
            ->where('is_active', true);

        if ($site && Schema::connection('central')->hasColumn('voucher_templates', 'site_id')) {
            $query->where('site_id', $site->id);
        }

        $template = (clone $query)->where('is_default', true)->first()
            ?: (clone $query)->orderBy('name')->first();

        return $template?->toArray() ?: [
            'name' => 'Default Blue Strip',
            'layout' => 'grid-2x4',
            'paper_size' => 'A4',
            'design' => ['style' => 'blue-strip', 'numbering' => true],
            'background_color' => '#ffffff',
            'text_color' => '#1f2937',
            'accent_color' => '#0444cf',
            'show_voucher_code' => true,
            'show_voucher_type' => true,
            'show_sales_point' => true,
            'show_duration' => true,
            'show_price' => true,
            'show_expiry' => false,
            'show_qr_code' => false,
            'header_text' => 'WIFI NAME',
            'footer_text' => 'Support: +256 700 000 000',
            'instructions' => 'One device per voucher.',
        ];
    }

    private function voucherPdfHtml(VoucherGroup $group, $vouchers, array $template): string
    {
        $design = is_array($template['design'] ?? null) ? $template['design'] : [];
        $style = $design['style'] ?? 'blue-strip';
        [$columns, $cardWidth, $cardHeight] = match ($template['layout'] ?? 'grid-2x4') {
            'single' => [1, '170mm', '250mm'],
            'grid-2x2' => [2, '92mm', '120mm'],
            'grid-3x3' => [3, '62mm', '82mm'],
            'grid-4x5' => [4, '47mm', '52mm'],
            'grid-5x8' => [5, '37mm', '32mm'],
            'grid-8x10' => [8, '23mm', '25mm'],
            default => [2, '92mm', '68mm'],
        };
        $dense = in_array($template['layout'] ?? 'grid-2x4', ['grid-4x5', 'grid-5x8', 'grid-8x10'], true);

        $accent = e($template['accent_color'] ?? '#0444cf');
        $text = e($template['text_color'] ?? '#1f2937');
        $background = e($template['background_color'] ?? '#ffffff');
        $header = e($template['header_text'] ?? 'WIFI NAME');
        $footer = e($template['footer_text'] ?? '');
        $instructions = e($template['instructions'] ?? '');
        $gradient = $style === 'modern-blue' ? "linear-gradient(90deg, {$accent}, #0666ff)" : $accent;

        $cards = $vouchers->values()->map(function (Voucher $voucher, int $index) use ($template, $group, $style, $header, $footer, $instructions, $design) {
            $number = '#' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $duration = $voucher->validity_minutes
                ? $voucher->validity_minutes . ' mins'
                : ($voucher->validity_hours ?: $group->validity_hours) . 'h';
            $price = 'UGX ' . number_format((float) ($voucher->price ?: $group->price));
            $salesPoint = $voucher->salesPoint?->name ?: $group->salesPoint?->name;
            $code = e($voucher->voucher_code);

            return '
                <div class="voucher-card">
                    <div class="voucher-header">
                        ' . (($design['numbering'] ?? true) !== false ? '<span class="voucher-number">' . $number . '</span>' : '') . '
                        ' . $header . '
                    </div>
                    <div class="voucher-code-panel">
                        <span>Voucher Code</span>
                        <strong>' . $code . '</strong>
                    </div>
                    <div class="voucher-details">
                        ' . (($template['show_voucher_type'] ?? true) ? '<div><span>Package</span><strong>' . e($group->group_name) . '</strong></div>' : '') . '
                        ' . (($template['show_duration'] ?? true) ? '<div><span>Duration</span><strong>' . e($duration) . '</strong></div>' : '') . '
                        ' . (($template['show_price'] ?? true) ? '<div><span>Price</span><strong>' . e($price) . '</strong></div>' : '') . '
                        ' . (($template['show_sales_point'] ?? true) && $salesPoint ? '<div><span>Sales Point</span><strong>' . e($salesPoint) . '</strong></div>' : '') . '
                    </div>
                    <div class="voucher-footer-block">
                        ' . ($instructions ? '<div class="voucher-instructions">' . $instructions . '</div>' : '') . '
                        ' . ($footer ? '<div class="voucher-footer">' . $footer . '</div>' : '') . '
                        <div class="powered">Powered by onlifi.net</div>
                    </div>
                </div>
            ';
        })->implode('');

        return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { size: ' . e($template['paper_size'] ?? 'A4') . ' landscape; margin: ' . ($dense ? '5mm' : '10mm') . '; }
body { margin: 0; font-family: DejaVu Sans, Arial, sans-serif; color: ' . $text . '; }
.voucher-grid { font-size: 0; }
.voucher-card {
    position: relative;
    display: inline-block;
    vertical-align: top;
    width: ' . $cardWidth . ';
    min-height: ' . $cardHeight . ';
    margin: ' . ($dense ? '1mm' : '2.5mm') . ';
    box-sizing: border-box;
    overflow: hidden;
    border: 1.5px solid ' . $accent . ';
    border-radius: ' . ($dense ? '4px' : '7px') . ';
    background: ' . $background . ';
    font-size: ' . ($dense ? '6px' : '11px') . ';
}
.voucher-header {
    position: relative;
    padding: ' . ($dense ? '2px 3px' : '6px 8px') . ';
    text-align: center;
    color: #fff;
    font-weight: 700;
    background: ' . $gradient . ';
}
.voucher-number {
    position: absolute;
    left: ' . ($dense ? '3px' : '7px') . ';
    top: ' . ($dense ? '2px' : '6px') . ';
    font-size: ' . ($dense ? '5px' : '9px') . ';
    padding: 1px ' . ($dense ? '2px' : '5px') . ';
    border-radius: 3px;
    background: rgba(255,255,255,.22);
}
.voucher-code-panel {
    margin: ' . ($dense ? '2px 3px' : '8px 10px 6px') . ';
    padding: ' . ($dense ? '2px 3px' : '6px 8px') . ';
    border: 1px solid rgba(15,23,42,.16);
    border-radius: ' . ($dense ? '3px' : '5px') . ';
    background: rgba(15,23,42,.035);
    text-align: center;
}
.voucher-code-panel span { display: block; color: #64748b; font-size: ' . ($dense ? '4px' : '7px') . '; font-weight: 700; text-transform: uppercase; margin-bottom: ' . ($dense ? '1px' : '4px') . '; }
.voucher-code-panel strong { display: block; color: ' . $accent . '; font-size: ' . ($dense ? '8px' : '20px') . '; line-height: 1.15; font-weight: 800; word-break: break-all; }
.voucher-details { margin: 0 ' . ($dense ? '3px' : '10px') . '; }
.voucher-details div { display: inline-block; width: 48%; margin-bottom: ' . ($dense ? '1px' : '4px') . '; vertical-align: top; }
.voucher-details span { display: block; font-size: ' . ($dense ? '4.5px' : '8px') . '; opacity: .7; }
.voucher-details strong { display: block; font-size: ' . ($dense ? '5.5px' : '10px') . '; }
.voucher-footer-block { margin: ' . ($dense ? '2px 3px 3px' : '6px 10px 8px') . '; padding-top: ' . ($dense ? '1px' : '4px') . '; border-top: 1px solid rgba(15,23,42,.16); text-align: center; }
.voucher-instructions { margin: 0 0 ' . ($dense ? '1px' : '4px') . '; font-size: ' . ($dense ? '4.5px' : '8px') . '; line-height: 1.15; opacity: .78; }
.voucher-footer { margin: 0 0 ' . ($dense ? '1px' : '3px') . '; font-size: ' . ($dense ? '4.8px' : '8px') . '; line-height: 1.15; opacity: .72; }
.powered { color: ' . $accent . '; font-size: ' . ($dense ? '4.5px' : '8px') . '; line-height: 1.15; text-align: center; font-weight: 700; }
</style>
</head>
<body>
<div class="voucher-grid" style="columns: ' . $columns . ';">' . $cards . '</div>
</body>
</html>';
    }

    public function statistics(Request $request)
    {
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json([
                'total_vouchers' => 0,
                'unused_vouchers' => 0,
                'in_use_vouchers' => 0,
                'reserved_vouchers' => 0,
                'used_vouchers' => 0,
                'consumed_vouchers' => 0,
                'expired_vouchers' => 0,
                'total_revenue' => 0,
                'revenue_30_days' => 0,
                'vouchers_by_status' => [],
                'daily' => [],
                'by_sales_point' => [],
            ]);
        }
        $salesPointId = $request->integer('sales_point_id') ?: null;

        if ($salesPointId && Schema::connection('tenant')->hasColumn('voucher_sales_points', 'site_id')) {
            $belongsToSite = DB::connection('tenant')->table('voucher_sales_points')
                ->where('id', $salesPointId)
                ->where('site_id', $site->id)
                ->exists();

            if (!$belongsToSite) {
                return response()->json([
                    'error' => 'Invalid sales point',
                    'message' => 'The selected sales point does not belong to the active site.',
                ], 422);
            }
        }

        $voucherQuery = Voucher::query();
        SiteScope::applyToTenantTable($voucherQuery, 'vouchers', $site);
        if ($salesPointId) {
            $voucherQuery->where('sales_point_id', $salesPointId);
        }

        // Overall statistics
        $stats = [
            'total_vouchers' => (clone $voucherQuery)->count(),
            'unused_vouchers' => (clone $voucherQuery)->unused()->count(),
            'reserved_vouchers' => (clone $voucherQuery)->where('status', 'reserved')->count(),
            'in_use_vouchers' => (clone $voucherQuery)->where('status', 'in_use')->count(),
            'used_vouchers' => (clone $voucherQuery)->used()->count(),
            'consumed_vouchers' => (clone $voucherQuery)->whereIn('status', ['in_use', 'used', 'expired'])->count(),
            'expired_vouchers' => (clone $voucherQuery)->expired()->count(),
            'total_revenue' => (clone $voucherQuery)->whereNotNull('first_used_at')->sum('price'),
            'revenue_30_days' => (clone $voucherQuery)
                ->whereNotNull('first_used_at')
                ->where('first_used_at', '>=', now()->subDays(30))
                ->sum('price'),
            'vouchers_by_status' => (clone $voucherQuery)->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get(),
        ];

        // Daily statistics (last 30 days)
        $dailyQuery = Voucher::selectRaw('DATE(first_used_at) as date, COUNT(*) as vouchers_used, SUM(price) as revenue, COUNT(DISTINCT used_by_mac) as unique_devices');
        SiteScope::applyToTenantTable($dailyQuery, 'vouchers', $site);
        if ($salesPointId) {
            $dailyQuery->where('sales_point_id', $salesPointId);
        }
        $stats['daily'] = $dailyQuery
            ->whereNotNull('first_used_at')
            ->where('first_used_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($day) {
                return [
                    'date' => $day->date,
                    'vouchers_used' => (int) $day->vouchers_used,
                    'revenue' => (float) $day->revenue,
                    'unique_devices' => (int) $day->unique_devices,
                ];
            });

        // Statistics by sales point - use try/catch to handle missing tables gracefully
        try {
            $salesQuery = VoucherGroup::join('vouchers', 'voucher_groups.id', '=', 'vouchers.group_id')
                ->join('voucher_sales_points', 'voucher_groups.sales_point_id', '=', 'voucher_sales_points.id')
                ->selectRaw('
                    voucher_sales_points.id,
                    voucher_sales_points.name,
                    COUNT(vouchers.id) as total_vouchers,
                    SUM(CASE WHEN vouchers.status = "unused" THEN 1 ELSE 0 END) as unused,
                    SUM(CASE WHEN vouchers.status = "reserved" THEN 1 ELSE 0 END) as reserved,
                    SUM(CASE WHEN vouchers.status = "in_use" THEN 1 ELSE 0 END) as in_use,
                    SUM(CASE WHEN vouchers.status = "used" THEN 1 ELSE 0 END) as used,
                    SUM(CASE WHEN vouchers.first_used_at IS NOT NULL THEN vouchers.price ELSE 0 END) as revenue,
                    SUM(CASE WHEN vouchers.first_used_at IS NOT NULL AND vouchers.first_used_at >= ? THEN vouchers.price ELSE 0 END) as revenue_30_days
                ', [now()->subDays(30)]);
            SiteScope::applyToTenantTable($salesQuery, 'voucher_groups', $site);
            if (Schema::connection('tenant')->hasColumn('vouchers', 'site_id')) {
                $salesQuery->where('vouchers.site_id', $site->id);
            }
            if ($salesPointId) {
                $salesQuery->where('voucher_sales_points.id', $salesPointId);
            }
            $stats['by_sales_point'] = $salesQuery
                ->groupBy('voucher_sales_points.id', 'voucher_sales_points.name')
                ->get()
                ->map(function ($point) {
                    return [
                        'id' => (int) $point->id,
                        'name' => $point->name,
                        'total_vouchers' => (int) $point->total_vouchers,
                        'unused' => (int) $point->unused,
                        'reserved' => (int) $point->reserved,
                        'in_use' => (int) $point->in_use,
                        'used' => (int) $point->used,
                        'revenue' => (float) $point->revenue,
                        'revenue_30_days' => (float) $point->revenue_30_days,
                    ];
                });
        } catch (\Exception $e) {
            // If query fails (no data or table issues), return empty array
            $stats['by_sales_point'] = [];
        }

        return response()->json($stats);
    }
}
