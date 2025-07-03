<?php

namespace Rococo\ChLeadGen\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class RuleConfigService
{
    protected $configPath;

    public function __construct()
    {
        $this->configPath = config_path('ch-lead-gen.php');
    }

    /**
     * Get current configuration array
     */
    public function getConfig(): array
    {
        if (!File::exists($this->configPath)) {
            throw new \Exception("Configuration file not found at: {$this->configPath}");
        }

        return include $this->configPath;
    }

    /**
     * Add a new rule to the configuration
     */
    public function addRule(string $ruleKey, array $ruleConfig): void
    {
        $config = $this->getConfig();
        $config['rules'][$ruleKey] = $ruleConfig;
        $this->writeConfig($config);
    }

    /**
     * Update an existing rule
     */
    public function updateRule(string $ruleKey, array $ruleConfig): void
    {
        $config = $this->getConfig();
        
        if (!isset($config['rules'][$ruleKey])) {
            throw new \Exception("Rule '{$ruleKey}' not found");
        }

        $config['rules'][$ruleKey] = $ruleConfig;
        $this->writeConfig($config);
    }

    /**
     * Delete a rule from the configuration
     */
    public function deleteRule(string $ruleKey): void
    {
        $config = $this->getConfig();
        
        if (!isset($config['rules'][$ruleKey])) {
            throw new \Exception("Rule '{$ruleKey}' not found");
        }

        unset($config['rules'][$ruleKey]);
        $this->writeConfig($config);
    }

    /**
     * Update global settings
     */
    public function updateGlobalSettings(array $settings): void
    {
        $config = $this->getConfig();
        
        // Update specific global settings
        foreach ($settings as $key => $value) {
            if (in_array($key, ['companies_house_api_key', 'apollo_api_key', 'instantly_api_key'])) {
                $config[$key] = $value;
            } elseif (isset($config['defaults']) && array_key_exists($key, $config['defaults'])) {
                $config['defaults'][$key] = $value;
            }
        }
        
        $this->writeConfig($config);
    }

    /**
     * Write configuration array to file
     */
    protected function writeConfig(array $config): void
    {
        try {
            $content = "<?php\n\nreturn " . $this->arrayToPhp($config) . ";\n";
            
            // Create backup of current config
            $backupPath = $this->configPath . '.backup.' . time();
            if (File::exists($this->configPath)) {
                File::copy($this->configPath, $backupPath);
            }
            
            File::put($this->configPath, $content);
            
            // Clear config cache to reload changes
            Artisan::call('config:clear');
            
            Log::info("Configuration updated successfully", ['config_path' => $this->configPath]);
            
        } catch (\Exception $e) {
            Log::error("Failed to write configuration: " . $e->getMessage());
            throw new \Exception("Failed to save configuration: " . $e->getMessage());
        }
    }

    /**
     * Convert array to formatted PHP array string
     */
    protected function arrayToPhp(array $array, int $indent = 1): string
    {
        $lines = [];
        $indentStr = str_repeat('    ', $indent);
        $prevIndentStr = str_repeat('    ', $indent - 1);
        
        $lines[] = '[';
        
        foreach ($array as $key => $value) {
            $keyStr = is_string($key) ? "'{$key}'" : $key;
            
            if (is_array($value)) {
                $lines[] = $indentStr . $keyStr . ' => ' . $this->arrayToPhp($value, $indent + 1) . ',';
            } elseif (is_string($value)) {
                $escapedValue = addslashes($value);
                $lines[] = $indentStr . $keyStr . " => '{$escapedValue}',";
            } elseif (is_bool($value)) {
                $boolStr = $value ? 'true' : 'false';
                $lines[] = $indentStr . $keyStr . " => {$boolStr},";
            } elseif (is_null($value)) {
                $lines[] = $indentStr . $keyStr . ' => null,';
            } else {
                $lines[] = $indentStr . $keyStr . " => {$value},";
            }
        }
        
        $lines[] = $prevIndentStr . ']';
        
        return implode("\n", $lines);
    }

