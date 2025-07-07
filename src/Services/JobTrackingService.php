<?php

namespace Rococo\ChLeadGen\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class JobTrackingService
{
    protected $cachePrefix = 'ch_lead_gen_job_';
    protected $cacheExpiry = 3600; // 1 hour

    /**
     * Start tracking a job
     */
    public function startJob(string $jobId, array $jobData = []): void
    {
        $jobInfo = [
            'job_id' => $jobId,
            'started_at' => now()->toISOString(),
            'status' => 'running',
            'rule_key' => $jobData['rule_key'] ?? null,
            'force_run' => $jobData['force_run'] ?? false,
            'progress' => [
                'companies_processed' => 0,
                'companies_found' => 0,
                'contacts_found' => 0,
                'current_company' => null,
            ],
            'cancelled' => false,
        ];

        Cache::put($this->cachePrefix . $jobId, $jobInfo, $this->cacheExpiry);
        
        // Store reference to current job
        Cache::put($this->cachePrefix . 'current_job', $jobId, $this->cacheExpiry);
        
        Log::info("Started tracking job: {$jobId}", $jobInfo);
    }

    /**
     * Update job progress
     */
    public function updateProgress(string $jobId, array $progress): void
    {
        $jobInfo = $this->getJob($jobId);
        if (!$jobInfo) {
            return;
        }

        $jobInfo['progress'] = array_merge($jobInfo['progress'], $progress);
        Cache::put($this->cachePrefix . $jobId, $jobInfo, $this->cacheExpiry);
    }

    /**
     * Complete a job
     */
    public function completeJob(string $jobId, array $result = []): void
    {
        $jobInfo = $this->getJob($jobId);
        if (!$jobInfo) {
            return;
        }

        $jobInfo['status'] = 'completed';
        $jobInfo['completed_at'] = now()->toISOString();
        $jobInfo['result'] = $result;

        Cache::put($this->cachePrefix . $jobId, $jobInfo, $this->cacheExpiry);
        
        // Clear current job reference if this was the current job
        $currentJobId = Cache::get($this->cachePrefix . 'current_job');
        if ($currentJobId === $jobId) {
            Cache::forget($this->cachePrefix . 'current_job');
        }
        
        Log::info("Completed job: {$jobId}", $result);
    }

    /**
     * Fail a job
     */
    public function failJob(string $jobId, string $error): void
    {
        $jobInfo = $this->getJob($jobId);
        if (!$jobInfo) {
            return;
        }

        $jobInfo['status'] = 'failed';
        $jobInfo['failed_at'] = now()->toISOString();
        $jobInfo['error'] = $error;

        Cache::put($this->cachePrefix . $jobId, $jobInfo, $this->cacheExpiry);
        
        // Clear current job reference if this was the current job
        $currentJobId = Cache::get($this->cachePrefix . 'current_job');
        if ($currentJobId === $jobId) {
            Cache::forget($this->cachePrefix . 'current_job');
        }
        
        Log::error("Failed job: {$jobId}", ['error' => $error]);
    }

    /**
     * Cancel a job
     */
    public function cancelJob(string $jobId): bool
    {
        $jobInfo = $this->getJob($jobId);
        if (!$jobInfo) {
            return false;
        }

        $jobInfo['cancelled'] = true;
        $jobInfo['cancelled_at'] = now()->toISOString();
        $jobInfo['status'] = 'cancelled';

        Cache::put($this->cachePrefix . $jobId, $jobInfo, $this->cacheExpiry);
        
        // Clear current job reference if this was the current job
        $currentJobId = Cache::get($this->cachePrefix . 'current_job');
        if ($currentJobId === $jobId) {
            Cache::forget($this->cachePrefix . 'current_job');
        }
        
        Log::info("Cancelled job: {$jobId}");
        return true;
    }

    /**
     * Check if a job is cancelled
     */
    public function isJobCancelled(string $jobId): bool
    {
        $jobInfo = $this->getJob($jobId);
        return $jobInfo && ($jobInfo['cancelled'] ?? false);
    }

    /**
     * Get job information
     */
    public function getJob(string $jobId): ?array
    {
        return Cache::get($this->cachePrefix . $jobId);
    }

    /**
     * Get all running jobs
     */
    public function getRunningJobs(): array
    {
        $runningJobs = [];
        
        // For now, we'll just return the most recent job if it's running
        // In a production environment, you might want to use a more sophisticated tracking system
        $recentJob = $this->getMostRecentJob();
        if ($recentJob && $recentJob['status'] === 'running') {
            $runningJobs[$recentJob['job_id']] = $recentJob;
        }
        
        return $runningJobs;
    }

    /**
     * Get the most recent job
     */
    public function getMostRecentJob(): ?array
    {
        // Get the current job ID from cache
        $currentJobId = Cache::get($this->cachePrefix . 'current_job');
        
        if (!$currentJobId) {
            return null;
        }
        
        // Get the job info
        $jobInfo = $this->getJob($currentJobId);
        
        // Only return if the job is still running
        if ($jobInfo && $jobInfo['status'] === 'running') {
            return $jobInfo;
        }
        
        return null;
    }

    /**
     * Clean up old completed jobs
     */
    public function cleanupOldJobs(): void
    {
        // This would clean up old job records
        // For now, we rely on cache expiration
    }

    /**
     * Generate a unique job ID
     */
    public function generateJobId(): string
    {
        return 'lead_gen_' . uniqid() . '_' . time();
    }
} 