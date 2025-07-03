<?php

namespace Rococo\ChLeadGen\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StatsService
{
    protected $config;
    protected $cachePrefix = 'ch_lead_gen_stats_';

    public function __construct()
    {
        $this->config = config('ch-lead-gen');
    }

    /**
     * Start tracking a rule execution
     */
    public function startRuleExecution(string $ruleKey): void
    {
        if (!$this->isStatsEnabled()) {
            return;
        }

        $executionId = uniqid();
        $startTime = microtime(true);
        
        Cache::put($this->cachePrefix . "execution_{$ruleKey}_{$executionId}", [
            'rule_key' => $ruleKey,
            'execution_id' => $executionId,
            'start_time' => $startTime,
            'start_datetime' => now()->toISOString(),
            'status' => 'running',
        ], 3600); // 1 hour cache

        // Store current execution ID for this rule
        Cache::put($this->cachePrefix . "current_execution_{$ruleKey}", $executionId, 3600);
    }

    /**
     * Complete a rule execution with results
     */
    public function completeRuleExecution(
        string $ruleKey, 
        int $companiesFound, 
        int $contactsFound, 
        int $contactsAdded, 
        float $executionTime
    ): void {
        if (!$this->isStatsEnabled()) {
            return;
        }

        $executionId = Cache::get($this->cachePrefix . "current_execution_{$ruleKey}");
        if (!$executionId) {
            return;
        }

        $executionData = [
            'rule_key' => $ruleKey,
            'execution_id' => $executionId,
            'end_time' => microtime(true),
            'end_datetime' => now()->toISOString(),
            'status' => 'completed',
            'companies_found' => $companiesFound,
            'contacts_found' => $contactsFound,
            'contacts_added' => $contactsAdded,
            'execution_time' => $executionTime,
        ];

        // Update the execution record
        Cache::put($this->cachePrefix . "execution_{$ruleKey}_{$executionId}", $executionData, 86400); // 24 hours

        // Add to rule execution history
        $this->addToRuleHistory($ruleKey, $executionData);

        // Update rule summary stats
        $this->updateRuleSummaryStats($ruleKey, $executionData);
    }

    /**
     * Record an error during rule execution
     */
    public function recordRuleError(string $ruleKey, string $errorMessage): void
    {
        if (!$this->isStatsEnabled()) {
            return;
        }

        $executionId = Cache::get($this->cachePrefix . "current_execution_{$ruleKey}");
        if (!$executionId) {
            return;
        }

        $executionData = [
            'rule_key' => $ruleKey,
            'execution_id' => $executionId,
            'end_time' => microtime(true),
            'end_datetime' => now()->toISOString(),
            'status' => 'error',
            'error_message' => $errorMessage,
        ];

        Cache::put($this->cachePrefix . "execution_{$ruleKey}_{$executionId}", $executionData, 86400);
        $this->addToRuleHistory($ruleKey, $executionData);
    }

    /**
     * Track API usage per rule
     */
    public function trackApiUsage(string $ruleKey, string $service, string $endpoint, int $count = 1): void
    {
        if (!$this->isStatsEnabled() || !($this->config['stats']['track_api_usage'] ?? true)) {
            return;
        }

        $date = now()->format('Y-m-d');
        $hour = now()->format('H');
        
        // Track daily usage
        $dailyKey = $this->cachePrefix . "api_usage_daily_{$ruleKey}_{$service}_{$endpoint}_{$date}";
        $dailyUsage = Cache::get($dailyKey, 0);
        Cache::put($dailyKey, $dailyUsage + $count, 86400 * 7); // Keep for a week

        // Track hourly usage for rate limiting awareness
        $hourlyKey = $this->cachePrefix . "api_usage_hourly_{$ruleKey}_{$service}_{$endpoint}_{$date}_{$hour}";
        $hourlyUsage = Cache::get($hourlyKey, 0);
        Cache::put($hourlyKey, $hourlyUsage + $count, 3600 * 2); // Keep for 2 hours

        // Track overall service usage
        $serviceKey = $this->cachePrefix . "api_usage_service_{$service}_{$date}";
        $serviceUsage = Cache::get($serviceKey, 0);
        Cache::put($serviceKey, $serviceUsage + $count, 86400 * 7);
    }

    /**
     * Get API usage statistics for a specific rule
     */
    public function getRuleApiUsage(string $ruleKey, int $days = 7): array
    {
        $stats = [];
        $services = ['companies_house', 'apollo', 'instantly'];
        
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayStats = ['date' => $date];
            
            foreach ($services as $service) {
                $dayStats[$service] = 0;
                
                // Get all endpoints for this service on this date
                $pattern = $this->cachePrefix . "api_usage_daily_{$ruleKey}_{$service}_*_{$date}";
                
                // Since we can't wildcard search in cache, we'll track known endpoints
                $endpoints = $this->getKnownEndpoints($service);
                
                foreach ($endpoints as $endpoint) {
                    $key = $this->cachePrefix . "api_usage_daily_{$ruleKey}_{$service}_{$endpoint}_{$date}";
                    $usage = Cache::get($key, 0);
                    $dayStats[$service] += $usage;
                }
            }
            
            $stats[] = $dayStats;
        }
        
