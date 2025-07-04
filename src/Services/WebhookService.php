<?php

namespace Rococo\ChLeadGen\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    private $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30.0,
            'connect_timeout' => 10.0,
        ]);
    }

    /**
     * Send webhook notification with rule execution results
     */
    public function sendRuleResults(string $ruleKey, array $rule, array $results): bool
    {
        Log::info("WebhookService::sendRuleResults called for rule '{$ruleKey}'");
        
        if (!$this->isWebhookEnabled($rule)) {
            Log::info("Webhook disabled for rule '{$ruleKey}', skipping webhook notification");
            return true; // Not enabled, so consider it successful
        }

        $webhookUrl = $rule['webhook']['url'] ?? '';
        $webhookSecret = $rule['webhook']['secret'] ?? '';

        Log::info("Webhook configuration for rule '{$ruleKey}':", [
            'webhook_url' => $webhookUrl ? 'configured' : 'not configured',
            'webhook_secret' => $webhookSecret ? 'configured' : 'not configured'
        ]);

        if (empty($webhookUrl)) {
            Log::warning("Webhook enabled for rule '{$ruleKey}' but no URL configured");
            return false;
        }

        try {
            $payload = $this->buildPayload($ruleKey, $rule, $results);
            $headers = $this->buildHeaders($payload, $webhookSecret);

            Log::info("Sending webhook for rule '{$ruleKey}' to: {$webhookUrl}");
            Log::debug("Webhook payload for rule '{$ruleKey}': " . json_encode($payload, JSON_PRETTY_PRINT));

            $response = $this->client->post($webhookUrl, [
                'headers' => $headers,
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            
            Log::debug("Webhook response for rule '{$ruleKey}': Status {$statusCode}, Body: {$responseBody}");
            
            if ($statusCode >= 200 && $statusCode < 300) {
                Log::info("Webhook sent successfully for rule '{$ruleKey}'. Status: {$statusCode}");
                return true;
            } else {
                Log::warning("Webhook failed for rule '{$ruleKey}'. Status: {$statusCode}, Response: {$responseBody}");
                return false;
            }

        } catch (\Exception $e) {
            Log::error("Error sending webhook for rule '{$ruleKey}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if webhook is enabled for a rule
     */
    private function isWebhookEnabled(array $rule): bool
    {
        $enabled = $rule['webhook']['enabled'] ?? false;
        $result = $enabled === true || $enabled === '1' || $enabled === 1;
        
        Log::info("Webhook enabled check:", [
            'raw_enabled_value' => $enabled,
            'enabled_type' => gettype($enabled),
            'result' => $result
        ]);
        
        return $result;
    }

    /**
     * Build the webhook payload
     */
    private function buildPayload(string $ruleKey, array $rule, array $results): array
    {
        return [
            'event' => 'rule_execution_completed',
            'timestamp' => now()->toISOString(),
            'rule' => [
                'key' => $ruleKey,
                'name' => $rule['name'],
                'description' => $rule['description'] ?? '',
            ],
            'results' => [
                'companies_found' => $results['companies_found'] ?? 0,
                'contacts_found' => $results['contacts_found'] ?? 0,
                'contacts_added' => $results['contacts_added'] ?? 0,
                'execution_time' => $results['execution_time'] ?? 0,
                'instantly_lead_list' => $rule['instantly']['lead_list_name'] ?? '',
            ],
            'search_parameters' => [
                'days_ago' => $rule['search_parameters']['days_ago'] ?? null,
                'company_status' => $rule['search_parameters']['company_status'] ?? '',
                'company_type' => $rule['search_parameters']['company_type'] ?? '',
                'allowed_countries' => $rule['search_parameters']['allowed_countries'] ?? [],
                'max_results' => $rule['search_parameters']['max_results'] ?? 0,
            ],
            // Include contact details if available
            'contacts' => $results['contacts'] ?? [],
        ];
    }

    /**
     * Build headers for the webhook request
     */
    private function buildHeaders(array $payload, string $secret = ''): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'CH-Lead-Gen-Webhook/1.0',
            'X-Webhook-Timestamp' => (string) time(),
        ];

        // Add signature if secret is provided
        if (!empty($secret)) {
            $signature = $this->generateSignature($payload, $secret);
            $headers['X-Webhook-Signature'] = $signature;
        }

        return $headers;
    }

    /**
     * Generate HMAC signature for webhook security
     */
    private function generateSignature(array $payload, string $secret): string
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return 'sha256=' . hash_hmac('sha256', $jsonPayload, $secret);
    }

    /**
     * Test a webhook URL to verify it's accessible
     */
    public function testWebhook(string $url, string $secret = ''): array
    {
        try {
            $testPayload = [
                'event' => 'webhook_test',
                'timestamp' => now()->toISOString(),
                'message' => 'This is a test webhook from CH Lead Generation',
            ];

            $headers = $this->buildHeaders($testPayload, $secret);

            $response = $this->client->post($url, [
                'headers' => $headers,
                'json' => $testPayload,
                'timeout' => 10.0,
            ]);

            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
                'message' => 'Webhook test successful',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook test failed: ' . $e->getMessage(),
            ];
        }
    }
} 