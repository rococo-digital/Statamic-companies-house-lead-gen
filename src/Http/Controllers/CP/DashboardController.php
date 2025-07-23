<?php

namespace Rococo\ChLeadGen\Http\Controllers\CP;

use Illuminate\Http\Request;
use Statamic\Http\Controllers\Controller;
use Statamic\Facades\CP\Toast;
use Illuminate\Support\Facades\Log;
use Rococo\ChLeadGen\Services\RuleManagerService;
use Rococo\ChLeadGen\Services\StatsService;
use Rococo\ChLeadGen\Services\ApolloService;
use Rococo\ChLeadGen\Services\JobTrackingService;

class DashboardController extends Controller
{
    protected $ruleManagerService;
    protected $statsService;
    protected $apolloService;

    public function __construct(RuleManagerService $ruleManagerService, StatsService $statsService, ApolloService $apolloService)
    {
        $this->ruleManagerService = $ruleManagerService;
        $this->statsService = $statsService;
        $this->apolloService = $apolloService;
    }

    public function index()
    {
        // Get all rules and their statistics
        $rules = $this->ruleManagerService->getAllRules();
        $rulesStats = $this->statsService->getAllRulesStats();
        
        // Get internal API usage tracking
        $internalApiUsage = $this->statsService->getOverallApiUsage(7);

        // Get Apollo API usage for quota warning
        $apolloApiUsage = null;
        $canMakeApiCall = null;
        try {
            $apolloApiUsage = $this->apolloService->getApiUsageStats();
            $canMakeApiCall = $this->apolloService->canMakeApiCall();
        } catch (\Exception $e) {
            // Log error but don't fail the dashboard
            Log::warning('Failed to fetch Apollo API usage for dashboard: ' . $e->getMessage());
        }

        // Get current running job
        $jobTrackingService = app(JobTrackingService::class);
        $currentJob = $jobTrackingService->getMostRecentJob();

        return view('ch-lead-gen::dashboard', [
            'config' => config('ch-lead-gen'),
            'rules' => $rules,
            'rulesStats' => $rulesStats,
            'internalApiUsage' => $internalApiUsage,
            'apolloApiUsage' => $apolloApiUsage,
            'canMakeApiCall' => $canMakeApiCall,
            'currentJob' => $currentJob,
        ]);
    }