    /**
     * Validate rule configuration
     */
    public function validateRule(array $ruleConfig): array
    {
        $errors = [];
        
        // Required fields
        if (empty($ruleConfig['name'])) {
            $errors[] = 'Rule name is required';
        }
        
        if (!isset($ruleConfig['search_parameters'])) {
            $errors[] = 'Search parameters are required';
        } else {
            $search = $ruleConfig['search_parameters'];
            
            if (isset($search['days_ago'])) {
                if ($search['days_ago'] < 1 || $search['days_ago'] > 1825) {
                    $errors[] = 'Days ago must be between 1 and 1825 (5 years)';
                }
            } elseif (isset($search['months_ago'])) {
                // Legacy support
                if ($search['months_ago'] < 1 || $search['months_ago'] > 60) {
                    $errors[] = 'Months ago must be between 1 and 60';
                }
            } else {
                $errors[] = 'Either days_ago or months_ago must be specified';
            }
            
            if (!isset($search['max_results']) || $search['max_results'] < 0 || $search['max_results'] > 1000) {
                $errors[] = 'Max results must be between 0 and 1000 (0 = use dynamic quota)';
            }
            
            if (!isset($search['allowed_countries']) || !is_array($search['allowed_countries']) || empty($search['allowed_countries'])) {
                $errors[] = 'At least one allowed country must be specified';
            }
        }
        
        if (!isset($ruleConfig['schedule'])) {
            $errors[] = 'Schedule configuration is required';
        } else {
            $schedule = $ruleConfig['schedule'];
            
            if (!in_array($schedule['frequency'] ?? '', ['daily', 'weekly', 'monthly'])) {
                $errors[] = 'Frequency must be daily, weekly, or monthly';
            }
            
            if (!isset($schedule['time']) || !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $schedule['time'])) {
                $errors[] = 'Time must be in HH:MM format';
            }
        }
        
        // Instantly validation - only required if Instantly is enabled
        $instantlyEnabled = $ruleConfig['instantly']['enabled'] ?? false;
        if ($instantlyEnabled) {
            if (!isset($ruleConfig['instantly']['lead_list_name']) || empty($ruleConfig['instantly']['lead_list_name'])) {
                $errors[] = 'Instantly lead list name is required when Instantly integration is enabled';
            }
        }
        
        // Webhook validation
        if (isset($ruleConfig['webhook']['enabled']) && $ruleConfig['webhook']['enabled']) {
            if (!isset($ruleConfig['webhook']['url']) || empty($ruleConfig['webhook']['url'])) {
                $errors[] = 'Webhook URL is required when webhook is enabled';
            } elseif (!filter_var($ruleConfig['webhook']['url'], FILTER_VALIDATE_URL)) {
                $errors[] = 'Webhook URL must be a valid URL';
            }
        }
        
        return $errors;
    }

    /**
     * Get configuration file backup list
     */
    public function getBackups(): array
    {
        $backups = [];
        $directory = dirname($this->configPath);
        $configName = basename($this->configPath, '.php');
        
        $files = File::glob($directory . '/' . $configName . '.backup.*');
        
        foreach ($files as $file) {
            $timestamp = (int) str_replace($directory . '/' . $configName . '.backup.', '', $file);
            $backups[] = [
                'file' => $file,
                'timestamp' => $timestamp,
                'date' => date('Y-m-d H:i:s', $timestamp),
            ];
        }
        
        // Sort by timestamp descending (newest first)
        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return $backups;
    }

    /**
     * Restore from backup
     */
    public function restoreBackup(string $backupFile): void
    {
        if (!File::exists($backupFile)) {
            throw new \Exception("Backup file not found: {$backupFile}");
        }
        
        // Create backup of current config before restoring
        $currentBackup = $this->configPath . '.backup.' . time();
        File::copy($this->configPath, $currentBackup);
        
        // Restore from backup
        File::copy($backupFile, $this->configPath);
        
        // Clear config cache
        Artisan::call('config:clear');
        
        Log::info("Configuration restored from backup", [
            'backup_file' => $backupFile,
            'current_backup' => $currentBackup
        ]);
    }
} 