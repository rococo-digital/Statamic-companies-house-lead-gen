<?php

namespace Rococo\ChLeadGen\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Rococo\ChLeadGen\Services\RuleManagerService;
use Rococo\ChLeadGen\Services\StatsService;
use Rococo\ChLeadGen\Services\JobTrackingService;
use Illuminate\Support\Facades\Log;

class RunLeadGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $ruleKey;
    protected $forceRun;
    protected $jobId;

    /**
     * Create a new job instance.
     *
     * @param string|null $ruleKey Specific rule to run, or null for all scheduled rules
     * @param bool $forceRun Force run even if rule is disabled or not scheduled
     * @param string|null $jobId Optional job ID for tracking
     */
    public function __construct(?string $ruleKey = null, bool $forceRun = false, ?string $jobId = null)
    {
        $this->ruleKey = $ruleKey;
        $this->forceRun = $forceRun;
        $this->jobId = $jobId;
    }

    public function handle(RuleManagerService $ruleManager, StatsService $statsService, JobTrackingService $jobTracking)
    {
        // Generate job ID if not provided
        if (!$this->jobId) {
            $this->jobId = $jobTracking->generateJobId();
        }

        try {
            // Start tracking the job
            $jobTracking->startJob($this->jobId, [
                'rule_key' => $this->ruleKey,
                'force_run' => $this->forceRun
            ]);

            Log::info('=== Starting lead generation job ===', [
                'job_id' => $this->jobId,
                'rule_key' => $this->ruleKey,
                'force_run' => $this->forceRun
            ]);

            // Check for cancellation before starting
            if ($jobTracking->isJobCancelled($this->jobId)) {
                Log::info('=== Job cancelled before execution ===', ['job_id' => $this->jobId]);
                $jobTracking->completeJob($this->jobId, ['status' => 'cancelled']);
                return;
            }

            if ($this->ruleKey) {
                // Run specific rule
                $result = $this->runSpecificRule($ruleManager, $jobTracking);
            } else {
                // Run all scheduled rules (default behavior for backward compatibility)
                $result = $this->runScheduledRules($ruleManager, $jobTracking);
            }

            // Check for cancellation before completing
            if ($jobTracking->isJobCancelled($this->jobId)) {
                Log::info('=== Job cancelled during execution ===', ['job_id' => $this->jobId]);
                $jobTracking->completeJob($this->jobId, ['status' => 'cancelled']);
                return;
            }

            // Check if the job completed with partial results due to rate limits
            if (isset($result['rate_limit_reached']) && $result['rate_limit_reached']) {
                Log::info('=== Lead generation job completed with partial results due to rate limits ===', [
                    'job_id' => $this->jobId, 
                    'result' => $result
                ]);
                $jobTracking->completeJobWithPartialResults($this->jobId, $result);
            } else {
                Log::info('=== Lead generation job completed ===', ['job_id' => $this->jobId, 'result' => $result]);
                $jobTracking->completeJob($this->jobId, $result);
            }

        } catch (\Exception $e) {
            Log::error('=== Error in lead generation job ===', ['job_id' => $this->jobId]);
            Log::error('Error message: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            $jobTracking->failJob($this->jobId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Run a specific rule
     */
    protected function runSpecificRule(RuleManagerService $ruleManager, JobTrackingService $jobTracking): array
    {
        Log::info("Executing specific rule: {$this->ruleKey}");

        try {
            $result = $ruleManager->executeRule($this->ruleKey, $this->forceRun, $jobTracking, $this->jobId);
            
            Log::info("Rule execution completed", [
                'rule_key' => $this->ruleKey,
                'success' => $result['success'],
                'companies_found' => $result['companies_found'] ?? 0,
                'contacts_found' => $result['contacts_found'] ?? 0,
                'contacts_added' => $result['contacts_added'] ?? 0,
                'execution_time' => $result['execution_time'] ?? 0,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error("Failed to execute rule {$this->ruleKey}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Run all scheduled rules (backward compatibility)
     */
    protected function runScheduledRules(RuleManagerService $ruleManager, JobTrackingService $jobTracking): array
    {
        Log::info("Executing all scheduled rules");

        $dueRules = $ruleManager->getRulesDueToRun();
        
        if (empty($dueRules)) {
            Log::info("No rules due to run at this time");
            
            // For backward compatibility, if no rules are configured or due,
            // check if we should fall back to legacy behavior
            if ($this->shouldUseLegacyFallback($ruleManager)) {
                return $this->runLegacyFallback($ruleManager);
            }
            
            return ['message' => 'No rules due to run'];
        }

        Log::info("Found " . count($dueRules) . " rules due to run", ['rules' => array_keys($dueRules)]);

        $results = $ruleManager->executeScheduledRules($jobTracking, $this->jobId);
        
        $successCount = 0;
        $failureCount = 0;
        $partialCount = 0;
        $totalCompanies = 0;
        $totalContacts = 0;
        $totalAdded = 0;
        $anyPartialExecution = false;

        foreach ($results as $ruleKey => $result) {
            if ($result['success']) {
                $successCount++;
                $totalCompanies += $result['companies_found'] ?? 0;
                $totalContacts += $result['contacts_found'] ?? 0;
                $totalAdded += $result['contacts_added'] ?? 0;
                
                // Check if this rule had partial execution due to rate limits
                if (isset($result['partial_execution']) && $result['partial_execution']) {
                    $partialCount++;
                    $anyPartialExecution = true;
                }
            } else {
                $failureCount++;
            }
        }

        Log::info("All scheduled rules executed", [
            'total_rules' => count($results),
            'successful' => $successCount,
            'failed' => $failureCount,
            'partial_executions' => $partialCount,
            'total_companies' => $totalCompanies,
            'total_contacts' => $totalContacts,
            'total_added' => $totalAdded,
            'any_partial_execution' => $anyPartialExecution,
        ]);

        return [
            'total_rules' => count($results),
            'successful' => $successCount,
            'failed' => $failureCount,
            'partial_executions' => $partialCount,
            'total_companies' => $totalCompanies,
            'total_contacts' => $totalContacts,
            'total_added' => $totalAdded,
            'results' => $results,
            'rate_limit_reached' => $anyPartialExecution,
            'partial_execution' => $anyPartialExecution,
        ];
    }

    /**
     * Check if we should use legacy fallback behavior
     */
    protected function shouldUseLegacyFallback(RuleManagerService $ruleManager): bool
    {
        $allRules = $ruleManager->getAllRules();
        
        // If no rules are configured, fall back to legacy behavior
        if (empty($allRules)) {
            Log::info("No rules configured, falling back to legacy behavior");
            return true;
        }

        // If rules exist but none are enabled, don't fallback
        $enabledRules = $ruleManager->getEnabledRules();
        if (empty($enabledRules)) {
            Log::info("Rules configured but none enabled, not falling back to legacy");
            return false;
        }

        return false;
    }

    /**
     * Legacy fallback for backward compatibility
     * This runs the old hardcoded logic when no rules are configured
     */
    protected function runLegacyFallback(RuleManagerService $ruleManager): array
    {
        Log::info("=== Running legacy fallback behavior ===");

        // This creates a temporary rule based on the legacy config
        $config = config('ch-lead-gen');
        
        $legacyRule = [
            'name' => 'Legacy Lead Generation',
            'enabled' => true,
            'search_parameters' => [
                'months_ago' => $config['search']['months_ago'] ?? 11,
                'company_status' => $config['search']['company_status'] ?? 'active',
                'company_type' => $config['search']['company_type'] ?? 'ltd',
                'allowed_countries' => $config['search']['allowed_countries'] ?? ['GB', 'US'],
                'max_results' => 500,
                'check_confirmation_statement' => true, // Legacy behavior
            ],
            'instantly' => [
                'lead_list_name' => $config['instantly']['lead_list_name'] ?? 'CH Lead Generation',
                'enable_enrichment' => false,
            ],
        ];

        try {
            // Temporarily add the legacy rule to config
            $tempConfig = $config;
            $tempConfig['rules'] = ['legacy' => $legacyRule];
            config(['ch-lead-gen' => $tempConfig]);

            // Execute the legacy rule
            $result = $ruleManager->executeRule('legacy', true);
            
            Log::info("Legacy fallback completed", [
                'companies_found' => $result['companies_found'] ?? 0,
                'contacts_found' => $result['contacts_found'] ?? 0,
                'contacts_added' => $result['contacts_added'] ?? 0,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error("Legacy fallback failed: " . $e->getMessage());
            throw $e;
        } finally {
            // Restore original config
            config(['ch-lead-gen' => $config]);
        }
    }

    /**
     * Static method to dispatch job for a specific rule
     */
    public static function dispatchRule(string $ruleKey, bool $forceRun = false, ?string $jobId = null): void
    {
        static::dispatch($ruleKey, $forceRun, $jobId);
    }

    /**
     * Static method to dispatch job for all scheduled rules (default behavior)
     */
    public static function dispatchScheduled(?string $jobId = null): void
    {
        static::dispatch(null, false, $jobId);
    }
} 