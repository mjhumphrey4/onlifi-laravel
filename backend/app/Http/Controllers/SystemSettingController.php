<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SystemSettingController extends Controller
{
    public function index(Request $request)
    {
        $query = SystemSetting::query();

        if ($request->has('group')) {
            $query->where('group', $request->group);
        }

        $settings = $query->orderBy('group')->orderBy('key')->get();

        return response()->json($settings);
    }

    public function groups()
    {
        $groups = SystemSetting::select('group')
            ->distinct()
            ->orderBy('group')
            ->pluck('group');

        return response()->json($groups);
    }

    public function byGroup(string $group)
    {
        $settings = SystemSetting::getByGroup($group);

        return response()->json($settings);
    }

    public function publicSettings()
    {
        $settings = SystemSetting::getPublic();

        return response()->json($settings);
    }

    public function show(string $key)
    {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            return response()->json([
                'error' => 'Setting not found',
            ], 404);
        }

        return response()->json($setting);
    }

    public function update(Request $request, string $key)
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'type' => 'in:string,integer,float,boolean,array,json',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            return response()->json([
                'error' => 'Setting not found',
            ], 404);
        }

        $value = $request->value;
        if (in_array($request->type ?? $setting->type, ['array', 'json']) && is_array($value)) {
            $value = json_encode($value);
        }

        $setting->update([
            'value' => $value,
            'type' => $request->type ?? $setting->type,
            'description' => $request->description ?? $setting->description,
            'is_public' => $request->is_public ?? $setting->is_public,
        ]);

        return response()->json([
            'message' => 'Setting updated successfully',
            'setting' => $setting->fresh(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string|unique:system_settings,key',
            'value' => 'required',
            'type' => 'required|in:string,integer,float,boolean,array,json',
            'group' => 'required|string',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $value = $request->value;
        if (in_array($request->type, ['array', 'json']) && is_array($value)) {
            $value = json_encode($value);
        }

        $setting = SystemSetting::create([
            'key' => $request->key,
            'value' => $value,
            'type' => $request->type,
            'group' => $request->group,
            'description' => $request->description,
            'is_public' => $request->is_public ?? false,
        ]);

        return response()->json([
            'message' => 'Setting created successfully',
            'setting' => $setting,
        ], 201);
    }

    public function destroy(string $key)
    {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            return response()->json([
                'error' => 'Setting not found',
            ], 404);
        }

        $setting->delete();

        return response()->json([
            'message' => 'Setting deleted successfully',
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.key' => 'required|string|exists:system_settings,key',
            'settings.*.value' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        foreach ($request->settings as $settingData) {
            $setting = SystemSetting::where('key', $settingData['key'])->first();
            
            if ($setting) {
                $value = $settingData['value'];
                if (in_array($setting->type, ['array', 'json']) && is_array($value)) {
                    $value = json_encode($value);
                }
                
                $setting->update(['value' => $value]);
            }
        }

        return response()->json([
            'message' => 'Settings updated successfully',
        ]);
    }
}
