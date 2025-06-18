@extends('statamic::layout')

@section('title', 'CH Lead Gen Dashboard')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1>CH Lead Gen Dashboard</h1>
        <div class="flex items-center">
            <a href="{{ cp_route('ch-lead-gen.settings') }}" class="btn-primary mr-2">Settings</a>
            <button type="button" class="btn-primary" onclick="runLeadGeneration()">Run Now</button>
        </div>
    </div>

    <div class="card p-4 mb-6">
        <h2 class="mb-4">Configuration</h2>
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

    <div class="card p-4">
        <div class="flex items-center justify-between mb-4">
            <h2>Recent Activity</h2>
            <div class="flex items-center">
                <div id="activity-status" class="hidden">
                    <div class="flex items-center text-blue-600">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-sm font-medium">Processing...</span>
                    </div>
                </div>
                <button type="button" onclick="refreshLogs()" class="text-sm text-gray-500 hover:text-gray-700 ml-3">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                </button>
            </div>
        </div>
        <div id="activity-log" class="max-h-64 overflow-y-auto">
            <div class="text-gray-500 text-sm" id="no-activity">
                No recent activity to display.
            </div>
        </div>
    </div>

    <script>
        let isProcessing = false;
        let refreshInterval = null;

        function runLeadGeneration() {
            if (!confirm('Are you sure you want to run the lead generation process now?')) {
                return;
            }

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

            fetch('{{ cp_route('ch-lead-gen.run') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Statamic.$toast.success(data.message);
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
                Statamic.$toast.error('An error occurred while running the process');
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
            fetch('{{ cp_route('ch-lead-gen.logs') }}', {
                headers: {
                    'Accept': 'application/json',
                }
            })
            .then(response => response.json())
            .then(data => {
                displayLogs(data.logs);
            })
            .catch(error => {
                console.error('Error fetching logs:', error);
            });
        }

        function displayLogs(logs) {
            const container = document.getElementById('activity-log');
            const noActivity = document.getElementById('no-activity');
            
            if (!container) {
                console.warn('Activity log container not found');
                return;
            }
            
            if (logs.length === 0) {
                if (noActivity) {
                    noActivity.style.display = 'block';
                }
                return;
            }
            
            if (noActivity) {
                noActivity.style.display = 'none';
            }
            
            const logsHtml = logs.map(log => {
                const levelClass = {
                    'ERROR': 'text-red-600',
                    'WARNING': 'text-yellow-600',
                    'INFO': 'text-blue-600',
                    'DEBUG': 'text-gray-500'
                }[log.level] || 'text-gray-600';
                
                return `
                    <div class="flex items-start space-x-2 py-2 border-b border-gray-100 last:border-b-0">
                        <span class="text-xs text-gray-400 mt-0.5 min-w-[50px]">${log.formatted_time}</span>
                        <span class="text-sm ${levelClass} flex-1">${log.message}</span>
                    </div>
                `;
            }).join('');
            
            container.innerHTML = logsHtml;
            
            // Auto-scroll to bottom
            container.scrollTop = container.scrollHeight;
        }

        // Load logs on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add a small delay to ensure DOM is fully loaded
            setTimeout(() => {
                refreshLogs();
            }, 100);
        });
    </script>
@stop 