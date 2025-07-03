<?php

namespace Rococo\ChLeadGen\Commands;

use Illuminate\Console\Command;
use Rococo\ChLeadGen\Services\RuleManagerService;
use Rococo\ChLeadGen\Services\StatsService;
use Illuminate\Support\Facades\Log;

class RunLeadGeneration extends Command
{
    protected $signature = 'ch-lead-gen:run {--rule= : Run a specific rule by key} {--force : Force run even if not scheduled} {--list : List all available rules}';
    protected $description = 'Run the Companies House lead generation process (all scheduled rules or a specific rule)';

    private $ruleManagerService;
    private $statsService;

    public function __construct(
        RuleManagerService $ruleManagerService,
        StatsService $statsService
    ) {
        parent::__construct();
        $this->ruleManagerService = $ruleManagerService;
        $this->statsService = $statsService;
    }

    public function handle()
    {
        try {
            // Handle list option
            if ($this->option('list')) {
                $this->listRules();
                return 0;
            }

            $specificRule = $this->option('rule');
            $forceRun = $this->option('force');

            if ($specificRule) {
                return $this->runSpecificRule($specificRule, $forceRun);
            } else {
                return $this->runScheduledRules();
            }

        } catch (\Exception $e) {
            $this->error('Error in lead generation process: ' . $e->getMessage());
            Log::error('Lead generation command failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * List all available rules
     */
    protected function listRules(): void
    {
        $rules = $this->ruleManagerService->getAllRules();
        
        if (empty($rules)) {
            $this->warn('No rules configured.');
            return;
        }

        $this->info('Available Lead Generation Rules:');
        $this->line('');

        $headers = ['Rule Key', 'Name', 'Status', 'Schedule', 'Lead List', 'Last Run'];
        $rows = [];

        foreach ($rules as $ruleKey => $rule) {
            $status = $rule['enabled'] ? '<info>Enabled</info>' : '<comment>Disabled</comment>';
            
            $schedule = $rule['schedule']['enabled'] ?? false 
                ? sprintf('%s at %s', 
                    ucfirst($rule['schedule']['frequency'] ?? 'daily'), 
                    $rule['schedule']['time'] ?? '09:00'
                ) 
                : 'Manual only';

            $leadList = $rule['instantly']['lead_list_name'] ?? 'Default';
            
            $stats = $this->statsService->getRuleSummaryStats($ruleKey);
            $lastRun = $stats['last_run'] ? date('Y-m-d H:i', strtotime($stats['last_run'])) : 'Never';

            $rows[] = [
                $ruleKey,
                $rule['name'] ?? $ruleKey,
                $status,
                $schedule,
                $leadList,
                $lastRun,
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Run a specific rule
     */
    protected function runSpecificRule(string $ruleKey, bool $forceRun): int
    {
        $rule = $this->ruleManagerService->getRule($ruleKey);
        
        if (!$rule) {
            $this->error("Rule '{$ruleKey}' not found. Use --list to see available rules.");
            return 1;
        }

        $this->info("Running specific rule: {$ruleKey} ({$rule['name']})");
        
        if (!$rule['enabled'] && !$forceRun) {
            $this->warn("Rule '{$ruleKey}' is disabled. Use --force to run anyway.");
            return 1;
        }

        if ($forceRun) {
            $this->comment("Force running rule (ignoring schedule and enabled status)");
        }

        try {
            $startTime = now();
            $result = $this->ruleManagerService->executeRule($ruleKey, $forceRun);
            $duration = now()->diffInSeconds($startTime);

            if ($result['success']) {
                $this->info("✅ Rule '{$ruleKey}' completed successfully!");
                $this->line("📊 Results:");
                $this->line("   Companies found: {$result['companies_found']}");
                $this->line("   Contacts found: {$result['contacts_found']}");
                $this->line("   Contacts added: {$result['contacts_added']}");
                $this->line("   Execution time: {$result['execution_time']}s");
                
                return 0;
            } else {
                $this->error("❌ Rule '{$ruleKey}' failed: " . ($result['error'] ?? 'Unknown error'));
                return 1;
            }

        } catch (\Exception $e) {
            $this->error("❌ Error executing rule '{$ruleKey}': " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Run all scheduled rules
     */
    protected function runScheduledRules(): int
    {
        $this->info('🚀 Starting scheduled lead generation process...');

        $dueRules = $this->ruleManagerService->getRulesDueToRun();
        
        if (empty($dueRules)) {
            $this->info('✅ No rules are due to run at this time.');
            $this->showUpcomingRules();
            return 0;
        }

        $this->info('📋 Found ' . count($dueRules) . ' rule(s) due to run:');
        foreach ($dueRules as $ruleKey => $rule) {
            $this->line("   • {$ruleKey}: {$rule['name']}");
        }
        $this->line('');

        $results = $this->ruleManagerService->executeScheduledRules();
        
        $this->displayResults($results);
        
        // Return error code if any rule failed
        foreach ($results as $result) {
            if (!$result['success']) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Display execution results
     */
    protected function displayResults(array $results): void
    {
        $this->line('');
        $this->info('📊 Execution Results:');
        $this->line('');

        $totalCompanies = 0;
        $totalContacts = 0;
        $totalAdded = 0;
        $successCount = 0;
        $failureCount = 0;

        foreach ($results as $ruleKey => $result) {
            $status = $result['success'] ? '✅' : '❌';
            $ruleName = $result['rule_name'] ?? $ruleKey;
            
            $this->line("{$status} {$ruleName}");
            
            if ($result['success']) {
                $successCount++;
                $companies = $result['companies_found'] ?? 0;
                $contacts = $result['contacts_found'] ?? 0;
                $added = $result['contacts_added'] ?? 0;
                $time = $result['execution_time'] ?? 0;
                
                $this->line("    Companies: {$companies} | Contacts: {$contacts} | Added: {$added} | Time: {$time}s");
                
                $totalCompanies += $companies;
                $totalContacts += $contacts;
                $totalAdded += $added;
            } else {
                $failureCount++;
                $error = $result['error'] ?? 'Unknown error';
                $this->line("    Error: {$error}");
            }
        }

        $this->line('');
        $this->info('🎯 Summary:');
        $this->line("   Rules executed: " . count($results));
        $this->line("   Successful: {$successCount}");
        $this->line("   Failed: {$failureCount}");
        $this->line("   Total companies: {$totalCompanies}");
        $this->line("   Total contacts: {$totalContacts}");
        $this->line("   Total added: {$totalAdded}");
    }

    /**
     * Show information about upcoming scheduled rules
     */
    protected function showUpcomingRules(): void
    {
        $enabledRules = $this->ruleManagerService->getEnabledRules();
        
        if (empty($enabledRules)) {
            $this->comment('No rules are currently enabled.');
            return;
        }

        $this->line('');
        $this->comment('ℹ️  Enabled rules and their schedules:');
        
        foreach ($enabledRules as $ruleKey => $rule) {
            $schedule = $rule['schedule'];
            if (!($schedule['enabled'] ?? false)) {
                continue;
            }

            $frequency = $schedule['frequency'] ?? 'daily';
            $time = $schedule['time'] ?? '09:00';
            
            $scheduleText = ucfirst($frequency) . " at {$time}";
            
            if ($frequency === 'weekly') {
                $dayOfWeek = $schedule['day_of_week'] ?? 1;
                $dayName = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$dayOfWeek] ?? 'Monday';
                $scheduleText .= " ({$dayName})";
            } elseif ($frequency === 'monthly') {
                $dayOfMonth = $schedule['day_of_month'] ?? 1;
                $scheduleText .= " (day {$dayOfMonth})";
            }
            
            $this->line("   • {$rule['name']}: {$scheduleText}");
        }
    }
} 