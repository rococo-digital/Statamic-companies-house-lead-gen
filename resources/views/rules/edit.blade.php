@extends('statamic::layout')

@section('title', 'Edit Rule')

@section('content')
<div class="flex items-center mb-6">
    <a href="{{ cp_route('ch-lead-gen.rules.index') }}" class="flex items-center text-blue-600 hover:text-blue-900 mr-4">
        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
        Back to Rules
    </a>
    <h1 class="flex-1">Edit Rule: {{ $rule['name'] }}</h1>
</div>

@if ($errors->any())
    <div class="publish-error-banner bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
        <ul class="list-disc list-inside">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ cp_route('ch-lead-gen.rules.update', $ruleKey) }}" class="publish-form">
    @csrf
    @method('PUT')
    
    <div class="publish-form-container">
        <!-- Basic Information -->
        <div class="card mb-6">
            <header class="card-header">
                <h2>Basic Information</h2>
            </header>
            <div class="card-body">
                <div class="form-group">
                    <label class="block font-medium">Rule Name *</label>
                    <input type="text" name="name" value="{{ old('name', $rule['name']) }}" 
                           class="input-text" required>
                </div>

                <div class="form-group">
                    <label class="block font-medium">Description</label>
                    <textarea name="description" class="input-text" rows="3">{{ old('description', $rule['description'] ?? '') }}</textarea>
                </div>

                <div class="form-group">
                    <input type="hidden" name="enabled" value="0">
                    <label class="flex items-center">
                        <input type="checkbox" name="enabled" value="1" 
                               {{ old('enabled', $rule['enabled'] ?? true) ? 'checked' : '' }} class="mr-2">
                        <span class="font-medium">Enable this rule</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Search Parameters -->
        <div class="card mb-6">
            <header class="card-header">
                <h2>Search Parameters</h2>
            </header>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="form-group">
                        <label class="block font-medium">Days Ago *</label>
                        <input type="number" name="days_ago" 
                               value="{{ old('days_ago', $rule['search_parameters']['days_ago'] ?? ($rule['search_parameters']['months_ago'] ?? 6) * 30) }}" 
                               min="1" max="1825" class="input-text" required>
                        <div class="help-text">Search for companies incorporated X days ago (1-1825 days = ~5 years)</div>
                    </div>

                    <div class="form-group">
                        <label class="block font-medium">Max Results *</label>
                        <input type="number" name="max_results" 
                               value="{{ old('max_results', $rule['search_parameters']['max_results'] ?? 50) }}" 
                               min="0" max="1000" class="input-text" required>
                        <div class="help-text">Set to 0 to use dynamic quota based on your current API limits.</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="form-group">
                        <label class="block font-medium">Company Status *</label>
                        <select name="company_status" class="input-text" required>
                            <option value="active" {{ old('company_status', $rule['search_parameters']['company_status'] ?? 'active') == 'active' ? 'selected' : '' }}>Active</option>
                            <option value="dissolved" {{ old('company_status', $rule['search_parameters']['company_status'] ?? '') == 'dissolved' ? 'selected' : '' }}>Dissolved</option>
                            <option value="liquidation" {{ old('company_status', $rule['search_parameters']['company_status'] ?? '') == 'liquidation' ? 'selected' : '' }}>Liquidation</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="block font-medium">Company Type *</label>
                        <select name="company_type" class="input-text" required>
                            <option value="ltd" {{ old('company_type', $rule['search_parameters']['company_type'] ?? 'ltd') == 'ltd' ? 'selected' : '' }}>Limited Company (LTD)</option>
                            <option value="plc" {{ old('company_type', $rule['search_parameters']['company_type'] ?? '') == 'plc' ? 'selected' : '' }}>Public Limited Company (PLC)</option>
                            <option value="llp" {{ old('company_type', $rule['search_parameters']['company_type'] ?? '') == 'llp' ? 'selected' : '' }}>Limited Liability Partnership (LLP)</option>
                            <option value="partnership" {{ old('company_type', $rule['search_parameters']['company_type'] ?? '') == 'partnership' ? 'selected' : '' }}>Partnership</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="block font-medium">Allowed Countries *</label>
                    <div class="flex flex-wrap gap-4 mt-2">
                        @php $selectedCountries = old('allowed_countries', $rule['search_parameters']['allowed_countries'] ?? ['GB']); @endphp
                        <label class="flex items-center">
                            <input type="checkbox" name="allowed_countries[]" value="GB" 
                                   {{ in_array('GB', $selectedCountries) ? 'checked' : '' }} class="mr-2">
                            <span>United Kingdom (GB)</span>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="allowed_countries[]" value="US" 
                                   {{ in_array('US', $selectedCountries) ? 'checked' : '' }} class="mr-2">
                            <span>United States (US)</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="flex items-center">
                        <input type="checkbox" name="check_confirmation_statement" value="1" 
                               {{ old('check_confirmation_statement', $rule['search_parameters']['check_confirmation_statement'] ?? false) ? 'checked' : '' }} class="mr-2">
                        <span class="font-medium">Check Confirmation Statements</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Schedule Settings -->
        <div class="card mb-6">
            <header class="card-header">
                <h2>Schedule Settings</h2>
            </header>
            <div class="card-body">
                <div class="form-group">
                    <input type="hidden" name="schedule_enabled" value="0">
                    <label class="flex items-center">
                        <input type="checkbox" name="schedule_enabled" value="1" 
                               {{ old('schedule_enabled', $rule['schedule']['enabled'] ?? true) ? 'checked' : '' }} class="mr-2" id="schedule_enabled">
                        <span class="font-medium">Enable automatic scheduling</span>
                    </label>
                    <div class="help-text">When disabled, rule can only be run manually</div>
                </div>

                <div id="schedule_options" class="grid grid-cols-1 md:grid-cols-4 gap-6 mt-4">
                    <div class="form-group">
                        <label class="block font-medium">Frequency *</label>
                        <select name="frequency" class="input-text" required>
                            <option value="daily" {{ old('frequency', $rule['schedule']['frequency'] ?? 'daily') == 'daily' ? 'selected' : '' }}>Daily</option>
                            <option value="weekly" {{ old('frequency', $rule['schedule']['frequency'] ?? '') == 'weekly' ? 'selected' : '' }}>Weekly</option>
                            <option value="monthly" {{ old('frequency', $rule['schedule']['frequency'] ?? '') == 'monthly' ? 'selected' : '' }}>Monthly</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="block font-medium">Time *</label>
                        <input type="time" name="time" value="{{ old('time', $rule['schedule']['time'] ?? '09:00') }}" class="input-text" required>
                        <div class="help-text">24-hour format</div>
                    </div>

                    <div class="form-group">
                        <label class="block font-medium">Day of Week</label>
                        <select name="day_of_week" class="input-text">
                            <option value="">Not applicable</option>
                            <option value="1" {{ old('day_of_week', $rule['schedule']['day_of_week'] ?? 1) == 1 ? 'selected' : '' }}>Monday</option>
                            <option value="2" {{ old('day_of_week', $rule['schedule']['day_of_week'] ?? '') == 2 ? 'selected' : '' }}>Tuesday</option>
                            <option value="3" {{ old('day_of_week', $rule['schedule']['day_of_week'] ?? '') == 3 ? 'selected' : '' }}>Wednesday</option>
                            <option value="4" {{ old('day_of_week', $rule['schedule']['day_of_week'] ?? '') == 4 ? 'selected' : '' }}>Thursday</option>
                            <option value="5" {{ old('day_of_week', $rule['schedule']['day_of_week'] ?? '') == 5 ? 'selected' : '' }}>Friday</option>
                            <option value="6" {{ old('day_of_week', $rule['schedule']['day_of_week'] ?? '') == 6 ? 'selected' : '' }}>Saturday</option>
                            <option value="7" {{ old('day_of_week', $rule['schedule']['day_of_week'] ?? '') == 7 ? 'selected' : '' }}>Sunday</option>
                        </select>
                        <div class="help-text">For weekly frequency only</div>
                    </div>

                    <div class="form-group">
                        <label class="block font-medium">Day of Month</label>
                        <select name="day_of_month" class="input-text">
                            <option value="">Not applicable</option>
                            @for ($i = 1; $i <= 31; $i++)
                                <option value="{{ $i }}" {{ old('day_of_month', $rule['schedule']['day_of_month'] ?? 1) == $i ? 'selected' : '' }}>{{ $i }}</option>
                            @endfor
                        </select>
                        <div class="help-text">For monthly frequency only</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Instantly Integration -->
        <div class="card mb-6">
            <header class="card-header">
                <h2>Instantly Integration</h2>
            </header>
            <div class="card-body">
                <div class="form-group">
                    <input type="hidden" name="instantly_enabled" value="0">
                    <label class="flex items-center">
                        <input type="checkbox" name="instantly_enabled" value="1" 
                               {{ old('instantly_enabled', $rule['instantly']['enabled'] ?? false) ? 'checked' : '' }} class="mr-2" id="instantly_enabled">
                        <span class="font-medium">Enable Instantly Integration</span>
                    </label>
                    <div class="help-text">Send contacts to Instantly when this rule executes</div>
                </div>

                <div id="instantly_options" class="space-y-4 mt-4">
                    <div class="form-group">
                        <label class="block font-medium">Lead List Name *</label>
                        <input type="text" name="lead_list_name" value="{{ old('lead_list_name', $rule['instantly']['lead_list_name'] ?? '') }}" 
                               class="input-text" placeholder="e.g. CH - 6 Month Companies">
                        <div class="help-text">Name of the lead list in Instantly where contacts will be added</div>
                    </div>

                    <div class="form-group">
                        <input type="hidden" name="enable_enrichment" value="0">
                        <label class="flex items-center">
                            <input type="checkbox" name="enable_enrichment" value="1" 
                                   {{ old('enable_enrichment', $rule['instantly']['enable_enrichment'] ?? false) ? 'checked' : '' }} class="mr-2">
                            <span class="font-medium">Enable enrichment</span>
                        </label>
                        <div class="help-text">Additional enrichment via Instantly (usually not needed as Apollo handles this)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Webhook Settings -->
        <div class="card mb-6">
            <header class="card-header">
                <h2>Webhook Settings</h2>
            </header>
            <div class="card-body">
                <div class="form-group">
                    <input type="hidden" name="webhook_enabled" value="0">
                    <label class="flex items-center">
                        <input type="checkbox" name="webhook_enabled" value="1" 
                               {{ old('webhook_enabled', $rule['webhook']['enabled'] ?? false) ? 'checked' : '' }} class="mr-2" id="webhook_enabled">
                        <span class="font-medium">Enable webhook notifications</span>
                    </label>
                    <div class="help-text">Send rule execution results to a webhook URL when processing completes</div>
                </div>

                <div id="webhook_options" class="space-y-4 mt-4">
                    <div class="form-group">
                        <label class="block font-medium">Webhook URL</label>
                        <input type="url" name="webhook_url" value="{{ old('webhook_url', $rule['webhook']['url'] ?? '') }}" 
                               class="input-text" placeholder="https://example.com/webhook">
                        <div class="help-text">The URL to send webhook notifications to</div>
                    </div>

                    <div class="form-group">
                        <label class="block font-medium">Webhook Secret (Optional)</label>
                        <input type="text" name="webhook_secret" value="{{ old('webhook_secret', $rule['webhook']['secret'] ?? '') }}" 
                               class="input-text" placeholder="Your webhook secret for verification">
                        <div class="help-text">Optional secret for HMAC signature verification. Will be sent in X-Webhook-Signature header.</div>
                    </div>
                </div>

                <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mt-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-blue-800">Webhook Payload</h3>
                            <div class="mt-2 text-sm text-blue-700">
                                <p>Webhook will receive a JSON payload with:</p>
                                <ul class="list-disc pl-5 space-y-1 mt-1">
                                    <li>Rule details (name, key, search parameters)</li>
                                    <li>Execution results (companies found, contacts found/added)</li>
                                    <li>Contact details array</li>
                                    <li>Execution time and timestamp</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-between">
            <a href="{{ cp_route('ch-lead-gen.rules.index') }}" class="btn">Cancel</a>
            <button type="submit" class="btn-primary">Update Rule</button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Schedule options toggle
    const scheduleEnabled = document.getElementById('schedule_enabled');
    const scheduleOptions = document.getElementById('schedule_options');
    
    function toggleScheduleOptions() {
        if (scheduleEnabled.checked) {
            scheduleOptions.style.display = 'grid';
        } else {
            scheduleOptions.style.display = 'none';
        }
    }
    
    scheduleEnabled.addEventListener('change', toggleScheduleOptions);
    toggleScheduleOptions(); // Initialize

    // Webhook options toggle
    const webhookEnabled = document.getElementById('webhook_enabled');
    const webhookOptions = document.getElementById('webhook_options');
    
    function toggleWebhookOptions() {
        if (webhookEnabled.checked) {
            webhookOptions.style.display = 'block';
        } else {
            webhookOptions.style.display = 'none';
        }
    }
    
    webhookEnabled.addEventListener('change', toggleWebhookOptions);
    toggleWebhookOptions(); // Initialize
    
    // Instantly toggle functionality
    const instantlyEnabled = document.getElementById('instantly_enabled');
    const instantlyOptions = document.getElementById('instantly_options');
    
    function toggleInstantlyOptions() {
        if (instantlyEnabled.checked) {
            instantlyOptions.style.display = 'block';
        } else {
            instantlyOptions.style.display = 'none';
        }
    }
    
    instantlyEnabled.addEventListener('change', toggleInstantlyOptions);
    toggleInstantlyOptions(); // Set initial state
});
</script>

@endsection 