@extends('statamic::layout')

@section('title', 'CH Lead Gen Settings')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1>CH Lead Gen Settings</h1>
        <a href="{{ cp_route('ch-lead-gen.dashboard') }}" class="btn-primary">Back to Dashboard</a>
    </div>

    <div class="card p-4">
        <form id="settings-form" method="POST" action="{{ cp_route('ch-lead-gen.settings.update') }}">
            @csrf
            
            <!-- API Keys Section -->
            <div class="mb-6">
                <h2 class="mb-4">API Keys</h2>
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Companies House API Key</label>
                        <input type="text" name="companies_house_api_key" value="{{ $settings['companies_house_api_key'] }}" class="input-text mt-1 w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Apollo API Key</label>
                        <input type="text" name="apollo_api_key" value="{{ $settings['apollo_api_key'] }}" class="input-text mt-1 w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Apollo Master API Key</label>
                        <input type="text" name="apollo_master_api_key" value="{{ $settings['apollo_master_api_key'] ?? '' }}" class="input-text mt-1 w-full">
                        <p class="text-sm text-gray-500 mt-1">Required for viewing detailed Apollo API usage statistics. This key has higher permissions than the regular API key.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Instantly API Key</label>
                        <input type="text" name="instantly_api_key" value="{{ $settings['instantly_api_key'] }}" class="input-text mt-1 w-full">
                    </div>
                </div>
            </div>

            <!-- Global Schedule Toggle -->
            <div class="mb-6">
                <h2 class="mb-4">Global Settings</h2>
                <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-blue-800">Rule-Based Configuration</h3>
                            <div class="mt-2 text-sm text-blue-700">
                                <p>Individual rules now have their own search parameters, schedules, and Instantly settings. Use the Rules section to configure specific lead generation rules.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Master Schedule Control</label>
                        <div class="mt-1">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="schedule[enabled]" value="1" {{ $settings['schedule']['enabled'] ? 'checked' : '' }} class="form-checkbox">
                                <span class="ml-2">Enable scheduled runs globally</span>
                            </label>
                            <p class="text-sm text-gray-500 mt-1">When disabled, no rules will run automatically regardless of their individual schedule settings. Useful for staging environments.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Logging Section -->
            <div class="mb-6">
                <h2 class="mb-4">Logging</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Enabled</label>
                        <div class="mt-1">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="logging[enabled]" value="1" {{ $settings['logging']['enabled'] ? 'checked' : '' }} class="form-checkbox">
                                <span class="ml-2">Enable logging</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Log Retention (days)</label>
                        <input type="number" name="logging[retention_days]" value="{{ $settings['logging']['retention_days'] }}" min="1" class="input-text mt-1 w-full">
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary">Save Settings</button>
            </div>
        </form>
    </div>

    <script>
        console.log('Settings page script loaded');
        
        function setupForm() {
            console.log('Setting up form handler');
            
            const form = document.getElementById('settings-form');
            if (!form) {
                console.error('Form not found!');
                return;
            }
            
            console.log('Form found, adding event listener');
            
            form.addEventListener('submit', function(e) {
                console.log('Form submit event triggered');
                e.preventDefault();
                
                // Collect form data
                const data = {};
                
                // Collect API keys
                data['companies_house_api_key'] = form.querySelector('[name="companies_house_api_key"]').value || '';
                data['apollo_api_key'] = form.querySelector('[name="apollo_api_key"]').value || '';
                data['apollo_master_api_key'] = form.querySelector('[name="apollo_master_api_key"]').value || '';
                data['instantly_api_key'] = form.querySelector('[name="instantly_api_key"]').value || '';
                
                // Collect global schedule setting
                data['schedule.enabled'] = form.querySelector('[name="schedule[enabled]"]').checked;
                
                // Collect logging settings
                data['logging.enabled'] = form.querySelector('[name="logging[enabled]"]').checked;
                data['logging.retention_days'] = parseInt(form.querySelector('[name="logging[retention_days]"]').value) || 30;
                
                console.log('Form data being sent:', data);

                fetch('{{ cp_route('ch-lead-gen.settings.update') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(data)
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Response:', data);
                    if (data.success) {
                        alert('Settings saved successfully!');
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.message || 'An error occurred while saving settings'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while saving settings');
                });
            });
        }

        // Try multiple ways to run the setup
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupForm);
        } else {
            setupForm();
        }

        // Also try with a timeout as a fallback
        setTimeout(setupForm, 100);
    </script>
@stop 