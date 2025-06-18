<?php

namespace Rococo\ChLeadGen\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class InstantlyService
{
    private $client;
    private $config;

    public function __construct()
    {
        $this->config = config('ch-lead-gen');
        $this->client = new Client([
            'base_uri' => 'https://api.instantly.ai/',
            'timeout' => 20.0,
        ]);
    }

    public function addContacts($contacts)
    {
        // This method wraps createLeadList for compatibility with the Job
        return $this->createLeadList($contacts);
    }

    public function createLeadList($contacts)
    {
        if (empty($contacts)) {
            Log::info("No contacts to add to Instantly");
            return;
        }

        $leadListName = 'CH_require-confirmation-statement-' . now()->format('Y-m-d_H-i-s');
        Log::info("Creating lead list: {$leadListName}");

        try {
            // Get or create the lead list
            $leadListId = $this->getOrCreateLeadList($leadListName);
            if (!$leadListId) {
                throw new \Exception("Failed to create or get lead list");
            }

            // Add contacts to the lead list
            $added = 0;
            foreach ($contacts as $contact) {
                $firstName = null;
                $lastName = null;
                if (!empty($contact['name'])) {
                    $parts = explode(' ', $contact['name'], 2);
                    $firstName = $parts[0];
                    $lastName = isset($parts[1]) ? $parts[1] : '';
                }

                $payload = [
                    'list_id' => $leadListId,
                    'email' => $contact['email'],
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'company_name' => $contact['company_name_input'] ?? null
                ];

                try {
                    $response = $this->client->post('api/v2/leads', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->config['instantly_api_key'],
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                        ],
                        'json' => $payload
                    ]);

                    $data = json_decode($response->getBody()->getContents(), true);
                    if (isset($data['id'])) {
                        $added++;
                        Log::info("Added lead: {$contact['email']} ({$contact['name']})");
                    } else {
                        Log::warning("Failed to add lead: {$contact['email']} - Response: " . json_encode($data));
                    }
                } catch (\Exception $e) {
                    Log::error("Error adding lead {$contact['email']}: " . $e->getMessage());
                }
            }

            Log::info("Added {$added} of " . count($contacts) . " contacts to Instantly lead list '{$leadListName}'");
            return $added;

        } catch (\Exception $e) {
            Log::error("Error in Instantly integration: " . $e->getMessage());
            throw $e;
        }
    }

    private function getOrCreateLeadList($name)
    {
        try {
            // Try to find existing list
            $response = $this->client->get('api/v2/lead-lists', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['instantly_api_key'],
                    'Accept' => 'application/json',
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['lead_lists'])) {
                foreach ($data['lead_lists'] as $list) {
                    if (strcasecmp($list['name'], $name) === 0) {
                        Log::info("Using existing lead list '{$name}' with ID {$list['id']}");
                        return $list['id'];
                    }
                }
            }

            // Create new list if not found
            $response = $this->client->post('api/v2/lead-lists', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['instantly_api_key'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'name' => $name
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['id'])) {
                Log::info("Created new lead list '{$name}' with ID {$data['id']}");
                return $data['id'];
            }

            throw new \Exception("Failed to create lead list. Response: " . json_encode($data));

        } catch (\Exception $e) {
            Log::error("Error in getOrCreateLeadList: " . $e->getMessage());
            throw $e;
        }
    }
} 