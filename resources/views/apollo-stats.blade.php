@extends('statamic::layout')

@section('title', 'Apollo API Usage Statistics')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1>Apollo API Usage Statistics</h1>
        <div class="flex items-center space-x-2">
            <a href="{{ cp_route('ch-lead-gen.dashboard') }}" class="btn-primary">← Back to Dashboard</a>
            <button type="button" class="btn-primary" onclick="refreshStats()">🔄 Refresh</button>
        </div>
    </div>

    <!-- API Call Status -->
    @if(isset($canMakeApiCall))
        <div class="card p-4 mb-6">
            <h2 class="mb-4">API Call Status</h2>
            <div class="flex items-center space-x-4">
                @if($canMakeApiCall['can_proceed'])
                    <div class="flex items-center text-green-600">
                        <svg class="h-5 w-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <span class="font-medium">Ready to make API calls</span>
                    </div>
                @else
                    <div class="flex items-center text-red-600">
                        <svg class="h-5 w-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                        <span class="font-medium">Insufficient API quota - cannot make API calls</span>
                    </div>
                @endif
            </div>
            
            <!-- Actual API Usage -->
            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="text-sm font-medium text-blue-800 mb-2">Actual API Usage (Raw Limits)</div>
                <div class="text-sm text-blue-700">
                    @php
                        $rawLimits = $canMakeApiCall['raw_limits'] ?? $rawApiLimits;
                        $minuteUsed = $rawLimits['per_minute']['used'] ?? 0;
                        $minuteLimit = $rawLimits['per_minute']['limit'] ?? 0;
                        $hourUsed = $rawLimits['per_hour']['used'] ?? 0;
                        $hourLimit = $rawLimits['per_hour']['limit'] ?? 0;
                        $dayUsed = $rawLimits['per_day']['used'] ?? 0;
                        $dayLimit = $rawLimits['per_day']['limit'] ?? 0;
                    @endphp
                    Minute: {{ $minuteUsed }}/{{ $minuteLimit }} ({{ $canMakeApiCall['minute_remaining'] }} remaining, min: {{ $canMakeApiCall['minute_threshold'] ?? 10 }}) | 
                    Hour: {{ $hourUsed }}/{{ $hourLimit }} ({{ $canMakeApiCall['hour_remaining'] }} remaining, min: {{ $canMakeApiCall['hour_threshold'] ?? 50 }}) | 
                    Day: {{ $dayUsed }}/{{ $dayLimit }} ({{ $canMakeApiCall['day_remaining'] }} remaining, min: {{ $canMakeApiCall['day_threshold'] ?? 25 }})
                </div>
                <div class="text-xs text-blue-600 mt-1">
                    These are the actual API usage and remaining requests from Apollo. The system checks if remaining requests meet minimum thresholds for rule execution.
                </div>
            </div>
            
            <!-- Adjusted Limits (with safety margin) -->
            @if(!empty($canMakeApiCall['adjusted_limits']))
                <div class="mt-3 p-3 bg-gray-50 border border-gray-200 rounded-lg">
                    <div class="text-sm font-medium text-gray-800 mb-2">Adjusted Limits (with {{ config('ch-lead-gen.apollo.safety_margin', 0.9) * 100 }}% safety margin)</div>
                    <div class="text-sm text-gray-700">
                        @php
                            $adjustedLimits = $canMakeApiCall['adjusted_limits'];
                        @endphp
                        Minute: {{ $adjustedLimits['per_minute']['used'] ?? 0 }}/{{ $adjustedLimits['per_minute']['limit'] ?? 0 }} ({{ $adjustedLimits['per_minute']['remaining'] ?? 0 }} remaining) | 
                        Hour: {{ $adjustedLimits['per_hour']['used'] ?? 0 }}/{{ $adjustedLimits['per_hour']['limit'] ?? 0 }} ({{ $adjustedLimits['per_hour']['remaining'] ?? 0 }} remaining) | 
                        Day: {{ $adjustedLimits['per_day']['used'] ?? 0 }}/{{ $adjustedLimits['per_day']['limit'] ?? 0 }} ({{ $adjustedLimits['per_day']['remaining'] ?? 0 }} remaining)
                    </div>
                    <div class="text-xs text-gray-600 mt-1">
                        These are the adjusted limits used by the system's rate limiting (with safety margin applied).
                    </div>
                </div>
            @endif
            
            <!-- Warning when close to thresholds or high usage -->
            @if($canMakeApiCall['can_proceed'])
                @php
                    $rawLimits = $canMakeApiCall['raw_limits'] ?? $rawApiLimits;
                    $minuteClose = $canMakeApiCall['minute_remaining'] <= ($canMakeApiCall['minute_threshold'] ?? 10) * 1.5;
                    $hourClose = $canMakeApiCall['hour_remaining'] <= ($canMakeApiCall['hour_threshold'] ?? 50) * 1.5;
                    $dayClose = $canMakeApiCall['day_remaining'] <= ($canMakeApiCall['day_threshold'] ?? 25) * 1.5;
                    
                    // Check for high usage percentages
                    $dayUsagePercent = $rawLimits['per_day']['limit'] > 0 ? round(($rawLimits['per_day']['used'] / $rawLimits['per_day']['limit']) * 100, 1) : 0;
                    $hourUsagePercent = $rawLimits['per_hour']['limit'] > 0 ? round(($rawLimits['per_hour']['used'] / $rawLimits['per_hour']['limit']) * 100, 1) : 0;
                    $minuteUsagePercent = $rawLimits['per_minute']['limit'] > 0 ? round(($rawLimits['per_minute']['used'] / $rawLimits['per_minute']['limit']) * 100, 1) : 0;
                    
                    $highDayUsage = $dayUsagePercent >= 90;
                    $highHourUsage = $hourUsagePercent >= 90;
                    $highMinuteUsage = $minuteUsagePercent >= 90;
                @endphp
                @if($minuteClose || $hourClose || $dayClose || $highDayUsage || $highHourUsage || $highMinuteUsage)
                    <div class="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <div class="text-sm font-medium text-yellow-800 mb-2">⚠️ API Quota Warning</div>
                        <div class="text-sm text-yellow-700">
                            @if($dayClose)
                                <div>• Daily quota is running low ({{ $canMakeApiCall['day_remaining'] }} remaining)</div>
                            @endif
                            @if($hourClose)
                                <div>• Hourly quota is running low ({{ $canMakeApiCall['hour_remaining'] }} remaining)</div>
                            @endif
                            @if($minuteClose)
                                <div>• Minute quota is running low ({{ $canMakeApiCall['minute_remaining'] }} remaining)</div>
                            @endif
                            @if($highDayUsage)
                                <div>• Daily usage is very high ({{ $dayUsagePercent }}% used)</div>
                            @endif
                            @if($highHourUsage)
                                <div>• Hourly usage is very high ({{ $hourUsagePercent }}% used)</div>
                            @endif
                            @if($highMinuteUsage)
                                <div>• Minute usage is very high ({{ $minuteUsagePercent }}% used)</div>
                            @endif
                        </div>
                        <div class="text-xs text-yellow-600 mt-1">
                            Consider pausing rule execution until quota resets or upgrading your Apollo plan.
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @endif

    @if(!empty($apolloApiUsage) && !isset($apolloApiUsage['error']))
        <!-- Rate Limits -->
        @if(!empty($apolloApiUsage['rate_limits']))
            <div class="card p-4 mb-6">
                <h2 class="mb-4">Rate Limits</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach(['per_minute', 'per_hour', 'per_day'] as $period)
                        @php
                            $rateLimit = $apolloApiUsage['rate_limits'][$period] ?? [];
                            $limit = $rateLimit['limit'] ?? 0;
                            $used = $rateLimit['used'] ?? 0;
                            $remaining = $rateLimit['remaining'] ?? 0;
                            $percentage = $limit > 0 ? round(($used / $limit) * 100, 1) : 0;
                            $colorClass = $percentage >= 80 ? 'text-red-600' : ($percentage >= 60 ? 'text-yellow-600' : 'text-green-600');
                        @endphp
                        <div class="p-4 border rounded-lg">
                            <div class="text-sm text-gray-500 mb-1">{{ ucfirst(str_replace('_', ' ', $period)) }}</div>
                            <div class="text-lg font-medium {{ $colorClass }}">
                                {{ number_format($used) }} / {{ number_format($limit) }}
                            </div>
                            <div class="text-sm text-gray-500">{{ $percentage }}% used ({{ number_format($remaining) }} remaining)</div>
                            @if($percentage >= 80)
                                <div class="text-xs text-red-600 mt-1">⚠️ Approaching limit</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Detailed Quota by Endpoint -->
        @if(!empty($apolloApiUsage['quota_info']))
            <div class="card p-4 mb-6">
                <h2 class="mb-4">Detailed Quota by Endpoint</h2>
                <div class="space-y-4">
                    @foreach($apolloApiUsage['quota_info'] as $endpoint => $quota)
                        @if($quota['day']['consumed'] > 0 || $quota['hour']['consumed'] > 0 || $quota['minute']['consumed'] > 0)
                            <div class="border rounded-lg p-4">
                                <h3 class="text-lg font-medium mb-3">{{ $endpoint }}</h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    @foreach(['day', 'hour', 'minute'] as $period)
                                        @php
                                            $periodQuota = $quota[$period];
                                            $percentage = $periodQuota['percentage_used'] ?? 0;
                                            $colorClass = $percentage >= 80 ? 'text-red-600' : ($percentage >= 60 ? 'text-yellow-600' : 'text-green-600');
                                        @endphp
                                        <div class="text-center">
                                            <div class="text-sm text-gray-500 mb-1">{{ ucfirst($period) }}</div>
                                            <div class="text-md font-medium {{ $colorClass }}">
                                                {{ number_format($periodQuota['consumed']) }} / {{ number_format($periodQuota['limit']) }}
                                            </div>
                                            <div class="text-xs text-gray-500">{{ $percentage }}% used</div>
                                            <div class="text-xs text-gray-400">{{ number_format($periodQuota['remaining']) }} left</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <!-- API Endpoint Usage -->
        @if(!empty($apolloApiUsage['endpoint_usage']))
            <div class="card p-4 mb-6">
                <h2 class="mb-4">Endpoint Usage (Today)</h2>
                <div class="space-y-2">
                    @foreach($apolloApiUsage['endpoint_usage'] as $endpoint => $usage)
                        @if($usage > 0)
                            <div class="flex justify-between items-center p-2 bg-gray-50 rounded">
                                <span class="text-sm font-medium">{{ $endpoint }}</span>
                                <span class="text-sm text-gray-600">{{ number_format($usage) }} requests</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Raw Data (for debugging) -->
        @if(config('app.debug') && !empty($apolloApiUsage['raw_data']))
            <div class="card p-4">
                <h2 class="mb-4">Raw API Response (Debug Mode)</h2>
                <pre class="bg-gray-100 p-4 rounded text-xs overflow-auto">{{ json_encode($apolloApiUsage['raw_data'], JSON_PRETTY_PRINT) }}</pre>
            </div>
        @endif

    @elseif(isset($apolloApiUsage['error']))
        <!-- Error Display -->
        <div class="card p-4">
            @if($apolloApiUsage['error'] === 'master_key_required')
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-blue-800">Apollo API Usage Stats</h3>
                            <div class="mt-2 text-sm text-blue-700">
                                <p>To view detailed Apollo API usage statistics, you need to configure a master API key.</p>
                                <p class="mt-1">Add your master API key to <code>config/ch-lead-gen.php</code>:</p>
                                <pre class="mt-2 text-xs bg-blue-100 p-2 rounded">'apollo_master_api_key' => 'your_master_api_key_here',</pre>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">Apollo API Stats Unavailable</h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <p>{{ $apolloApiUsage['message'] ?? 'Unable to fetch Apollo API usage statistics.' }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <script>
        function refreshStats() {
            window.location.reload();
        }
    </script>
@endsection 