        return array_reverse($stats); // Most recent first
    }

    /**
     * Get overall API usage statistics across all rules
     */
    public function getOverallApiUsage(int $days = 7): array
    {
        $stats = [];
        $services = ['companies_house', 'apollo', 'instantly'];
        
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayStats = ['date' => $date];
            
            foreach ($services as $service) {
                $key = $this->cachePrefix . "api_usage_service_{$service}_{$date}";
                $dayStats[$service] = Cache::get($key, 0);
            }
            
            $stats[] = $dayStats;
        }
        
        return array_reverse($stats); // Most recent first
    }

    /**
     * Get rule execution history
     */
    public function getRuleHistory(string $ruleKey, int $limit = 10): array
    {
        $historyKey = $this->cachePrefix . "rule_history_{$ruleKey}";
        $history = Cache::get($historyKey, []);
        
        return array_slice($history, -$limit); // Get last N executions
    }

    /**
     * Get rule summary statistics
     */
    public function getRuleSummaryStats(string $ruleKey): array
    {
        $summaryKey = $this->cachePrefix . "rule_summary_{$ruleKey}";
        return Cache::get($summaryKey, [
            'total_executions' => 0,
            'successful_executions' => 0,
            'failed_executions' => 0,
            'total_companies_found' => 0,
            'total_contacts_found' => 0,
            'total_contacts_added' => 0,
            'average_execution_time' => 0,
            'last_run' => null,
            'last_success' => null,
        ]);
    }

    /**
     * Get statistics for all rules
     */
    public function getAllRulesStats(): array
    {
        $config = config('ch-lead-gen');
        $rules = $config['rules'] ?? [];
        $allStats = [];
        
        foreach ($rules as $ruleKey => $rule) {
            $allStats[$ruleKey] = [
                'rule_name' => $rule['name'] ?? $ruleKey,
                'enabled' => $rule['enabled'] ?? false,
                'summary' => $this->getRuleSummaryStats($ruleKey),
                'recent_api_usage' => $this->getRuleApiUsage($ruleKey, 1), // Last day only for overview
            ];
        }
        
        return $allStats;
    }

    /**
     * Clear old statistics data
     */
    public function cleanupOldStats(): void
    {
        $retentionDays = $this->config['stats']['retention_days'] ?? 90;
        
        // This is a simplified cleanup - in a real implementation you might want
        // to use a more sophisticated approach with database storage for persistence
        Log::info("Stats cleanup would remove data older than {$retentionDays} days");
    }

    /**
     * Check if statistics tracking is enabled
     */
    protected function isStatsEnabled(): bool
    {
        return $this->config['stats']['enabled'] ?? true;
    }

    /**
     * Add execution to rule history
     */
    protected function addToRuleHistory(string $ruleKey, array $executionData): void
    {
        $historyKey = $this->cachePrefix . "rule_history_{$ruleKey}";
        $history = Cache::get($historyKey, []);
        
        $history[] = $executionData;
        
        // Keep only last 50 executions
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }
        
        Cache::put($historyKey, $history, 86400 * 30); // Keep for 30 days
    }

    /**
     * Update rule summary statistics
     */
    protected function updateRuleSummaryStats(string $ruleKey, array $executionData): void
    {
        $summaryKey = $this->cachePrefix . "rule_summary_{$ruleKey}";
        $summary = $this->getRuleSummaryStats($ruleKey);
        
        $summary['total_executions']++;
        $summary['last_run'] = $executionData['end_datetime'];
        
        if ($executionData['status'] === 'completed') {
            $summary['successful_executions']++;
            $summary['last_success'] = $executionData['end_datetime'];
            $summary['total_companies_found'] += $executionData['companies_found'] ?? 0;
            $summary['total_contacts_found'] += $executionData['contacts_found'] ?? 0;
            $summary['total_contacts_added'] += $executionData['contacts_added'] ?? 0;
            
            // Calculate average execution time
            if (isset($executionData['execution_time'])) {
                $totalTime = $summary['average_execution_time'] * ($summary['successful_executions'] - 1);
                $summary['average_execution_time'] = ($totalTime + $executionData['execution_time']) / $summary['successful_executions'];
            }
        } else {
            $summary['failed_executions']++;
        }
        
        Cache::put($summaryKey, $summary, 86400 * 30); // Keep for 30 days
    }

    /**
     * Get known API endpoints for a service
     */
    protected function getKnownEndpoints(string $service): array
    {
        switch ($service) {
            case 'companies_house':
                return ['search', 'filing_history', 'company_profile'];
            case 'apollo':
                return ['people_search', 'bulk_enrich'];
            case 'instantly':
                return ['add_contacts', 'create_list'];
            default:
                return ['unknown'];
        }
    }
} 