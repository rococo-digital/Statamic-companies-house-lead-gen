@extends('statamic::layout')

@section('title', 'Lead Generation Rules')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="flex-1">Lead Generation Rules</h1>
    <a href="{{ cp_route('ch-lead-gen.rules.create') }}" class="btn-primary">Create New Rule</a>
</div>

@if (session('success'))
    <div class="publish-success-banner bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
        {{ session('success') }}
    </div>
@endif

@if (session('error'))
    <div class="publish-error-banner bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
        {{ session('error') }}
    </div>
@endif

<div class="card overflow-hidden">
    @if (empty($rules))
        <div class="text-center py-8">
            <svg class="w-16 h-16 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No rules configured</h3>
            <p class="text-gray-600 mb-4">Create your first lead generation rule to get started.</p>
            <a href="{{ cp_route('ch-lead-gen.rules.create') }}" class="btn-primary">Create First Rule</a>
        </div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Schedule</th>
                    <th>Search Parameters</th>
                    <th>Max Results</th>
                    <th>Instantly</th>
                    <th>Webhook</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rules as $ruleKey => $rule)
                    <tr>
                        <td>
                            <div class="flex flex-col">
                                <span class="font-medium">{{ $rule['name'] }}</span>
                                @if (!empty($rule['description']))
                                    <span class="text-sm text-gray-600">{{ $rule['description'] }}</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            @if ($rule['enabled'] ?? false)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <svg class="w-2 h-2 mr-1" fill="currentColor" viewBox="0 0 8 8">
                                        <circle cx="4" cy="4" r="3"/>
                                    </svg>
                                    Enabled
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    <svg class="w-2 h-2 mr-1" fill="currentColor" viewBox="0 0 8 8">
                                        <circle cx="4" cy="4" r="3"/>
                                    </svg>
                                    Disabled
                                </span>
                            @endif
                        </td>
                        <td>
                            @if ($rule['schedule']['enabled'] ?? false)
                                <div class="text-sm">
                                    <div class="font-medium">{{ ucfirst($rule['schedule']['frequency']) }}</div>
                                    <div class="text-gray-600">{{ $rule['schedule']['time'] }}</div>
                                </div>
                            @else
                                <span class="text-gray-400">Manual only</span>
                            @endif
                        </td>
                        <td>
                            <div class="text-sm">
                                <div>{{ $rule['search_parameters']['days_ago'] ?? ($rule['search_parameters']['months_ago'] ?? 'N/A') * 30 }} days ago</div>
                                <div class="text-gray-600">
                                    {{ ucfirst($rule['search_parameters']['company_status'] ?? 'active') }} 
                                    {{ strtoupper($rule['search_parameters']['company_type'] ?? 'ltd') }}
                                </div>
                                <div class="text-gray-600">
                                    {{ implode(', ', $rule['search_parameters']['allowed_countries'] ?? []) }}
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="font-mono">{{ $rule['search_parameters']['max_results'] ?? 'N/A' }}</span>
                        </td>
                        <td>
                            @if ($rule['instantly']['enabled'] ?? false)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                    <svg class="w-2 h-2 mr-1" fill="currentColor" viewBox="0 0 8 8">
                                        <circle cx="4" cy="4" r="3"/>
                                    </svg>
                                    Enabled
                                </span>
                                @if (!empty($rule['instantly']['lead_list_name']))
                                    <div class="text-xs text-gray-600 mt-1">{{ $rule['instantly']['lead_list_name'] }}</div>
                                @endif
                            @else
                                <span class="text-gray-400">Disabled</span>
                            @endif
                        </td>
                        <td>
                            @if ($rule['webhook']['enabled'] ?? false)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <svg class="w-2 h-2 mr-1" fill="currentColor" viewBox="0 0 8 8">
                                        <circle cx="4" cy="4" r="3"/>
                                    </svg>
                                    Enabled
                                </span>
                            @else
                                <span class="text-gray-400">Disabled</span>
                            @endif
                        </td>
                        <td>
                            <div class="flex flex-col space-y-1">
                                <!-- Primary Actions Row -->
                                <div class="flex items-center space-x-1">
                                    <a href="{{ cp_route('ch-lead-gen.rules.edit', $ruleKey) }}" 
                                       class="text-blue-600 hover:text-blue-900 p-1 rounded hover:bg-blue-50" title="Edit">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </a>
                                    
                                    <form method="POST" action="{{ cp_route('ch-lead-gen.rules.duplicate', $ruleKey) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-green-600 hover:text-green-900 p-1 rounded hover:bg-green-50" title="Duplicate">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                                
                                <!-- Secondary Actions Row -->
                                <div class="flex items-center space-x-1">
                                    @if ($rule['webhook']['enabled'] ?? false)
                                        <form method="POST" action="{{ cp_route('ch-lead-gen.rules.test-webhook', $ruleKey) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-purple-600 hover:text-purple-900 p-1 rounded hover:bg-purple-50" title="Test Webhook">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    @endif
                                    
                                    <form method="POST" action="{{ cp_route('ch-lead-gen.rules.destroy', $ruleKey) }}" 
                                          class="inline" 
                                          onsubmit="return confirm('Are you sure you want to delete this rule? This action cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-900 p-1 rounded hover:bg-red-50" title="Delete">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="mt-8">
    <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800">Configuration Guidelines</h3>
                <div class="mt-2 text-sm text-blue-700">
                    <ul class="list-disc pl-5 space-y-1">
                        <li><strong>Testing:</strong> Use 5-10 max results for safe testing</li>
                        <li><strong>Daily runs:</strong> 25-50 results work well for regular operation</li>
                        <li><strong>Weekly/Monthly:</strong> 100-500 results for batch processing</li>
                        <li><strong>API costs:</strong> ~3-6 API calls per company (CH + Apollo + Instantly)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection 