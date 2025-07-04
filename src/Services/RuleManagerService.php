<?php

namespace Rococo\ChLeadGen\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class RuleManagerService
{
    protected $companiesHouseService;
    protected $apolloService;
    protected $instantlyService;
    protected $statsService;
    protected $webhookService;
    protected $config;

    public function __construct(
        CompaniesHouseService $companiesHouseService,
        ApolloService $apolloService,
        InstantlyService $instantlyService,
        StatsService $statsService,
        WebhookService $webhookService
    ) {
        $this->companiesHouseService = $companiesHouseService;
        $this->apolloService = $apolloService;
        $this->instantlyService = $instantlyService;
        $this->statsService = $statsService;
        $this->webhookService = $webhookService;
        $this->config = config('ch-lead-gen');
    }

    /**
     * Get all configured rules
     */
    public function getAllRules(): array
    {
        return $this->config['rules'] ?? [];
    }

    /**
     * Get a specific rule by its key
     */
    public function getRule(string $ruleKey): ?array
    {
        $rules = $this->getAllRules();
        return $rules[$ruleKey] ?? null;
    }

    /**
     * Get all enabled rules
     */
    public function getEnabledRules(): array
    {
        $rules = $this->getAllRules();
        return array_filter($rules, function($rule) {
            return $rule['enabled'] ?? false;
        });
    }

    /**
     * Check if any rule should run now
     */
    public function getRulesDueToRun(): array
    {
        $enabledRules = $this->getEnabledRules();
        $dueRules = [];

        foreach ($enabledRules as $ruleKey => $rule) {
            if ($this->isRuleDueToRun($ruleKey, $rule)) {
                $dueRules[$ruleKey] = $rule;
            }
        }

        return $dueRules;
    }

    /**
     * Check if a specific rule should run now
     */
    public function isRuleDueToRun(string $ruleKey, array $rule): bool
    {
        if (!($rule['schedule']['enabled'] ?? false)) {
            return false;
        }

        $lastRun = Cache::get("ch_lead_gen_rule_last_run_{$ruleKey}");
        $now = Carbon::now();
        
        if (!$lastRun) {
            return true; // Never run before
        }

        $lastRunTime = Carbon::parse($lastRun);
        $schedule = $rule['schedule'];
        $frequency = $schedule['frequency'] ?? 'daily';
        $scheduledTime = $schedule['time'] ?? '09:00';

        // Parse scheduled time
        list($hour, $minute) = explode(':', $scheduledTime);
        $scheduledToday = $now->copy()->setTime((int)$hour, (int)$minute, 0);

        switch ($frequency) {
            case 'daily':
                // Should run if it's past the scheduled time today and hasn't run today
                return $now >= $scheduledToday && $lastRunTime->format('Y-m-d') !== $now->format('Y-m-d');

            case 'weekly':
                $dayOfWeek = $schedule['day_of_week'] ?? 1; // Monday
                $scheduledThisWeek = $now->copy()->startOfWeek()->addDays($dayOfWeek - 1)->setTime((int)$hour, (int)$minute, 0);
                
                return $now >= $scheduledThisWeek && $lastRunTime < $scheduledThisWeek;

            case 'monthly':
                $dayOfMonth = $schedule['day_of_month'] ?? 1;
                $scheduledThisMonth = $now->copy()->startOfMonth()->addDays($dayOfMonth - 1)->setTime((int)$hour, (int)$minute, 0);
                
                return $now >= $scheduledThisMonth && $lastRunTime < $scheduledThisMonth;

            default:
                return false;
        }
    }

