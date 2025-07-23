<?php

namespace Rococo\ChLeadGen\Commands;

use Illuminate\Console\Command;
use Rococo\ChLeadGen\Services\ApolloService;
use Rococo\ChLeadGen\Services\RuleManagerService;
use Rococo\ChLeadGen\Services\JobTrackingService;
use Illuminate\Support\Facades\Log;

class TestRateLimitDetection extends Command
{
    protected $signature = 'ch-lead-gen:test-rate-limits {--simulate-low-limits : Simulate low rate limits for testing}';
    protected $description = 'Test the hourly rate limit detection functionality';

    private $apolloService;
    private $ruleManagerService;
    private $jobTrackingService;

    public function __construct(
        ApolloService $apolloService,
        RuleManagerService $ruleManagerService,
        JobTrackingService $jobTrackingService
    ) {
        parent::__construct();
        $this->apolloService = $apolloService;
        $this->ruleManagerService = $ruleManagerService;
        $this->jobTrackingService = $jobTrackingService;
    }

    public function handle()
    {
        $this->info('🧪 Testing Rate Limit Detection...');
        
        try {
            // Test 1: Check current rate limits
            $this->info("\n1. Checking current Apollo API rate limits...");
            $canMakeApiCall = $this->apolloService->canMakeApiCall();
            
            $this->table(
                ['Metric', 'Remaining', 'Threshold', 'Status'],
                [
                    ['Minute', $canMakeApiCall['minute_remaining'], $canMakeApiCall['minute_threshold'], $canMakeApiCall['minute_remaining'] >= $canMakeApiCall['minute_threshold'] ? '✅ OK' : '❌ Low'],
                    ['Hour', $canMakeApiCall['hour_remaining'], $canMakeApiCall['hour_threshold'], $canMakeApiCall['hour_remaining'] >= $canMakeApiCall['hour_threshold'] ? '✅ OK' : '❌ Low'],
                    ['Day', $canMakeApiCall['day_remaining'], $canMakeApiCall['day_threshold'], $canMakeApiCall['day_remaining'] >= $canMakeApiCall['day_threshold'] ? '✅ OK' : '❌ Low'],
                ]
            );
            
            $this->info("Overall status: " . ($canMakeApiCall['can_proceed'] ? '✅ Can proceed' : '❌ Cannot proceed'));
            $this->info("Reason: " . $canMakeApiCall['message']);

            // Test 2: Check hourly limit approaching
            $this->info("\n2. Testing hourly limit approaching detection...");
            $hourlyCheck = $this->apolloService->isHourlyLimitApproaching(10, 3);
            
            $this->table(
                ['Metric', 'Remaining', 'Threshold', 'Should Stop'],
                [
                    ['Hour', $hourlyCheck['hourly_remaining'], $hourlyCheck['hourly_threshold'], $hourlyCheck['hourly_remaining'] <= $hourlyCheck['hourly_threshold'] ? '❌ Yes' : '✅ No'],
                    ['Minute', $hourlyCheck['minute_remaining'], $hourlyCheck['minute_threshold'], $hourlyCheck['minute_remaining'] <= $hourlyCheck['minute_threshold'] ? '❌ Yes' : '✅ No'],
                ]
            );
            
            $this->info("Should stop processing: " . ($hourlyCheck['should_stop'] ? '❌ Yes' : '✅ No'));
            $this->info("Reason: " . $hourlyCheck['message']);

            // Test 3: Test with different thresholds
            $this->info("\n3. Testing with different thresholds...");
            
            $thresholds = [
                ['hourly' => 5, 'minute' => 2, 'description' => 'Very Low'],
                ['hourly' => 10, 'minute' => 3, 'description' => 'Low'],
                ['hourly' => 20, 'minute' => 5, 'description' => 'Medium'],
                ['hourly' => 50, 'minute' => 10, 'description' => 'High'],
            ];
            
            $results = [];
            foreach ($thresholds as $threshold) {
                $check = $this->apolloService->isHourlyLimitApproaching($threshold['hourly'], $threshold['minute']);
                $results[] = [
                    $threshold['description'],
                    $threshold['hourly'],
                    $threshold['minute'],
                    $check['should_stop'] ? '❌ Stop' : '✅ Continue',
                    $check['hourly_remaining'] . '/' . $check['minute_remaining']
                ];
            }
            
            $this->table(
                ['Threshold', 'Hour Limit', 'Minute Limit', 'Decision', 'Current (Hour/Min)'],
                $results
            );

            // Test 4: Simulate job with rate limit detection
            if ($this->option('simulate-low-limits')) {
                $this->info("\n4. Simulating job execution with rate limit detection...");
                $this->simulateJobWithRateLimits();
            }

            $this->info("\n✅ Rate limit detection test completed successfully!");
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Test failed: " . $e->getMessage());
            Log::error("Rate limit detection test failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Simulate a job execution with rate limit detection
     */
    private function simulateJobWithRateLimits()
    {
        $this->info("   Simulating job execution...");
        
        // Create a test job
        $jobId = $this->jobTrackingService->generateJobId();
        $this->jobTrackingService->startJob($jobId, [
            'rule_key' => 'test_rule',
            'force_run' => true
        ]);
        
        $this->info("   Job started: {$jobId}");
        
        // Simulate processing companies
        $companies = ['Test Company 1', 'Test Company 2', 'Test Company 3', 'Test Company 4', 'Test Company 5'];
        $processedCount = 0;
        $contactsFound = 0;
        
        foreach ($companies as $company) {
            $this->info("   Processing company: {$company}");
            
            // Check rate limits before processing each company
            $rateLimitCheck = $this->apolloService->isHourlyLimitApproaching(10, 3);
            
            if ($rateLimitCheck['should_stop']) {
                $this->warn("   ⚠️ Rate limit reached! Stopping with partial results.");
                $this->warn("   Hourly remaining: {$rateLimitCheck['hourly_remaining']}, Minute remaining: {$rateLimitCheck['minute_remaining']}");
                break;
            }
            
            // Simulate processing
            $processedCount++;
            $contactsFound += rand(1, 5); // Simulate finding 1-5 contacts
            
            $this->jobTrackingService->updateProgress($jobId, [
                'companies_processed' => $processedCount,
                'current_company' => $company
            ]);
            
            $this->info("   ✅ Processed {$company} (found {$contactsFound} contacts so far)");
            
            // Simulate API delay
            sleep(1);
        }
        
        // Complete the job
        $result = [
            'success' => true,
            'companies_found' => count($companies),
            'companies_processed' => $processedCount,
            'contacts_found' => $contactsFound,
            'contacts_added' => $contactsFound,
            'execution_time' => 5.0,
            'rate_limit_reached' => $processedCount < count($companies),
            'partial_execution' => $processedCount < count($companies),
        ];
        
        if ($result['rate_limit_reached']) {
            $this->jobTrackingService->completeJobWithPartialResults($jobId, $result);
            $this->warn("   Job completed with partial results due to rate limits");
        } else {
            $this->jobTrackingService->completeJob($jobId, $result);
            $this->info("   Job completed successfully");
        }
        
        $this->info("   Final results: {$processedCount}/" . count($companies) . " companies processed, {$contactsFound} contacts found");
    }
} 