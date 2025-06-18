@extends('statamic::layout')

@section('title', 'CH Lead Gen Settings')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1>CH Lead Gen Settings</h1>
        <a href="{{ cp_route('ch-lead-gen.index') }}" class="btn-primary">Back to Dashboard</a>
    </div>

    <div class="card p-4">
        <form id="settings-form" method="POST" action="{{ cp_route('ch-lead-gen.settings.update') }}">
            @csrf
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
                        <label class="block text-sm font-medium text-gray-700">Instantly API Key</label>
                        <input type="text" name="instantly_api_key" value="{{ $settings['instantly_api_key'] }}" class="input-text mt-1 w-full">
                    </div>
                </div>
            </div>

            <div class="mb-6">
                <h2 class="mb-4">Schedule</h2>
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Enabled</label>
                        <div class="mt-1">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="schedule[enabled]" value="1" {{ $settings['schedule']['enabled'] ? 'checked' : '' }} class="form-checkbox">
                                <span class="ml-2">Enable scheduled runs</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Frequency</label>
                        <select name="schedule[frequency]" class="input-text mt-1 w-full">
                            <option value="daily" {{ $settings['schedule']['frequency'] === 'daily' ? 'selected' : '' }}>Daily</option>
                            <option value="weekly" {{ $settings['schedule']['frequency'] === 'weekly' ? 'selected' : '' }}>Weekly</option>
                            <option value="monthly" {{ $settings['schedule']['frequency'] === 'monthly' ? 'selected' : '' }}>Monthly</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Time (24h)</label>
                        <input type="time" name="schedule[time]" value="{{ $settings['schedule']['time'] }}" class="input-text mt-1 w-full">
                    </div>
                </div>
            </div>

            <div class="mb-6">
                <h2 class="mb-4">Search Parameters</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Months Ago</label>
                        <input type="number" name="search[months_ago]" value="{{ $settings['search']['months_ago'] }}" min="1" max="12" class="input-text mt-1 w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Company Status</label>
                        <input type="text" name="search[company_status]" value="{{ $settings['search']['company_status'] }}" class="input-text mt-1 w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Company Type</label>
                        <input type="text" name="search[company_type]" value="{{ $settings['search']['company_type'] }}" class="input-text mt-1 w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Allowed Countries</label>
                        <div class="mt-1 space-y-2" id="countries-container">
                            @foreach($settings['search']['allowed_countries'] as $country)
                                <div class="flex items-center">
                                    <input type="text" name="search[allowed_countries][]" value="{{ $country }}" class="input-text w-full">
                                    <button type="button" class="ml-2 text-red-500 hover:text-red-700" onclick="removeCountry(this)">×</button>
                                </div>
                            @endforeach
                            <button type="button" class="btn" onclick="addCountry()">Add Country</button>
                        </div>
                    </div>
                </div>
            </div>

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
        
        function addCountry() {
            console.log('addCountry called');
            const container = document.getElementById('countries-container');
            const addButton = container.querySelector('.btn');
            const newInput = document.createElement('div');
            newInput.className = 'flex items-center';
            newInput.innerHTML = `
                <input type="text" name="search[allowed_countries][]" class="input-text w-full" placeholder="Country code (e.g., GB)">
                <button type="button" class="ml-2 text-red-500 hover:text-red-700" onclick="removeCountry(this)">×</button>
            `;
            container.insertBefore(newInput, addButton);
        }

        function removeCountry(button) {
            console.log('removeCountry called');
            button.parentElement.remove();
        }

        // Try multiple ways to ensure the script runs
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
                data['instantly_api_key'] = form.querySelector('[name="instantly_api_key"]').value || '';
                
                // Collect schedule settings
                data['schedule.enabled'] = form.querySelector('[name="schedule[enabled]"]').checked;
                data['schedule.frequency'] = form.querySelector('[name="schedule[frequency]"]').value || 'daily';
                data['schedule.time'] = form.querySelector('[name="schedule[time]"]').value || '09:00';
                
                // Collect search parameters
                data['search.months_ago'] = parseInt(form.querySelector('[name="search[months_ago]"]').value) || 1;
                data['search.company_status'] = form.querySelector('[name="search[company_status]"]').value || 'active';
                data['search.company_type'] = form.querySelector('[name="search[company_type]"]').value || 'ltd';
                
                // Collect allowed countries
                const countryInputs = form.querySelectorAll('[name="search[allowed_countries][]"]');
                data['search.allowed_countries'] = [];
                countryInputs.forEach(input => {
                    if (input.value.trim()) {
                        data['search.allowed_countries'].push(input.value.trim());
                    }
                });
                
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