    /**
     * Execute a specific rule
     */
    public function executeRule(string $ruleKey, bool $forceRun = false): array
    {
        $rule = $this->getRule($ruleKey);
        
        if (!$rule) {
            throw new \InvalidArgumentException("Rule '{$ruleKey}' not found");
        }

        if (!$rule['enabled'] && !$forceRun) {
            throw new \InvalidArgumentException("Rule '{$ruleKey}' is disabled");
        }

        Log::info("=== Starting rule execution: {$ruleKey} ===", ['rule' => $rule['name']]);
        
        $startTime = microtime(true);
        $this->statsService->startRuleExecution($ruleKey);

        try {
            // Step 1: Search for companies
            $companies = $this->searchCompaniesForRule($ruleKey, $rule);
            $companiesCount = count($companies);
            
            Log::info("Found {$companiesCount} companies for rule '{$ruleKey}'");
            
            if ($companiesCount === 0) {
                $this->markRuleAsRun($ruleKey);
                return [
                    'success' => true,
                    'rule_key' => $ruleKey,
                    'rule_name' => $rule['name'],
                    'companies_found' => 0,
                    'contacts_found' => 0,
                    'contacts_added' => 0,
                    'execution_time' => round(microtime(true) - $startTime, 2),
                ];
            }

            // Step 2: Find people and enrich with Apollo
            $allContacts = [];
            $processedCount = 0;

            foreach ($companies as $company) {
                $companyName = $company['company_name'] ?? $company['title'] ?? 'Unknown';
                
                Log::info("Processing company for rule '{$ruleKey}': {$companyName}");

                try {
                    // Check if we should pause due to too many rate limit errors
                    if ($this->shouldPauseDueToRateLimits()) {
                        $pauseTime = 300; // 5 minutes
                        Log::warning("Too many rate limit errors detected. Pausing processing for {$pauseTime} seconds.");
                        sleep($pauseTime);
                        // Clear the error count after pausing
                        Cache::forget('apollo_rate_limit_errors');
                    }
                    
                    // Find people for this company
                    $people = $this->apolloService->findPeopleForCompany($companyName);
                    $this->statsService->trackApiUsage($ruleKey, 'apollo', 'people_search');
                    
                    if (!empty($people)) {
                        // Enrich people details
                        $contacts = $this->apolloService->enrichPeopleDetails($people);
                        $this->statsService->trackApiUsage($ruleKey, 'apollo', 'bulk_enrich');
                        
                        if (!empty($contacts)) {
                            $allContacts = array_merge($allContacts, $contacts);
                            Log::info("Added " . count($contacts) . " contacts for {$companyName}");
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Error processing company {$companyName} for rule {$ruleKey}: " . $e->getMessage());
                    
                    // Track rate limit errors specifically
                    if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), 'Too Many Requests') !== false) {
                        $this->trackRateLimitError();
                    }
                    
                    continue;
                }

                $processedCount++;
                
                // Rate limiting between companies
                if ($processedCount < $companiesCount) {
                    sleep(2);
                }
            }

            // Step 3: Add contacts to Instantly (if enabled)
            $contactsAdded = 0;
            $instantlyEnabled = $rule['instantly']['enabled'] ?? false;
            
            if (!empty($allContacts) && $instantlyEnabled) {
                $leadListName = $rule['instantly']['lead_list_name'] ?? "CH - {$rule['name']}";
                $contactsAdded = $this->addContactsToInstantly($allContacts, $leadListName);
                $this->statsService->trackApiUsage($ruleKey, 'instantly', 'add_contacts', count($allContacts));
                Log::info("Added {$contactsAdded} contacts to Instantly lead list: {$leadListName}");
            } elseif (!empty($allContacts) && !$instantlyEnabled) {
                Log::info("Instantly integration disabled for rule '{$ruleKey}', skipping contact upload");
            }

            // Mark rule as run and record execution time
            $executionTime = round(microtime(true) - $startTime, 2);
            $this->markRuleAsRun($ruleKey);
            $this->statsService->completeRuleExecution($ruleKey, $companiesCount, count($allContacts), $contactsAdded, $executionTime);

            Log::info("=== Completed rule execution: {$ruleKey} ===", [
                'companies_found' => $companiesCount,
                'contacts_found' => count($allContacts),
                'contacts_added' => $contactsAdded,
                'execution_time' => $executionTime
            ]);

            // Send webhook notification if enabled
            $results = [
                'success' => true,
                'rule_key' => $ruleKey,
                'rule_name' => $rule['name'],
                'companies_found' => $companiesCount,
                'contacts_found' => count($allContacts),
                'contacts_added' => $contactsAdded,
                'execution_time' => $executionTime,
                'contacts' => $allContacts, // Include contact details for webhook
            ];

            // Check webhook configuration before attempting to send
            $webhookEnabled = $rule['webhook']['enabled'] ?? false;
            $webhookUrl = $rule['webhook']['url'] ?? '';
            
            Log::info("Webhook configuration check for rule '{$ruleKey}':", [
                'webhook_enabled' => $webhookEnabled,
                'webhook_url' => $webhookUrl ? 'configured' : 'not configured',
                'webhook_url_length' => strlen($webhookUrl)
            ]);

            try {
                $webhookSent = $this->webhookService->sendRuleResults($ruleKey, $rule, $results);
                if ($webhookSent) {
                    Log::info("Webhook sent successfully for rule: {$ruleKey}");
                } else {
                    Log::warning("Webhook service returned false for rule: {$ruleKey}");
                }
            } catch (\Exception $e) {
                Log::error("Failed to send webhook for rule {$ruleKey}: " . $e->getMessage());
                Log::error("Webhook exception details: " . $e->getTraceAsString());
                // Don't fail the entire rule execution if webhook fails
            }

            return $results;

        } catch (\Exception $e) {
            $this->statsService->recordRuleError($ruleKey, $e->getMessage());
            Log::error("=== Error in rule execution: {$ruleKey} ===", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Execute all rules that are due to run
     */
    public function executeScheduledRules(): array
    {
        $dueRules = $this->getRulesDueToRun();
        $results = [];

        Log::info("Found " . count($dueRules) . " rules due to run", ['rules' => array_keys($dueRules)]);

        foreach ($dueRules as $ruleKey => $rule) {
            try {
                $results[$ruleKey] = $this->executeRule($ruleKey);
            } catch (\Exception $e) {
                Log::error("Failed to execute rule {$ruleKey}: " . $e->getMessage());
                $results[$ruleKey] = [
                    'success' => false,
                    'rule_key' => $ruleKey,
                    'rule_name' => $rule['name'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Search for companies based on rule parameters
     */
    protected function searchCompaniesForRule(string $ruleKey, array $rule): array
    {
        $searchParams = $rule['search_parameters'];
        $daysAgo = $searchParams['days_ago'] ?? ($searchParams['months_ago'] ?? 6) * 30; // Fallback for legacy months_ago
        $maxResults = $searchParams['max_results'] ?? 200;
        // If max_results is 0, use the dynamic quota from ApolloService
        if (isset($searchParams['max_results']) && (int)$searchParams['max_results'] === 0) {
            $maxResults = $this->apolloService->getRemainingDailyRequests();
            Log::info("max_results set to 0, using dynamic Apollo quota: {$maxResults}");
        }
        $checkConfirmationStatement = $searchParams['check_confirmation_statement'] ?? false;

        // Determine date range based on schedule frequency to avoid duplicate processing
        $schedule = $rule['schedule'] ?? [];
        $frequency = $schedule['frequency'] ?? 'daily';
        
        if ($frequency === 'daily') {
            // For daily schedules, search for companies incorporated on a specific day
            // This prevents processing the same companies repeatedly
            $targetDate = now()->subDays($daysAgo)->format('Y-m-d');
            $fromDate = $targetDate;
            $toDate = $targetDate;
            
            Log::info("Daily rule - searching companies incorporated on specific date", [
                'rule' => $ruleKey,
                'target_date' => $targetDate,
                'days_ago' => $daysAgo
            ]);
        } else {
            // For weekly/monthly schedules, use a date range to get more companies per run
            $fromDate = now()->subDays($daysAgo + 30)->format('Y-m-d'); // 30 day range
            $toDate = now()->subDays($daysAgo)->format('Y-m-d');
            
            Log::info("Non-daily rule - searching companies in date range", [
                'rule' => $ruleKey,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'frequency' => $frequency
            ]);
        }
        
        $apiParams = [
            'incorporated_from' => $fromDate,
            'incorporated_to' => $toDate,
            'company_status' => $searchParams['company_status'] ?? 'active',
            'company_type' => $searchParams['company_type'] ?? 'ltd',
        ];

        Log::info("Searching companies for rule '{$ruleKey}' with parameters", $apiParams);

        // Search for companies
        $companiesResponse = $this->companiesHouseService->searchCompanies($apiParams, $maxResults);
        $this->statsService->trackApiUsage($ruleKey, 'companies_house', 'search');

        if (!$companiesResponse || !isset($companiesResponse['items'])) {
            return [];
        }

        $companies = $companiesResponse['items'];

        // Filter by country if specified
        $allowedCountries = $searchParams['allowed_countries'] ?? [];
        if (!empty($allowedCountries)) {
            Log::info("Filtering companies by allowed countries", ['allowed_countries' => $allowedCountries, 'companies_before_filter' => count($companies)]);
            
            $companies = array_filter($companies, function($company) use ($allowedCountries, $ruleKey) {
                // Try both possible country field locations
                $country = $company['registered_office_address']['country'] ?? 
                          $company['address']['country'] ?? 
                          'Unknown';
                
                $normalizedCountry = $this->normalizeCountry($country);
                $allowed = in_array($normalizedCountry, $allowedCountries);
                
                if (!$allowed) {
                    Log::debug("Company filtered out by country for rule '{$ruleKey}'", [
                        'company_name' => $company['company_name'] ?? $company['title'] ?? 'Unknown',
                        'original_country' => $country,
                        'normalized_country' => $normalizedCountry,
                        'allowed_countries' => $allowedCountries
                    ]);
                }
                
                return $allowed;
            });
            
            Log::info("Companies after country filtering", ['companies_after_filter' => count($companies)]);
        }

        // If this rule requires checking confirmation statements, filter further
        if ($checkConfirmationStatement) {
            $companiesMissingStatements = [];
            
            foreach ($companies as $company) {
                try {
                    $result = $this->companiesHouseService->checkConfirmationStatement($company);
                    $this->statsService->trackApiUsage($ruleKey, 'companies_house', 'filing_history');
                    
                    if ($result['missing']) {
                        $companiesMissingStatements[] = $result['company'];
                    }
                } catch (\Exception $e) {
                    Log::warning("Error checking confirmation statement for {$company['company_number']}: " . $e->getMessage());
                    continue;
                }
            }
            
            return $companiesMissingStatements;
        }

        return $companies;
    }

    /**
     * Add contacts to Instantly with custom lead list name
     */
    protected function addContactsToInstantly(array $contacts, string $leadListName): int
    {
        if (empty($contacts)) {
            return 0;
        }

        // Temporarily update the config for this request
        $originalLeadListName = $this->config['instantly']['lead_list_name'] ?? null;
        
        // Create a new instance with updated config
        $tempConfig = $this->config;
        $tempConfig['instantly']['lead_list_name'] = $leadListName;
        
        // Update the config temporarily
        config(['ch-lead-gen.instantly.lead_list_name' => $leadListName]);
        
        try {
            $added = $this->instantlyService->createLeadList($contacts);
            return $added;
        } finally {
            // Restore original config
            if ($originalLeadListName) {
                config(['ch-lead-gen.instantly.lead_list_name' => $originalLeadListName]);
            }
        }
    }

    /**
     * Mark a rule as having been run
     */
    protected function markRuleAsRun(string $ruleKey): void
    {
        Cache::put("ch_lead_gen_rule_last_run_{$ruleKey}", now()->toISOString(), 60 * 60 * 24 * 7); // Keep for a week
    }

    /**
     * Normalize country names for consistent filtering
     */
    protected function normalizeCountry(string $country): string
    {
        $country = strtolower(trim($country));
        
        $ukVariants = ['united kingdom', 'england', 'scotland', 'wales', 'northern ireland', 'great britain', 'uk'];
        if (in_array($country, $ukVariants)) {
            return 'GB';
        }
        
        $usVariants = ['united states', 'united states of america', 'usa', 'us'];
        if (in_array($country, $usVariants)) {
            return 'US';
        }
        
        return strtoupper($country);
    }

    /**
     * Track rate limit errors to detect when we need to pause processing
     */
    protected function trackRateLimitError(): void
    {
        $cacheKey = 'apollo_rate_limit_errors';
        $errors = Cache::get($cacheKey, []);
        
        // Add current timestamp
        $errors[] = time();
        
        // Keep only errors from the last 10 minutes
        $tenMinutesAgo = time() - 600;
        $errors = array_filter($errors, function($timestamp) use ($tenMinutesAgo) {
            return $timestamp > $tenMinutesAgo;
        });
        
        Cache::put($cacheKey, $errors, 600); // Cache for 10 minutes
        
        Log::warning("Rate limit error tracked. Total errors in last 10 minutes: " . count($errors));
    }

    /**
     * Check if we should pause processing due to too many rate limit errors
     */
    protected function shouldPauseDueToRateLimits(): bool
    {
        $cacheKey = 'apollo_rate_limit_errors';
        $errors = Cache::get($cacheKey, []);
        
        // If we have more than 5 rate limit errors in the last 10 minutes, pause
        return count($errors) >= 5;
    }
} 