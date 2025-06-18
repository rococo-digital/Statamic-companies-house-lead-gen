<?php

namespace Rococo\ChLeadGen\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ApolloService
{
    private $client;
    private $config;
    private $lastRequestTime;
    private const APOLLO_API_PEOPLE_SEARCH_URL = 'https://api.apollo.io/api/v1/mixed_people/search';
    private const APOLLO_API_BULK_PEOPLE_ENRICH_URL = 'https://api.apollo.io/api/v1/people/bulk_match';
    private const RATE_LIMIT_PER_MINUTE = 120;
    private const MIN_REQUEST_INTERVAL = 0.5; // 0.5 seconds = 120 requests per minute

    public function __construct()
    {
        $this->config = config('ch-lead-gen');
        $this->client = new Client([
            'timeout' => 30.0,
        ]);
    }

    /**
     * Rate limiting to ensure we don't exceed 120 requests per minute
     */
    private function rateLimit()
    {
        if ($this->lastRequestTime) {
            $timeSinceLastRequest = microtime(true) - $this->lastRequestTime;
            if ($timeSinceLastRequest < self::MIN_REQUEST_INTERVAL) {
                $sleepMicroseconds = (int)((self::MIN_REQUEST_INTERVAL - $timeSinceLastRequest) * 1000000);
                if ($sleepMicroseconds > 0) {
                    Log::debug("Rate limiting: sleeping for " . ($sleepMicroseconds / 1000000) . " seconds");
                    usleep($sleepMicroseconds);
                }
            }
        }
        $this->lastRequestTime = microtime(true);
    }

    /**
     * Track API usage in cache to monitor rate limits
     */
    private function trackApiUsage($endpoint)
    {
        $cacheKey = 'apollo_api_usage_' . date('Y-m-d_H:i');
        $currentUsage = Cache::get($cacheKey, 0);
        $newUsage = $currentUsage + 1;
        
        Cache::put($cacheKey, $newUsage, 120); // Store for 2 minutes
        
        if ($newUsage > self::RATE_LIMIT_PER_MINUTE) {
            Log::warning("Apollo API rate limit potentially exceeded: {$newUsage} requests in current minute for {$endpoint}");
        }
        
        Log::debug("Apollo API usage: {$newUsage} requests in current minute for {$endpoint}");
    }

    public function findPeopleForCompany($companyName)
    {
        Log::info("Searching people for company: {$companyName}");
        
        try {
            // Apply rate limiting before making request
            $this->rateLimit();
            $this->trackApiUsage('people_search');
            
            $response = $this->client->post(self::APOLLO_API_PEOPLE_SEARCH_URL, [
                'json' => [
                    'q_organization_name' => $companyName,
                    'per_page' => 25
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $this->config['apollo_api_key']
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['people']) || empty($data['people'])) {
                Log::info("No people found in Apollo database for {$companyName}");
                return [];
            }

            $people = [];
            foreach ($data['people'] as $person) {
                $people[] = [
                    'id' => $person['id'] ?? null,
                    'name' => $person['name'] ?? 'N/A',
                    'title' => $person['title'] ?? 'N/A',
                    'first_name' => $person['first_name'] ?? null,
                    'last_name' => $person['last_name'] ?? null,
                    'organization_id' => $person['organization_id'] ?? null,
                    'linkedin_url' => $person['linkedin_url'] ?? null,
                    'company_name_input' => $companyName
                ];
            }

            Log::info("Found " . count($people) . " potential people for {$companyName}");
            return $people;

        } catch (\Exception $e) {
            Log::error("Error querying Apollo People Search for {$companyName}: " . $e->getMessage());
            throw $e;
        }
    }

    public function enrichPeopleDetails($people)
    {
        if (empty($people)) {
            return [];
        }

        Log::info("Enriching details for " . count($people) . " people...");
        $foundContactsWithEmail = [];
        $batches = array_chunk($people, 10);

        foreach ($batches as $batchIndex => $batch) {
            Log::info("Processing enrichment batch " . ($batchIndex + 1) . " of " . count($batches));
            
            $detailsForApi = [];
            foreach ($batch as $person) {
                $detail = [];
                if (!empty($person['first_name'])) $detail['first_name'] = $person['first_name'];
                if (!empty($person['last_name'])) $detail['last_name'] = $person['last_name'];
                if (!empty($person['name'])) $detail['name'] = $person['name'];
                if (!empty($person['organization_id'])) $detail['organization_id'] = $person['organization_id'];
                if (!empty($person['linkedin_url'])) $detail['linkedin_url'] = $person['linkedin_url'];

                if (!empty($detail['name']) || (!empty($detail['first_name']) && !empty($detail['last_name']))) {
                    $detailsForApi[] = $detail;
                }
            }

            if (empty($detailsForApi)) {
                Log::info("Skipping batch as no valid person details to send for enrichment");
                continue;
            }

            try {
                // Apply rate limiting before making request
                $this->rateLimit();
                $this->trackApiUsage('bulk_enrich');

                $response = $this->client->post(self::APOLLO_API_BULK_PEOPLE_ENRICH_URL, [
                    'json' => [
                        'reveal_personal_emails' => true,
                        'details' => $detailsForApi
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'X-Api-Key' => $this->config['apollo_api_key']
                    ]
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $contactsInResponse = $data['matches'] ?? $data['people'] ?? [];

                if (!empty($contactsInResponse)) {
                    foreach ($contactsInResponse as $personIndex => $enrichedPerson) {
                        if (!empty($enrichedPerson['email'])) {
                            $foundContactsWithEmail[] = [
                                'name' => $enrichedPerson['name'] ?? 'N/A',
                                'email' => $enrichedPerson['email'],
                                'title' => $enrichedPerson['title'] ?? 'N/A',
                                'company_name_input' => $batch[$personIndex]['company_name_input'] ?? 'Unknown Company'
                            ];
                            Log::info("Found email for: {$enrichedPerson['email']}");
                        }
                    }
                }

            } catch (\Exception $e) {
                Log::error("Error during Bulk People Enrichment: " . $e->getMessage());
                throw $e;
            }
        }

        Log::info("Successfully enriched and found emails for " . count($foundContactsWithEmail) . " people");
        return $foundContactsWithEmail;
    }
} 