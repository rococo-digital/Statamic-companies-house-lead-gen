<?php

namespace Rococo\ChLeadGen\Http\Controllers\CP;

use Illuminate\Http\Request;
use Statamic\Http\Controllers\Controller;
use Statamic\Facades\CP\Toast;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index()
    {
        return view('ch-lead-gen::dashboard', [
            'config' => config('ch-lead-gen'),
        ]);
    }

    public function run()
    {
        try {
            Log::info('=== Dashboard: Lead generation run button clicked ===');
            
            // Dispatch the job
            \Rococo\ChLeadGen\Jobs\RunLeadGeneration::dispatch();
            
            Log::info('=== Dashboard: Job dispatched successfully ===');

            return response()->json([
                'success' => true,
                'message' => 'Lead generation process started successfully'
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
                    strpos($line, 'Lead generation') !== false) {
                    
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
            'Starting lead generation process from dashboard' => '🚀 Starting lead generation process...',
            'Job dispatched successfully' => '✅ Job started successfully',
            'Found 0 companies from API' => '⚠️ No companies found for the specified criteria',
            'No companies found matching criteria' => '📄 Search completed - no matching companies',
            'Processing company:' => '🏢 Processing company:',
            'Company profile retrieved:' => '📋 Retrieved company details:',
            'Searching for people at' => '👥 Searching for contacts at',
            'Found' => '✅ Found',
            'No people found for' => '❌ No contacts found for',
            'Lead generation process completed successfully' => '🎉 Lead generation completed successfully!',
            'Lead generation job finished successfully' => '✅ Process finished successfully'
        ];
        
        foreach ($replacements as $search => $replace) {
            if (strpos($message, $search) !== false) {
                $message = str_replace($search, $replace, $message);
                break;
            }
        }
        
        return $message;
    }
} 