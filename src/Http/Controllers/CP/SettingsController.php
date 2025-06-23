<?php

namespace Rococo\ChLeadGen\Http\Controllers\CP;

use Illuminate\Http\Request;
use Statamic\Http\Controllers\Controller;
use Statamic\Facades\CP\Toast;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    public function index()
    {
        return view('ch-lead-gen::settings', [
            'settings' => config('ch-lead-gen'),
        ]);
    }

    public function update(Request $request)
    {
        try {
            // Handle JSON request
            $data = $request->json()->all();
            Log::info('Raw request data:', $data);
            
            // Let's see what keys we actually have
            Log::info('Available keys:', array_keys($data));
            
            // Validate the received data
            $validated = validator($data, [
                'companies_house_api_key' => 'nullable|string',
                'apollo_api_key' => 'nullable|string',
                'instantly_api_key' => 'nullable|string',
                'schedule.enabled' => 'nullable|boolean',
                'schedule.frequency' => 'nullable|string|in:daily,weekly,monthly',
                'schedule.time' => 'nullable|string',
                'search.months_ago' => 'nullable|integer|min:1|max:12',
                'search.company_status' => 'nullable|string',
                'search.company_type' => 'nullable|string',
                'search.allowed_countries' => 'nullable|array',
                'instantly.lead_list_name' => 'nullable|string',
                'instantly.enable_enrichment' => 'nullable|boolean',
                'logging.enabled' => 'nullable|boolean',
                'logging.retention_days' => 'nullable|integer|min:1',
            ])->validate();
            
            // Laravel validator removes fields that fail validation, let's manually include all fields
            $allFields = [
                'companies_house_api_key',
                'apollo_api_key', 
                'instantly_api_key',
                'schedule.enabled',
                'schedule.frequency',
                'schedule.time',
                'search.months_ago',
                'search.company_status',
                'search.company_type',
                'search.allowed_countries',
                'instantly.lead_list_name',
                'instantly.enable_enrichment',
                'logging.enabled',
                'logging.retention_days'
            ];
            
            $validated = [];
            foreach ($allFields as $field) {
                if (array_key_exists($field, $data)) {
                    $validated[$field] = $data[$field];
                }
            }

            Log::info('Settings validation passed:', $validated);

            // Save settings to config file
            $configPath = config_path('ch-lead-gen.php');
            Log::info('Config path:', ['path' => $configPath]);
            
            if (!File::exists($configPath)) {
                Log::info('Config file does not exist, creating it');
                $this->publishConfig();
            }
            
            $config = File::getRequire($configPath);
            Log::info('Current config:', $config);
            
            // Update the config values
            foreach ($validated as $key => $value) {
                if (strpos($key, '.') !== false) {
                    list($section, $field) = explode('.', $key);
                    $config[$section][$field] = $value;
                } else {
                    $config[$key] = $value;
                }
            }

            Log::info('Updated config:', $config);

            // Write the updated config back to the file
            $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
            $writeResult = File::put($configPath, $content);
            Log::info('Config file write result:', ['success' => $writeResult]);

            // Update environment variables for API keys
            if (!empty($validated['companies_house_api_key']) || 
                !empty($validated['apollo_api_key']) || 
                !empty($validated['instantly_api_key'])) {
                
                $envResult = $this->updateEnvFile([
                    'COMPANIES_HOUSE_API_KEY' => $validated['companies_house_api_key'] ?? '',
                    'APOLLO_API_KEY' => $validated['apollo_api_key'] ?? '',
                    'INSTANTLY_API_KEY' => $validated['instantly_api_key'] ?? '',
                ]);
                Log::info('Environment file update result:', ['success' => $envResult]);
            }

            // Clear config cache
            \Artisan::call('config:clear');
            Log::info('Config cache cleared');

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation errors:', $e->errors());
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . implode(', ', array_keys($e->errors())),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Settings update error:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Error updating settings: ' . $e->getMessage()
            ], 500);
        }
    }

    private function publishConfig()
    {
        $sourcePath = __DIR__ . '/../../config/ch-lead-gen.php';
        $targetPath = config_path('ch-lead-gen.php');
        return File::copy($sourcePath, $targetPath);
    }

    private function updateEnvFile($values)
    {
        try {
            $envFile = base_path('.env');
            
            if (!File::exists($envFile)) {
                Log::warning('Environment file does not exist');
                return false;
            }
            
            $envContent = File::get($envFile);

            foreach ($values as $key => $value) {
                // Skip empty values
                if (empty($value)) {
                    continue;
                }
                
                // Escape the value for .env file
                $escapedValue = $this->escapeEnvValue($value);
                
                // If the key exists, replace it
                if (preg_match("/^{$key}=.*/m", $envContent)) {
                    $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$escapedValue}", $envContent);
                    Log::info("Updated existing env key: {$key}");
                } else {
                    // If the key doesn't exist, add it
                    $envContent .= "\n{$key}={$escapedValue}";
                    Log::info("Added new env key: {$key}");
                }
            }

            return File::put($envFile, $envContent);
        } catch (\Exception $e) {
            Log::error('Environment file update error:', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function escapeEnvValue($value)
    {
        // If value contains spaces or special characters, wrap in quotes
        if (preg_match('/\s|[#"\'\\\\]/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }
} 