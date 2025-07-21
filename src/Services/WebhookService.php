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

        // Add retry logic for webhook failures
        $maxRetries = 3;
        $baseDelay = 5; // Start with 5 seconds delay
        
        // Add a small delay before first attempt to help with rate limiting
        // This is especially important after long-running rule executions
        Log::info("Adding 3-second delay before webhook attempt for rule '{$ruleKey}' to help with rate limiting");
        sleep(3);
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $payload = $this->buildPayload($ruleKey, $rule, $results);
                $headers = $this->buildHeaders($payload, $webhookSecret);

                Log::info("Sending webhook for rule '{$ruleKey}' to: {$webhookUrl} (attempt {$attempt}/{$maxRetries})");
                Log::debug("Webhook payload for rule '{$ruleKey}' (attempt {$attempt}): " . json_encode($payload, JSON_PRETTY_PRINT));
                Log::info("Webhook payload size for rule '{$ruleKey}' (attempt {$attempt}): " . strlen(json_encode($payload)) . " bytes");
                Log::info("Webhook HTTP method: POST");
                Log::info("Webhook headers: " . json_encode($headers));

                $response = $this->client->post($webhookUrl, [
                    'headers' => $headers,
                    'json' => $payload,
                    'timeout' => 30.0, // Increase timeout for webhooks
                ]);

                $statusCode = $response->getStatusCode();
                $responseBody = $response->getBody()->getContents();
                
                Log::debug("Webhook response for rule '{$ruleKey}' (attempt {$attempt}): Status {$statusCode}, Body: {$responseBody}");
                
                if ($statusCode >= 200 && $statusCode < 300) {
                    Log::info("Webhook sent successfully for rule '{$ruleKey}' (attempt {$attempt}). Status: {$statusCode}");
                    return true;
                } else {
                    Log::warning("Webhook failed for rule '{$ruleKey}' (attempt {$attempt}). Status: {$statusCode}, Response: {$responseBody}");
                    Log::error("Full webhook failure details for rule '{$ruleKey}' (attempt {$attempt}): Status {$statusCode}, URL: {$webhookUrl}, Response: {$responseBody}");
                    
                    // If this is the last attempt, return false
                    if ($attempt >= $maxRetries) {
                        return false;
                    }
                    
                    // Wait before retry with exponential backoff
                    $delay = $baseDelay * pow(2, $attempt - 1); // 5s, 10s, 20s
                    Log::info("Waiting {$delay} seconds before webhook retry for rule '{$ruleKey}'");
                    sleep($delay);
                }

            } catch (\Exception $e) {
                Log::error("Error sending webhook for rule '{$ruleKey}' (attempt {$attempt}): " . $e->getMessage());
                Log::error("Full error details for rule '{$ruleKey}' (attempt {$attempt}): " . $e->__toString());
                
                // If this is the last attempt, return false
                if ($attempt >= $maxRetries) {
                    return false;
                }
                
                // Wait before retry with exponential backoff
                $delay = $baseDelay * pow(2, $attempt - 1); // 5s, 10s, 20s
                Log::info("Waiting {$delay} seconds before webhook retry for rule '{$ruleKey}' after exception");
                sleep($delay);
            }
        }
        
        return false;
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
        $contacts = $results['contacts'] ?? [];
        $contactsCount = count($contacts);
        
        // If there are many contacts, exclude them from the payload to reduce size
        // This helps with webhook reliability, especially for Zapier
        $maxContactsInPayload = 10; // Only include first 10 contacts in webhook
        $includeAllContacts = $contactsCount <= $maxContactsInPayload;
        
        $payload = [
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
        ];
        
        // Include contact details based on count
        if ($includeAllContacts) {
            $payload['contacts'] = $contacts;
        } else {
            // Include only first few contacts and add a note
            $payload['contacts'] = array_slice($contacts, 0, $maxContactsInPayload);
            $payload['contacts_note'] = "Showing first {$maxContactsInPayload} of {$contactsCount} contacts. Full contact list available in rule execution results.";
        }
        
        return $payload;
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