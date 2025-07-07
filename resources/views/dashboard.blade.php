@extends('statamic::layout')

@section('title', 'CH Lead Gen Dashboard')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1>CH Lead Gen Dashboard</h1>
        <div class="flex items-center space-x-2">
            <a href="{{ cp_route('ch-lead-gen.settings') }}" class="btn-primary">Settings</a>
            @if($currentJob && $currentJob['status'] === 'running')
                <button type="button" class="btn btn-sm border-red-300 text-red-700 hover:bg-red-50" 
                        onclick="stopCurrentJob('{{ $currentJob['job_id'] }}')">
                    ⏹️ Stop Current Job
                </button>
            @else
                @if(!empty($rules))
                    <button type="button" class="btn-primary" onclick="runAllScheduledRules()">Run All Scheduled</button>
                @else
                    <button type="button" class="btn-primary" onclick="runLeadGeneration()">Run Now (Legacy)</button>
                @endif
            @endif
        </div>
    </div>

    @if(!empty($rules))
        <!-- Rules Overview -->
        <div class="card p-4 mb-6">
            <h2 class="mb-4">Lead Generation Rules</h2>
            <div class="space-y-4">
                @foreach($rules as $ruleKey => $rule)
                    @php
                        $ruleStats = $rulesStats[$ruleKey] ?? [];
                        $summary = $ruleStats['summary'] ?? [];
                        $enabled = $rule['enabled'] ?? false;
                        $schedule = $rule['schedule'] ?? [];
                    @endphp
                    <div class="border border-gray-200 rounded-lg p-4 @if(!$enabled) opacity-75 @endif">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center space-x-3">
                                <div class="flex items-center space-x-2">
                                    <span class="w-3 h-3 rounded-full @if($enabled) bg-green-500 @else bg-gray-400 @endif"></span>
                                    <h3 class="text-lg font-medium">{{ $rule['name'] ?? $ruleKey }}</h3>
                                    @if(!$enabled)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            Disabled
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <button type="button" class="btn-primary btn-sm @if(!$enabled) opacity-50 @endif" 
                                        onclick="runSpecificRule('{{ $ruleKey }}', false)"
                                        @if(!$enabled) title="Rule is disabled" @endif>
                                    ▶️ Run Now
                                </button>
                                @if(!$enabled)
                                    <button type="button" class="btn btn-sm border-yellow-300 text-yellow-700 hover:bg-yellow-50" 
                                            onclick="runSpecificRule('{{ $ruleKey }}', true)"
                                            title="Force run even though disabled">
                                        🔧 Force Run
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="text-sm text-gray-600 mb-3">
                            {{ $rule['description'] ?? 'No description available' }}
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <div class="text-gray-500">Schedule</div>
                                <div class="font-medium">
                                    @if($schedule['enabled'] ?? false)
                                        {{ ucfirst($schedule['frequency'] ?? 'daily') }} at {{ $schedule['time'] ?? '09:00' }}
                                    @else
                                        Manual only
                                    @endif
                                </div>
                            </div>
                            <div>
                                <div class="text-gray-500">Target List</div>
                                <div class="font-medium">{{ $rule['instantly']['lead_list_name'] ?? 'Default' }}</div>
                            </div>
                            <div>
                                <div class="text-gray-500">Total Executions</div>
                                <div class="font-medium">{{ $summary['total_executions'] ?? 0 }}</div>
                            </div>
                            <div>
                                <div class="text-gray-500">Last Run</div>
                                <div class="font-medium">
                                    @if($summary['last_run'] ?? false)
                                        {{ \Carbon\Carbon::parse($summary['last_run'])->diffForHumans() }}
                                    @else
                                        Never
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if($summary['total_executions'] ?? 0 > 0)
                            <div class="mt-3 pt-3 border-t border-gray-200">
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                    <div>
                                        <div class="text-gray-500">Success Rate</div>
                                        <div class="font-medium">
                                            @php
                                                $successRate = $summary['total_executions'] > 0 
                                                    ? round(($summary['successful_executions'] / $summary['total_executions']) * 100, 1) 
                                                    : 0;
                                            @endphp
                                            <span class="@if($successRate >= 80) text-green-600 @elseif($successRate >= 60) text-yellow-600 @else text-red-600 @endif">
                                                {{ $successRate }}%
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-gray-500">Companies Found</div>
                                        <div class="font-medium">{{ number_format($summary['total_companies_found'] ?? 0) }}</div>
                                    </div>
                                    <div>
                                        <div class="text-gray-500">Contacts Found</div>
                                        <div class="font-medium">{{ number_format($summary['total_contacts_found'] ?? 0) }}</div>
                                    </div>
                                    <div>
                                        <div class="text-gray-500">Contacts Added</div>
                                        <div class="font-medium">{{ number_format($summary['total_contacts_added'] ?? 0) }}</div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <!-- API Quota Warning -->
        @if(isset($canMakeApiCall) && isset($apolloApiUsage))
            @php
                // Get the specific people search endpoint usage
                $peopleSearchUsage = null;
                if (!empty($apolloApiUsage['quota_info'])) {
                    foreach ($apolloApiUsage['quota_info'] as $endpoint => $quota) {
                        if (strpos(strtolower($endpoint), 'mixed_people') !== false && strpos(strtolower($endpoint), 'search') !== false) {
                            $peopleSearchUsage = $quota;
                            break;
                        }
                    }
                }
                
                // Use people search endpoint data if available, otherwise fall back to overall data
                if ($peopleSearchUsage) {
                    $dayRemaining = $peopleSearchUsage['day']['remaining'] ?? 0;
                    $dayLimit = $peopleSearchUsage['day']['limit'] ?? 0;
                    $dayUsed = $peopleSearchUsage['day']['consumed'] ?? 0;
                    $dayUsagePercent = $peopleSearchUsage['day']['percentage_used'] ?? 0;
                } else {
                    $dayRemaining = $canMakeApiCall['day_remaining'] ?? 0;
                    $dayLimit = $canMakeApiCall['day_limit'] ?? 0;
                    $dayUsed = $canMakeApiCall['day_used'] ?? 0;
                    $dayUsagePercent = $dayLimit > 0 ? round(($dayUsed / $dayLimit) * 100, 1) : 0;
                }
                
                $dayThreshold = $canMakeApiCall['day_threshold'] ?? 25;
                $showApiWarning = $dayRemaining < $dayThreshold || $dayUsagePercent >= 90;
            @endphp
            
            @if($showApiWarning)
                <div class="card p-6 mb-6 bg-gradient-to-r from-yellow-50 to-orange-50 border-2 border-yellow-300">
                    <div class="flex items-start space-x-4">
                        <div class="flex-shrink-0">
                            <svg class="h-8 w-8 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="text-xl font-bold text-yellow-800">Apollo API Quota Warning</h2>
                                <a href="{{ cp_route('ch-lead-gen.apollo-stats') }}" class="btn-primary btn-sm">📊 View Details</a>
                            </div>
                            <div class="text-sm text-yellow-700 space-y-2">
                                @if($dayRemaining < $dayThreshold)
                                    <div class="flex items-center space-x-2">
                                        <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                                        <span><strong>Daily quota is running low:</strong> {{ number_format($dayRemaining) }} requests remaining (minimum threshold: {{ number_format($dayThreshold) }})</span>
                                    </div>
                                @endif
                                @if($dayUsagePercent >= 90)
                                    <div class="flex items-center space-x-2">
                                        <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                                        <span><strong>Daily usage is very high:</strong> {{ $dayUsagePercent }}% used ({{ number_format($dayUsed) }}/{{ number_format($dayLimit) }} requests)</span>
                                    </div>
                                @endif
                            </div>
                            <div class="mt-4 p-3 bg-yellow-100 border border-yellow-200 rounded-lg">
                                <div class="text-sm text-yellow-800">
                                    <strong>Recommendation:</strong> Consider pausing rule execution until quota resets or upgrading your Apollo plan to avoid rate limiting.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <!-- API Usage Summary (when quota is healthy) -->
                <div class="card p-4 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2>API Usage (Last 7 Days)</h2>
                        <a href="{{ cp_route('ch-lead-gen.apollo-stats') }}" class="btn-primary btn-sm">📊 Apollo API Stats</a>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        @php
                            $totalCH = array_sum(array_column($internalApiUsage, 'companies_house'));
                            $totalApollo = array_sum(array_column($internalApiUsage, 'apollo'));
                            $totalInstantly = array_sum(array_column($internalApiUsage, 'instantly'));
                        @endphp
                        <div class="text-center p-4 bg-blue-50 rounded-lg">
                            <div class="text-2xl font-bold text-blue-600">{{ number_format($totalCH) }}</div>
                            <div class="text-blue-800">Companies House</div>
                        </div>
                        <div class="text-center p-4 bg-green-50 rounded-lg">
                            <div class="text-2xl font-bold text-green-600">{{ number_format($totalApollo) }}</div>
                            <div class="text-green-800">Apollo</div>
                        </div>
                        <div class="text-center p-4 bg-purple-50 rounded-lg">
                            <div class="text-2xl font-bold text-purple-600">{{ number_format($totalInstantly) }}</div>
                            <div class="text-purple-800">Instantly</div>
                        </div>
                    </div>
                    <div class="mt-4 text-sm text-gray-600">
                        <p>💡 Apollo API quota is healthy. For detailed rate limits and real-time usage statistics, click the "Apollo API Stats" button above.</p>
                    </div>
                </div>
            @endif
        @elseif(!empty($internalApiUsage))
            <!-- Fallback API Usage Overview (when Apollo data unavailable) -->
            <div class="card p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2>API Usage (Last 7 Days)</h2>
                    <a href="{{ cp_route('ch-lead-gen.apollo-stats') }}" class="btn-primary btn-sm">📊 Apollo API Stats</a>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @php
                        $totalCH = array_sum(array_column($internalApiUsage, 'companies_house'));
                        $totalApollo = array_sum(array_column($internalApiUsage, 'apollo'));
                        $totalInstantly = array_sum(array_column($internalApiUsage, 'instantly'));
                    @endphp
                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($totalCH) }}</div>
                        <div class="text-blue-800">Companies House</div>
                    </div>
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <div class="text-2xl font-bold text-green-600">{{ number_format($totalApollo) }}</div>
                        <div class="text-green-800">Apollo</div>
                    </div>
                    <div class="text-center p-4 bg-purple-50 rounded-lg">
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($totalInstantly) }}</div>
                        <div class="text-purple-800">Instantly</div>
                    </div>
                </div>
                <div class="mt-4 text-sm text-gray-600">
                    <p>💡 For detailed Apollo API rate limits and real-time usage statistics, click the "Apollo API Stats" button above.</p>
                </div>
            </div>
        @endif
    @else
        <!-- Legacy Configuration Display -->
        <div class="card p-4 mb-6">
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-yellow-800">Legacy Configuration Detected</h3>
                        <div class="mt-2 text-sm text-yellow-700">
                            <p>No rules are configured in your config file. The system will use legacy behavior based on your current search parameters.</p>
                            <p class="mt-1">Consider migrating to the new rule-based system for better flexibility and scheduling.</p>
                        </div>
                    </div>
                </div>
            </div>

            <h2 class="mb-4">Legacy Configuration</h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Schedule</h3>
                    <p class="mt-1">
                        @if($config['schedule']['enabled'])
                            {{ ucfirst($config['schedule']['frequency']) }} at {{ $config['schedule']['time'] }}
                        @else
                            Disabled
                        @endif
                    </p>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Search Parameters</h3>
                    <p class="mt-1">
                        Looking for {{ $config['search']['company_type'] }} companies from {{ $config['search']['months_ago'] }} months ago
                    </p>
                </div>
            </div>
        </div>
    @endif

    <!-- Current Job Status -->
    @if($currentJob && $currentJob['status'] === 'running')
        <div class="card p-4 mb-6 bg-blue-50 border-2 border-blue-200">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-lg font-semibold text-blue-800">🔄 Current Job Running</h2>
                <button type="button" class="btn btn-sm border-red-300 text-red-700 hover:bg-red-50" 
                        onclick="stopCurrentJob('{{ $currentJob['job_id'] }}')">
                    ⏹️ Stop Job
                </button>
            </div>
            <div class="text-sm text-blue-700 space-y-2">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-2 bg-blue-500 rounded-full animate-pulse"></span>
                    <span><strong>Job ID:</strong> {{ $currentJob['job_id'] }}</span>
                </div>
                @if($currentJob['rule_key'])
                    <div class="flex items-center space-x-2">
                        <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                        <span><strong>Rule:</strong> {{ $currentJob['rule_key'] }}</span>
                    </div>
                @endif
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                    <span><strong>Started:</strong> {{ \Carbon\Carbon::parse($currentJob['started_at'])->diffForHumans() }}</span>
                </div>
                @if($currentJob['progress']['current_company'])
                    <div class="flex items-center space-x-2">
                        <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                        <span><strong>Processing:</strong> {{ $currentJob['progress']['current_company'] }}</span>
                    </div>
                @endif
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                    <span><strong>Progress:</strong> {{ $currentJob['progress']['companies_processed'] ?? 0 }} companies processed</span>
                </div>
            </div>
        </div>
    @endif

    <!-- Activity Log -->
    <div class="card p-4">
        <div class="flex items-center justify-between mb-4">
            <h2>Recent Activity</h2>
            <button type="button" class="btn btn-sm" onclick="refreshLogs()">
                🔄 Refresh
            </button>
        </div>
        
        <div id="activity-status" class="hidden">
            <div class="flex items-center text-blue-600 mb-4">
                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Processing... Check logs below for real-time updates
            </div>
        </div>

        <div id="logs-container">
            <div id="no-activity" class="text-gray-500 text-center py-8">
                No recent activity. Click "Run Now" to start lead generation.
            </div>
        </div>
    </div>



    <script>
        let isProcessing = false;
        let refreshInterval = null;

        function runAllScheduledRules() {
            if (!confirm('Are you sure you want to run all scheduled rules now?')) {
                return;
            }
            executeRun(null, false, 'all scheduled rules');
        }

        function runSpecificRule(ruleKey, forceRun = false) {
            const ruleName = document.querySelector(`[onclick*="${ruleKey}"]`).closest('.border').querySelector('h3').textContent;
            const action = forceRun ? 'force run' : 'run';
            
            if (!confirm(`Are you sure you want to ${action} the rule "${ruleName}"?`)) {
                return;
            }
            executeRun(ruleKey, forceRun, `rule: ${ruleName}`);
        }

        function runLeadGeneration() {
            if (!confirm('Are you sure you want to run the lead generation process now?')) {
                return;
            }
            executeRun(null, false, 'legacy lead generation');
        }

        function stopCurrentJob(jobId) {
            if (!confirm('Are you sure you want to stop the current job? This action cannot be undone.')) {
                return;
            }
            
            fetch('{{ cp_route('ch-lead-gen.stop') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    job_id: jobId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Statamic.$toast.success(data.message);
                    // Reload the page to update the UI
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    Statamic.$toast.error(data.message);
                }
            })
            .catch(error => {
                Statamic.$toast.error('An error occurred while stopping the job');
                console.error('Error:', error);
            });
        }

        function executeRun(ruleKey, forceRun, description) {
            // Show processing status
            isProcessing = true;
            const activityStatus = document.getElementById('activity-status');
            const noActivity = document.getElementById('no-activity');
            
            if (activityStatus) {
                activityStatus.classList.remove('hidden');
            }
            if (noActivity) {
                noActivity.style.display = 'none';
            }
            
            // Start refreshing logs
            startLogRefresh();

            const payload = {};
            if (ruleKey) {
                payload.rule_key = ruleKey;
            }
            if (forceRun) {
                payload.force_run = true;
            }

            fetch('{{ cp_route('ch-lead-gen.run') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Statamic.$toast.success(data.message);
                    
                    // Store job ID for potential stopping
                    if (data.job_id) {
                        window.currentJobId = data.job_id;
                    }
                    
                    // Continue monitoring for 2 minutes
                    setTimeout(() => {
                        stopLogRefresh();
                    }, 120000);
                } else {
                    Statamic.$toast.error(data.message);
                    stopLogRefresh();
                }
            })
            .catch(error => {
                Statamic.$toast.error(`An error occurred while running ${description}`);
                console.error('Error:', error);
                stopLogRefresh();
            });
        }



        function startLogRefresh() {
            refreshLogs();
            refreshInterval = setInterval(refreshLogs, 2000); // Refresh every 2 seconds
        }

        function stopLogRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
                refreshInterval = null;
            }
            isProcessing = false;
            const activityStatus = document.getElementById('activity-status');
            if (activityStatus) {
                activityStatus.classList.add('hidden');
            }
        }

        function refreshLogs() {
            fetch('{{ cp_route('ch-lead-gen.logs') }}')
                .then(response => response.json())
                .then(data => {
                    const logsContainer = document.getElementById('logs-container');
                    const noActivity = document.getElementById('no-activity');
                    
                    if (data.logs && data.logs.length > 0) {
                        if (noActivity) {
                            noActivity.style.display = 'none';
                        }
                        
                        const logsHtml = data.logs.map(log => {
                            const levelClass = log.level === 'ERROR' ? 'text-red-600' : 
                                             log.level === 'WARNING' ? 'text-yellow-600' : 
                                             'text-gray-700';
                            
                            return `
                                <div class="flex items-start space-x-3 py-2 border-b border-gray-100 last:border-b-0">
                                    <span class="text-xs text-gray-500 font-mono w-16 flex-shrink-0">${log.formatted_time}</span>
                                    <span class="text-xs font-medium w-12 flex-shrink-0 ${levelClass}">${log.level}</span>
                                    <span class="text-sm flex-1">${log.message}</span>
                                </div>
                            `;
                        }).join('');
                        
                        logsContainer.innerHTML = `<div class="max-h-96 overflow-y-auto">${logsHtml}</div>`;
                    } else if (!isProcessing) {
                        if (noActivity) {
                            noActivity.style.display = 'block';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching logs:', error);
                });
        }

        // Initial load of logs
        document.addEventListener('DOMContentLoaded', function() {
            refreshLogs();
            
            // If there's a current job running, refresh the page periodically to show progress
            @if($currentJob && $currentJob['status'] === 'running')
                setInterval(function() {
                    window.location.reload();
                }, 10000); // Refresh every 10 seconds
            @endif
        });
    </script>
@endsection 