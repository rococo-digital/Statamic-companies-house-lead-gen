<?php

namespace Rococo\ChLeadGen\Commands;

use Illuminate\Console\Command;
use Rococo\ChLeadGen\Services\RuleManagerService;
use Rococo\ChLeadGen\Services\StatsService;
use Illuminate\Support\Facades\Log;

class ManageRules extends Command
{
    protected $signature = 'ch-lead-gen:rules {action} {rule?} {--stats : Show statistics}';
    protected $description = 'Manage Companies House lead generation rules';

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
        $action = $this->argument('action');
        $ruleKey = $this->argument('rule');

        switch ($action) {
            case 'list':
                return $this->listRules();
            case 'show':
                return $this->showRule($ruleKey);
            case 'stats':
                return $this->showStats($ruleKey);
            case 'test':
                return $this->testRule($ruleKey);
            case 'clear-cache':
                return $this->clearCache();
            case 'rate-limits':
                return $this->showRateLimits();
            default:
                $this->error("Unknown action: {$action}");
                $this->showHelp();
                return 1;
        }
    }

    /**
     * List all rules
     */
    protected function listRules(): int
    {
        $rules = $this->ruleManagerService->getAllRules();
        
        if (empty($rules)) {
            $this->warn('No rules configured.');
            $this->line('');
            $this->comment('To add rules, edit your config/ch-lead-gen.php file.');
            return 0;
        }

        $this->info('📋 Configured Lead Generation Rules:');
        $this->line('');

        $headers = ['Rule Key', 'Name', 'Status', 'Schedule', 'Lead List', 'Last Run', 'Success Rate'];
        $rows = [];

        foreach ($rules as $ruleKey => $rule) {
            $status = $rule['enabled'] ? '<info>✅ Enabled</info>' : '<comment>❌ Disabled</comment>';
            
            $schedule = $rule['schedule']['enabled'] ?? false 
                ? sprintf('%s at %s', 
                    ucfirst($rule['schedule']['frequency'] ?? 'daily'), 
                    $rule['schedule']['time'] ?? '09:00'
                ) 
                : '<comment>Manual only</comment>';

            $leadList = $rule['instantly']['lead_list_name'] ?? 'Default';
            
            $stats = $this->statsService->getRuleSummaryStats($ruleKey);
            $lastRun = $stats['last_run'] ? date('M j, H:i', strtotime($stats['last_run'])) : '<comment>Never</comment>';
            
            $successRate = $stats['total_executions'] > 0 
                ? round(($stats['successful_executions'] / $stats['total_executions']) * 100, 1) . '%'
                : '<comment>N/A</comment>';

            $rows[] = [
                $ruleKey,
                $rule['name'] ?? $ruleKey,
                $status,
                $schedule,
                $leadList,
                $lastRun,
                $successRate,
            ];
        }

        $this->table($headers, $rows);
        
        $this->line('');
        $this->comment('💡 Use "ch-lead-gen:rules show <rule_key>" for detailed information');
        $this->comment('💡 Use "ch-lead-gen:rules stats <rule_key>" for statistics');
        
        return 0;
    }

    /**
     * Show detailed information about a specific rule
     */
    protected function showRule(?string $ruleKey): int
    {
        if (!$ruleKey) {
            $this->error('Please specify a rule key. Use "list" to see available rules.');
            return 1;
        }

        $rule = $this->ruleManagerService->getRule($ruleKey);
        
        if (!$rule) {
            $this->error("Rule '{$ruleKey}' not found.");
            return 1;
        }

        $this->info("🎯 Rule Details: {$ruleKey}");
        $this->line('');

        // Basic info
        $this->line("<comment>Name:</comment> {$rule['name']}");
        $this->line("<comment>Description:</comment> {$rule['description']}");
        $this->line("<comment>Status:</comment> " . ($rule['enabled'] ? '<info>Enabled</info>' : '<error>Disabled</error>'));
        
        $this->line('');
        $this->line('<comment>📅 Schedule:</comment>');
        if ($rule['schedule']['enabled'] ?? false) {
            $frequency = $rule['schedule']['frequency'] ?? 'daily';
            $time = $rule['schedule']['time'] ?? '09:00';
            $this->line("  Frequency: {$frequency}");
            $this->line("  Time: {$time}");
            
            if ($frequency === 'weekly') {
                $dayOfWeek = $rule['schedule']['day_of_week'] ?? 1;
                $dayName = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$dayOfWeek] ?? 'Monday';
                $this->line("  Day: {$dayName}");
            } elseif ($frequency === 'monthly') {
                $dayOfMonth = $rule['schedule']['day_of_month'] ?? 1;
                $this->line("  Day of month: {$dayOfMonth}");
            }
        } else {
            $this->line('  <comment>Manual execution only</comment>');
        }

        $this->line('');
        $this->line('<comment>🔍 Search Parameters:</comment>');
        $searchParams = $rule['search_parameters'];
        if (isset($searchParams['days_ago'])) {
            $this->line("  Company age: {$searchParams['days_ago']} days");
        } elseif (isset($searchParams['months_ago'])) {
            $this->line("  Company age: {$searchParams['months_ago']} months (legacy)");
        }
        $this->line("  Company status: {$searchParams['company_status']}");
        $this->line("  Company type: {$searchParams['company_type']}");
        $this->line("  Countries: " . implode(', ', $searchParams['allowed_countries']));
        $this->line("  Max results: {$searchParams['max_results']}");
        $this->line("  Check confirmation statements: " . ($searchParams['check_confirmation_statement'] ? 'Yes' : 'No'));

        $this->line('');
        $this->line('<comment>📮 Instantly Integration:</comment>');
        $instantlyEnabled = $rule['instantly']['enabled'] ?? false;
        $this->line("  Status: " . ($instantlyEnabled ? '<info>Enabled</info>' : '<comment>Disabled</comment>'));
        if ($instantlyEnabled) {
            $this->line("  Lead list: {$rule['instantly']['lead_list_name']}");
            $this->line("  Enrichment: " . ($rule['instantly']['enable_enrichment'] ? 'Enabled' : 'Disabled'));
        }

        $this->line('');
        $this->line('<comment>🔗 Webhook Settings:</comment>');
        if ($rule['webhook']['enabled'] ?? false) {
            $this->line("  Status: <info>Enabled</info>");
            $this->line("  URL: {$rule['webhook']['url']}");
            $this->line("  Secret: " . (!empty($rule['webhook']['secret']) ? 'Configured' : 'Not set'));
        } else {
            $this->line('  Status: <comment>Disabled</comment>');
        }

        if ($this->option('stats')) {
            $this->line('');
            $this->showRuleStatistics($ruleKey);
        }

        return 0;
    }

    /**
     * Show statistics for a rule
     */
    protected function showStats(?string $ruleKey): int
    {
        if (!$ruleKey) {
            $this->error('Please specify a rule key. Use "list" to see available rules.');
            return 1;
        }

        $rule = $this->ruleManagerService->getRule($ruleKey);
        
        if (!$rule) {
            $this->error("Rule '{$ruleKey}' not found.");
            return 1;
        }

        $this->showRuleStatistics($ruleKey);
        
        return 0;
    }

    /**
     * Test a rule (dry run)
     */
    protected function testRule(?string $ruleKey): int
    {
        if (!$ruleKey) {
            $this->error('Please specify a rule key. Use "list" to see available rules.');
            return 1;
        }

        $rule = $this->ruleManagerService->getRule($ruleKey);
        
        if (!$rule) {
            $this->error("Rule '{$ruleKey}' not found.");
            return 1;
        }

        $this->info("🧪 Testing rule: {$ruleKey}");
        $this->line('');

        if (!$rule['enabled']) {
            $this->warn('⚠️  Rule is currently disabled');
        }

        $isDue = $this->ruleManagerService->isRuleDueToRun($ruleKey, $rule);
        $this->line("Scheduled to run now: " . ($isDue ? '<info>Yes</info>' : '<comment>No</comment>'));
        
        if ($this->confirm('Would you like to run this rule now?')) {
            try {
                $this->line('');
                $this->info('🚀 Executing rule...');
                
                $result = $this->ruleManagerService->executeRule($ruleKey, true);
                
                if ($result['success']) {
                    $this->info('✅ Rule executed successfully!');
                    $this->line("📊 Results:");
                    $this->line("  Companies found: {$result['companies_found']}");
                    $this->line("  Contacts found: {$result['contacts_found']}");
                    $this->line("  Contacts added: {$result['contacts_added']}");
                    $this->line("  Execution time: {$result['execution_time']}s");
                } else {
                    $this->error('❌ Rule execution failed');
                    if (isset($result['error'])) {
                        $this->line("Error: {$result['error']}");
                    }
                }
            } catch (\Exception $e) {
                $this->error('❌ Error executing rule: ' . $e->getMessage());
                return 1;
            }
        }

        return 0;
    }

    /**
     * Show detailed statistics for a rule
     */
    protected function showRuleStatistics(string $ruleKey): void
    {
        $this->line('<comment>📊 Statistics (Last 30 days):</comment>');
        
        $stats = $this->statsService->getRuleSummaryStats($ruleKey);
        $apiUsage = $this->statsService->getRuleApiUsage($ruleKey, 7);
        $history = $this->statsService->getRuleHistory($ruleKey, 5);

        // Summary stats
        $this->line("  Total executions: {$stats['total_executions']}");
        $this->line("  Successful: {$stats['successful_executions']}");
        $this->line("  Failed: {$stats['failed_executions']}");
        $this->line("  Companies found: {$stats['total_companies_found']}");
        $this->line("  Contacts found: {$stats['total_contacts_found']}");
        $this->line("  Contacts added: {$stats['total_contacts_added']}");
        $this->line("  Avg execution time: " . round($stats['average_execution_time'], 2) . "s");

        if ($stats['last_run']) {
            $this->line("  Last run: " . date('M j, Y H:i', strtotime($stats['last_run'])));
        }

        // API Usage
        $this->line('');
        $this->line('<comment>🔌 API Usage (Last 7 days):</comment>');
        
        $totalCH = array_sum(array_column($apiUsage, 'companies_house'));
        $totalApollo = array_sum(array_column($apiUsage, 'apollo'));
        $totalInstantly = array_sum(array_column($apiUsage, 'instantly'));
        
        $this->line("  Companies House: {$totalCH} requests");
        $this->line("  Apollo: {$totalApollo} requests");
        $this->line("  Instantly: {$totalInstantly} requests");

        // Recent executions
        if (!empty($history)) {
            $this->line('');
            $this->line('<comment>📝 Recent Executions:</comment>');
            
            foreach (array_slice($history, -3) as $execution) {
                $status = $execution['status'] === 'completed' ? '✅' : '❌';
                $date = date('M j H:i', strtotime($execution['end_datetime']));
                
                if ($execution['status'] === 'completed') {
                    $companies = $execution['companies_found'] ?? 0;
                    $contacts = $execution['contacts_found'] ?? 0;
                    $this->line("  {$status} {$date} - {$companies} companies, {$contacts} contacts");
                } else {
                    $this->line("  {$status} {$date} - Failed");
                }
            }
        }
    }

    /**
     * Clear rate limit caches and reset error tracking
     */
    protected function clearCache(): int
    {
        $this->info('🧹 Clearing rate limit caches...');
        
        // Clear Apollo rate limit cache
        \Illuminate\Support\Facades\Cache::forget('apollo_rate_limits_people_search');
        $this->line('✅ Cleared Apollo rate limit cache');
        
        // Clear rate limit error tracking
        \Illuminate\Support\Facades\Cache::forget('apollo_rate_limit_errors');
        $this->line('✅ Cleared rate limit error tracking');
        
        // Clear API usage tracking
        $usageKeys = \Illuminate\Support\Facades\Cache::get('apollo_api_usage_*');
        if ($usageKeys) {
            foreach ($usageKeys as $key) {
                \Illuminate\Support\Facades\Cache::forget($key);
            }
        }
        $this->line('✅ Cleared API usage tracking');
        
        $this->info('🎉 All caches cleared successfully!');
        $this->line('');
        $this->comment('The system will now fetch fresh rate limit information from Apollo on the next request.');
        
        return 0;
    }

    /**
     * Show current Apollo API rate limit status
     */
    protected function showRateLimits(): int
    {
        $this->info('📊 Apollo API Rate Limit Status');
        $this->line('');

        try {
            // Get Apollo service instance
            $apolloService = app(\Rococo\ChLeadGen\Services\ApolloService::class);
            
            // Get current rate limits
            $rateLimits = $apolloService->getRateLimits();
            
            $this->line('<comment>Current Limits:</comment>');
            $this->line("  Per Minute: {$rateLimits['per_minute']['used']}/{$rateLimits['per_minute']['limit']} ({$rateLimits['per_minute']['remaining']} remaining)");
            $this->line("  Per Hour: {$rateLimits['per_hour']['used']}/{$rateLimits['per_hour']['limit']} ({$rateLimits['per_hour']['remaining']} remaining)");
            $this->line("  Per Day: {$rateLimits['per_day']['used']}/{$rateLimits['per_day']['limit']} ({$rateLimits['per_day']['remaining']} remaining)");
            
            // Calculate percentages
            $minutePercent = $rateLimits['per_minute']['limit'] > 0 ? round(($rateLimits['per_minute']['used'] / $rateLimits['per_minute']['limit']) * 100, 1) : 0;
            $hourPercent = $rateLimits['per_hour']['limit'] > 0 ? round(($rateLimits['per_hour']['used'] / $rateLimits['per_hour']['limit']) * 100, 1) : 0;
            $dayPercent = $rateLimits['per_day']['limit'] > 0 ? round(($rateLimits['per_day']['used'] / $rateLimits['per_day']['limit']) * 100, 1) : 0;
            
            $this->line('');
            $this->line('<comment>Usage Percentages:</comment>');
            $this->line("  Per Minute: {$minutePercent}%");
            $this->line("  Per Hour: {$hourPercent}%");
            $this->line("  Per Day: {$dayPercent}%");
            
            // Check for warnings
            $this->line('');
            if ($minutePercent > 80) {
                $this->warn('⚠️  Minute limit is high - consider pausing processing');
            }
            if ($hourPercent > 80) {
                $this->warn('⚠️  Hour limit is high - consider pausing processing');
            }
            if ($dayPercent > 80) {
                $this->warn('⚠️  Day limit is high - consider pausing processing');
            }
            
            if ($minutePercent <= 80 && $hourPercent <= 80 && $dayPercent <= 80) {
                $this->info('✅ All rate limits are healthy');
            }
            
            // Check for recent rate limit errors
            $errorCache = \Illuminate\Support\Facades\Cache::get('apollo_rate_limit_errors', []);
            $recentErrors = array_filter($errorCache, function($timestamp) {
                return $timestamp > (time() - 600); // Last 10 minutes
            });
            
            if (!empty($recentErrors)) {
                $this->line('');
                $this->warn('⚠️  Recent rate limit errors detected: ' . count($recentErrors) . ' in the last 10 minutes');
                $this->comment('Consider running "clear-cache" to reset error tracking');
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Error fetching rate limit status: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }

    /**
     * Show help information
     */
    protected function showHelp(): void
    {
        $this->line('');
        $this->comment('Available actions:');
        $this->line('  list           - List all configured rules');
        $this->line('  show <rule>    - Show detailed information about a rule');
        $this->line('  stats <rule>   - Show statistics for a rule');
        $this->line('  test <rule>    - Test/run a specific rule');
        $this->line('  clear-cache    - Clear rate limit caches and reset error tracking');
        $this->line('  rate-limits    - Show current Apollo API rate limit status');
        $this->line('');
        $this->comment('Examples:');
        $this->line('  php artisan ch-lead-gen:rules list');
        $this->line('  php artisan ch-lead-gen:rules show six_month_companies');
        $this->line('  php artisan ch-lead-gen:rules stats confirmation_statement_missing');
        $this->line('  php artisan ch-lead-gen:rules test six_month_companies');
        $this->line('  php artisan ch-lead-gen:rules clear-cache');
        $this->line('  php artisan ch-lead-gen:rules rate-limits');
    }
} 