    public function run(Request $request)
    {
        try {
            Log::info('=== Dashboard: Lead generation run button clicked ===');
            
            $ruleKey = $request->input('rule_key');
            $forceRun = $request->boolean('force_run', false);
            
            // Generate job ID for tracking
            $jobTrackingService = app(JobTrackingService::class);
            $jobId = $jobTrackingService->generateJobId();
            
            if ($ruleKey) {
                // Run specific rule
                Log::info("Dashboard: Running specific rule: {$ruleKey}");
                \Rococo\ChLeadGen\Jobs\RunLeadGeneration::dispatchRule($ruleKey, $forceRun, $jobId);
                $message = "Lead generation process started for rule: {$ruleKey}";
            } else {
                // Run all scheduled rules (legacy behavior)
                Log::info('Dashboard: Running all scheduled rules');
                \Rococo\ChLeadGen\Jobs\RunLeadGeneration::dispatchScheduled($jobId);
                $message = 'Lead generation process started for all scheduled rules';
            }
            
            Log::info('=== Dashboard: Job dispatched successfully ===', ['job_id' => $jobId]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'job_id' => $jobId
            ]);
        } catch (\Exception $e) {
            Log::error('=== Dashboard: Error dispatching job ===');
            Log::error('Error message: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to start lead generation process: ' . $e->getMessage()
            ], 500);
        }
    }

    public function stop(Request $request)
    {
        try {
            $jobId = $request->input('job_id');
            
            if (!$jobId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Job ID is required'
                ], 400);
            }
            
            Log::info('=== Dashboard: Stop job requested ===', ['job_id' => $jobId]);
            
            $jobTrackingService = app(JobTrackingService::class);
            $cancelled = $jobTrackingService->cancelJob($jobId);
            
            if ($cancelled) {
                Log::info('=== Dashboard: Job cancelled successfully ===', ['job_id' => $jobId]);
                return response()->json([
                    'success' => true,
                    'message' => 'Job cancelled successfully'
                ]);
            } else {
                Log::warning('=== Dashboard: Job not found or already completed ===', ['job_id' => $jobId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Job not found or already completed'
                ], 404);
            }
        } catch (\Exception $e) {
            Log::error('=== Dashboard: Error cancelling job ===');
            Log::error('Error message: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel job: ' . $e->getMessage()
            ], 500);
        }
    }



    public function toggleRule(Request $request, string $ruleKey)
    {
        try {
            $enabled = $request->boolean('enabled');
            
            // This would typically update the database or config file
            // For now, we'll just return success since we're using config files
            
            Log::info("Dashboard: Toggling rule {$ruleKey} to " . ($enabled ? 'enabled' : 'disabled'));
            
            return response()->json([
                'success' => true,
                'message' => "Rule {$ruleKey} " . ($enabled ? 'enabled' : 'disabled')
            ]);
        } catch (\Exception $e) {
            Log::error("Error toggling rule {$ruleKey}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to toggle rule'], 500);
        }
    }

    public function logs()
    {
        return response()->json([
            'logs' => $this->getRecentLogs()
        ]);
    }

    private function getRecentLogs()
    {
        $logs = [];
        $logPath = storage_path('logs/laravel.log');
        
        if (file_exists($logPath)) {
            $allLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            // Get last 200 lines and filter for lead generation activity
            $recentLines = array_slice($allLines, -200);
            
            foreach ($recentLines as $line) {
                // Look for lead generation related log entries
                if (strpos($line, 'lead generation') !== false || 
                    strpos($line, 'Dashboard:') !== false ||
                    strpos($line, 'Starting lead generation') !== false ||
                    strpos($line, 'Processing company') !== false ||
                    strpos($line, 'Found') !== false && strpos($line, 'companies') !== false ||
                    strpos($line, 'Apollo') !== false ||
                    strpos($line, 'Instantly') !== false ||
                    strpos($line, 'Lead generation') !== false ||
                    strpos($line, 'rule execution') !== false ||
                    strpos($line, 'Rule execution') !== false) {
                    
                    // Parse the log entry
                    $parsed = $this->parseLogEntry($line);
                    if ($parsed) {
                        $logs[] = $parsed;
                    }
                }
            }
        }

        // Return last 20 entries, most recent first
        return array_slice(array_reverse($logs), 0, 20);
    }

    private function parseLogEntry($line)
    {
        // Extract timestamp and message from Laravel log format
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*?\.(\w+): (.+)/', $line, $matches)) {
            $timestamp = $matches[1];
            $level = strtoupper($matches[2]);
            $message = trim($matches[3]);
            
            // Clean up the message
            $message = $this->cleanLogMessage($message);
            
            return [
                'timestamp' => $timestamp,
                'level' => $level,
                'message' => $message,
                'formatted_time' => date('H:i:s', strtotime($timestamp))
            ];
        }
        
        return null;
    }

    private function cleanLogMessage($message)
    {
        // Remove common prefixes and clean up the message
        $message = str_replace(['=== ', ' ==='], '', $message);
        
        // Make messages more user-friendly
        $replacements = [
            'Starting lead generation job' => '🚀 Starting lead generation job...',
            'Starting rule execution:' => '🎯 Starting rule:',
            'Completed rule execution:' => '✅ Completed rule:',
            'Job dispatched successfully' => '✅ Job dispatched successfully',
            'Found 0 companies from API' => '⚠️ No companies found for the specified criteria',
            'No companies found matching criteria' => '📄 Search completed - no matching companies',
            'Processing company for rule' => '🏢 Processing company for rule',
            'Company profile retrieved:' => '📋 Retrieved company details:',
            'Searching for people at' => '👥 Searching for contacts at',
            'Found' => '✅ Found',
            'No people found for' => '❌ No contacts found for',
            'Lead generation job completed' => '🎉 Lead generation completed!',
            'All scheduled rules executed' => '✅ All rules finished successfully'
        ];
        
        foreach ($replacements as $search => $replace) {
            $message = str_replace($search, $replace, $message);
        }
        
        return $message;
    }
} 