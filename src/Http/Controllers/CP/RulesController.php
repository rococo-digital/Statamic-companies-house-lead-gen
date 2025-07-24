<?php

namespace Rococo\ChLeadGen\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rococo\ChLeadGen\Services\RuleManagerService;
use Rococo\ChLeadGen\Services\RuleConfigService;

class RulesController extends Controller
{
    protected $ruleManager;
    protected $ruleConfig;

    public function __construct(RuleManagerService $ruleManager, RuleConfigService $ruleConfig)
    {
        $this->ruleManager = $ruleManager;
        $this->ruleConfig = $ruleConfig;
    }

    public function index()
    {
        $rules = $this->ruleManager->getAllRules();
        
        return view('ch-lead-gen::rules.index', compact('rules'));
    }

    public function create()
    {
        $defaults = config('ch-lead-gen.defaults', []);
        
        return view('ch-lead-gen::rules.create', compact('defaults'));
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'enabled' => 'boolean',
            
            // Search parameters
            'days_ago' => 'required|integer|min:1|max:1825',
            'company_status' => 'required|string|in:active,dissolved,liquidation',
            'company_type' => 'required|string|in:ltd,plc,llp,partnership',
            'allowed_countries' => 'required|array|min:1',
            'max_results' => 'required|integer|min:0|max:1000',
            'check_confirmation_statement' => 'boolean',
            
            // Schedule
            'schedule_enabled' => 'boolean',
            'frequency' => 'required|string|in:daily,weekly,monthly',
            'time' => 'required|string|date_format:H:i',
            'day_of_week' => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            
            // Instantly settings
            'instantly_enabled' => 'boolean',
            'lead_list_name' => 'nullable|string|max:255',
            'enable_enrichment' => 'boolean',
            
            // Webhook settings
            'webhook_enabled' => 'boolean',
            'webhook_url' => 'nullable|url|max:500',
            'webhook_secret' => 'nullable|string|max:100',
        ]);

        // Generate a unique rule key
        $ruleKey = Str::slug($validatedData['name']) . '_' . time();

        // Build rule configuration
        $ruleConfig = [
            'name' => $validatedData['name'],
            'description' => $validatedData['description'] ?? '',
            'enabled' => $validatedData['enabled'] ?? true,
            'search_parameters' => [
                'days_ago' => $validatedData['days_ago'],
                'company_status' => $validatedData['company_status'],
                'company_type' => $validatedData['company_type'],
                'allowed_countries' => $validatedData['allowed_countries'],
                'max_results' => $validatedData['max_results'],
                'check_confirmation_statement' => $validatedData['check_confirmation_statement'] ?? false,
            ],
            'schedule' => [
                'enabled' => $validatedData['schedule_enabled'] ?? false,
                'frequency' => $validatedData['frequency'],
                'time' => $validatedData['time'],
                'day_of_week' => $validatedData['day_of_week'],
                'day_of_month' => $validatedData['day_of_month'],
            ],
            'instantly' => [
                'enabled' => $validatedData['instantly_enabled'] ?? false,
                'lead_list_name' => $validatedData['lead_list_name'] ?? '',
                'enable_enrichment' => $validatedData['enable_enrichment'] ?? false,
            ],
            'webhook' => [
                'enabled' => $validatedData['webhook_enabled'] ?? false,
                'url' => $validatedData['webhook_url'] ?? '',
                'secret' => $validatedData['webhook_secret'] ?? '',
            ],
        ];

        try {
            $this->ruleConfig->addRule($ruleKey, $ruleConfig);
            
            Log::info("Rule created via CP", ['rule_key' => $ruleKey, 'rule_name' => $validatedData['name']]);
            
            return redirect(cp_route('ch-lead-gen.rules.index'))
                ->with('success', "Rule '{$validatedData['name']}' created successfully!");
        } catch (\Exception $e) {
            Log::error("Failed to create rule: " . $e->getMessage());
            
            return back()->withInput()
                ->with('error', 'Failed to create rule: ' . $e->getMessage());
        }
    }

    public function edit($ruleKey)
    {
        $rule = $this->ruleManager->getRule($ruleKey);
        
        if (!$rule) {
            return redirect(cp_route('ch-lead-gen.rules.index'))
                ->with('error', 'Rule not found.');
        }

        return view('ch-lead-gen::rules.edit', compact('rule', 'ruleKey'));
    }

    public function update(Request $request, $ruleKey)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'enabled' => 'boolean',
            
            // Search parameters
            'days_ago' => 'required|integer|min:1|max:1825',
            'company_status' => 'required|string|in:active,dissolved,liquidation',
            'company_type' => 'required|string|in:ltd,plc,llp,partnership',
            'allowed_countries' => 'required|array|min:1',
            'max_results' => 'required|integer|min:0|max:1000',
            'check_confirmation_statement' => 'boolean',
            
            // Schedule
            'schedule_enabled' => 'boolean',
            'frequency' => 'required|string|in:daily,weekly,monthly',
            'time' => 'required|string|date_format:H:i',
            'day_of_week' => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            
            // Instantly settings
            'instantly_enabled' => 'boolean',
            'lead_list_name' => 'nullable|string|max:255',
            'enable_enrichment' => 'boolean',
            
            // Webhook settings
            'webhook_enabled' => 'boolean',
            'webhook_url' => 'nullable|url|max:500',
            'webhook_secret' => 'nullable|string|max:100',
        ]);

        // Build updated rule configuration
        $ruleConfig = [
            'name' => $validatedData['name'],
            'description' => $validatedData['description'] ?? '',
            'enabled' => $validatedData['enabled'] ?? true,
            'search_parameters' => [
                'days_ago' => $validatedData['days_ago'],
                'company_status' => $validatedData['company_status'],
                'company_type' => $validatedData['company_type'],
                'allowed_countries' => $validatedData['allowed_countries'],
                'max_results' => $validatedData['max_results'],
                'check_confirmation_statement' => $validatedData['check_confirmation_statement'] ?? false,
            ],
            'schedule' => [
                'enabled' => $validatedData['schedule_enabled'] ?? false,
                'frequency' => $validatedData['frequency'],
                'time' => $validatedData['time'],
                'day_of_week' => $validatedData['day_of_week'],
                'day_of_month' => $validatedData['day_of_month'],
            ],
            'instantly' => [
                'enabled' => $validatedData['instantly_enabled'] ?? false,
                'lead_list_name' => $validatedData['lead_list_name'] ?? '',
                'enable_enrichment' => $validatedData['enable_enrichment'] ?? false,
            ],
            'webhook' => [
                'enabled' => $validatedData['webhook_enabled'] ?? false,
                'url' => $validatedData['webhook_url'] ?? '',
                'secret' => $validatedData['webhook_secret'] ?? '',
            ],
        ];

        try {
            $this->ruleConfig->updateRule($ruleKey, $ruleConfig);
            
            Log::info("Rule updated via CP", ['rule_key' => $ruleKey, 'rule_name' => $validatedData['name']]);
            
            return redirect(cp_route('ch-lead-gen.rules.index'))
                ->with('success', "Rule '{$validatedData['name']}' updated successfully!");
        } catch (\Exception $e) {
            Log::error("Failed to update rule: " . $e->getMessage());
            
            return back()->withInput()
                ->with('error', 'Failed to update rule: ' . $e->getMessage());
        }
    }

    public function destroy($ruleKey)
    {
        try {
            $rule = $this->ruleManager->getRule($ruleKey);
            $ruleName = $rule['name'] ?? $ruleKey;
            
            $this->ruleConfig->deleteRule($ruleKey);
            
            Log::info("Rule deleted via CP", ['rule_key' => $ruleKey, 'rule_name' => $ruleName]);
            
            return redirect(cp_route('ch-lead-gen.rules.index'))
                ->with('success', "Rule '{$ruleName}' deleted successfully!");
        } catch (\Exception $e) {
            Log::error("Failed to delete rule: " . $e->getMessage());
            
            return back()->with('error', 'Failed to delete rule: ' . $e->getMessage());
        }
    }

    public function duplicate($ruleKey)
    {
        try {
            $originalRule = $this->ruleManager->getRule($ruleKey);
            
            if (!$originalRule) {
                return back()->with('error', 'Rule not found.');
            }

            // Create new rule key and name
            $newRuleKey = $ruleKey . '_copy_' . time();
            $newRule = $originalRule;
            $newRule['name'] = $originalRule['name'] . ' (Copy)';
            $newRule['enabled'] = false; // Disable by default
            
            $this->ruleConfig->addRule($newRuleKey, $newRule);
            
            Log::info("Rule duplicated via CP", [
                'original_rule_key' => $ruleKey, 
                'new_rule_key' => $newRuleKey,
                'rule_name' => $newRule['name']
            ]);
            
            return redirect(cp_route('ch-lead-gen.rules.edit', $newRuleKey))
                ->with('success', "Rule duplicated successfully! You can now customize the copy.");
        } catch (\Exception $e) {
            Log::error("Failed to duplicate rule: " . $e->getMessage());
            
            return back()->with('error', 'Failed to duplicate rule: ' . $e->getMessage());
        }
    }

    public function testWebhook($ruleKey)
    {
        try {
            $rule = $this->ruleManager->getRule($ruleKey);
            
            if (!$rule) {
                return back()->with('error', 'Rule not found.');
            }

            if (!($rule['webhook']['enabled'] ?? false)) {
                return back()->with('error', 'Webhook is not enabled for this rule.');
            }

            $webhookUrl = $rule['webhook']['url'] ?? '';
            $webhookSecret = $rule['webhook']['secret'] ?? '';

            if (empty($webhookUrl)) {
                return back()->with('error', 'Webhook URL is not configured.');
            }

            // Test the webhook
            $webhookService = app(\Rococo\ChLeadGen\Services\WebhookService::class);
            $result = $webhookService->testWebhook($webhookUrl, $webhookSecret);

            if ($result['success']) {
                return back()->with('success', "Webhook test successful! Status: {$result['status_code']}");
            } else {
                return back()->with('error', "Webhook test failed: {$result['message']}");
            }

        } catch (\Exception $e) {
            Log::error("Failed to test webhook: " . $e->getMessage());
            return back()->with('error', 'Failed to test webhook: ' . $e->getMessage());
        }
    }

    public function testWebhookSimulation($ruleKey)
    {
        try {
            $rule = $this->ruleManager->getRule($ruleKey);
            
            if (!$rule) {
                return back()->with('error', 'Rule not found.');
            }

            if (!($rule['webhook']['enabled'] ?? false)) {
                return back()->with('error', 'Webhook is not enabled for this rule.');
            }

            $webhookUrl = $rule['webhook']['url'] ?? '';
            $webhookSecret = $rule['webhook']['secret'] ?? '';

            if (empty($webhookUrl)) {
                return back()->with('error', 'Webhook URL is not configured.');
            }

            // Test the webhook with simulation that matches real rule execution
            $webhookService = app(\Rococo\ChLeadGen\Services\WebhookService::class);
            $result = $webhookService->testWebhookWithRuleSimulation($webhookUrl, $webhookSecret);

            if ($result['success']) {
                $payloadSize = $result['payload_size'] ?? 'unknown';
                return back()->with('success', "Webhook simulation test successful! Status: {$result['status_code']}, Payload size: {$payloadSize} bytes");
            } else {
                return back()->with('error', "Webhook simulation test failed: {$result['message']}");
            }

        } catch (\Exception $e) {
            Log::error("Failed to test webhook simulation: " . $e->getMessage());
            return back()->with('error', 'Failed to test webhook simulation: ' . $e->getMessage());
        }
    }
} 