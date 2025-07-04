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
    private const APOLLO_API_USAGE_STATS_URL = 'https://api.apollo.io/api/v1/usage_stats/api_usage_stats';
    private const DEFAULT_RATE_LIMIT_PER_MINUTE = 50; // Conservative limit for people search
    private const DEFAULT_MIN_REQUEST_INTERVAL = 2.0; // 2 seconds = 30 requests per minute (conservative)

    public function __construct()
    {
        $this->config = config('ch-lead-gen');
        
        // Add fallback to environment variables for API keys
        if (empty($this->config['apollo_api_key'])) {
            $this->config['apollo_api_key'] = env('APOLLO_API_KEY');
        }
        if (empty($this->config['apollo_master_api_key'])) {
            $this->config['apollo_master_api_key'] = env('APOLLO_MASTER_API_KEY');
        }
        
        $this->client = new Client([
            'timeout' => 30.0,
        ]);
    }

    /**
     * Get current rate limits from Apollo API or cache
     */
    private function getCurrentRateLimits($endpoint = 'people_search')
    {
        $cacheKey = 'apollo_rate_limits_' . $endpoint;
        $cachedLimits = Cache::get($cacheKey);
        
        // Return cached limits if they're recent (less than 5 minutes old)
        if ($cachedLimits && isset($cachedLimits['timestamp']) && (time() - $cachedLimits['timestamp']) < 300) {
            return $cachedLimits['limits'];
        }
        
        // Fetch fresh limits from API
        try {
            $masterApiKey = $this->config['apollo_master_api_key'] ?? $this->config['apollo_api_key'];
            
            $response = $this->client->post(self::APOLLO_API_USAGE_STATS_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $masterApiKey
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            $headers = $response->getHeaders();
            
            // Initialize limits with conservative fallback values for people search
            $limits = [
                'per_minute' => [
                    'limit' => 50, // Conservative limit for people search
                    'remaining' => 50,
                    'used' => 0
                ],
                'per_hour' => [
                    'limit' => 200, // Conservative limit for people search
                    'remaining' => 200,
                    'used' => 0
                ],
                'per_day' => [
                    'limit' => 600, // Conservative limit for people search
                    'remaining' => 600,
                    'used' => 0
                ]
            ];
            
            // If we have detailed quota data from the response, find the people search endpoint
            if (is_array($data) && !empty($data)) {
                $peopleSearchEndpoint = null;
                
                // Look for the people search endpoint in the data
                foreach ($data as $endpointKey => $usage) {
                    if (strpos($endpointKey, 'people') !== false || strpos($endpointKey, 'search') !== false) {
                        $peopleSearchEndpoint = $usage;
                        break;
                    }
                }
                
                // If we found the people search endpoint, use its specific limits
                if ($peopleSearchEndpoint) {
                    $safetyMargin = $this->config['apollo']['safety_margin'] ?? 0.6;
                    
                    if (isset($peopleSearchEndpoint['day']['limit'])) {
                        $adjustedDayLimit = (int)($peopleSearchEndpoint['day']['limit'] * $safetyMargin);
                        $limits['per_day']['limit'] = $adjustedDayLimit;
                        $limits['per_day']['used'] = $peopleSearchEndpoint['day']['consumed'] ?? 0;
                        $limits['per_day']['remaining'] = max(0, $adjustedDayLimit - $limits['per_day']['used']);
                    }
                    
                    if (isset($peopleSearchEndpoint['hour']['limit'])) {
                        $adjustedHourLimit = (int)($peopleSearchEndpoint['hour']['limit'] * $safetyMargin);
                        $limits['per_hour']['limit'] = $adjustedHourLimit;
                        $limits['per_hour']['used'] = $peopleSearchEndpoint['hour']['consumed'] ?? 0;
                        $limits['per_hour']['remaining'] = max(0, $adjustedHourLimit - $limits['per_hour']['used']);
                    }
                    
                    if (isset($peopleSearchEndpoint['minute']['limit'])) {
                        $adjustedMinuteLimit = (int)($peopleSearchEndpoint['minute']['limit'] * $safetyMargin);
                        $limits['per_minute']['limit'] = $adjustedMinuteLimit;
                        $limits['per_minute']['used'] = $peopleSearchEndpoint['minute']['consumed'] ?? 0;
                        $limits['per_minute']['remaining'] = max(0, $adjustedMinuteLimit - $limits['per_minute']['used']);
                    }
                    
                    Log::debug("Using people search specific rate limits: " . json_encode($limits));
                } else {
                    Log::debug("People search endpoint not found in API response, using conservative fallback limits");
                }
            }
            
            // Cache the limits for 5 minutes
            Cache::put($cacheKey, [
                'limits' => $limits,
                'timestamp' => time()
            ], 300);
            
            Log::debug("Updated Apollo rate limits for {$endpoint}: " . json_encode($limits));
            return $limits;
            
        } catch (\Exception $e) {
            Log::warning("Failed to fetch Apollo rate limits, using fallback limits: " . $e->getMessage());
            $fallbackLimits = $this->config['apollo']['fallback_limits'] ?? [
                'per_minute' => 50, // Conservative limit for people search
                'per_hour' => 200,
                'per_day' => 600
            ];
            
            return [
                'per_minute' => ['limit' => $fallbackLimits['per_minute'], 'remaining' => $fallbackLimits['per_minute'], 'used' => 0],
                'per_hour' => ['limit' => $fallbackLimits['per_hour'], 'remaining' => $fallbackLimits['per_hour'], 'used' => 0],
                'per_day' => ['limit' => $fallbackLimits['per_day'], 'remaining' => $fallbackLimits['per_day'], 'used' => 0]
            ];
        }
    }

    /**
     * Conservative rate limiting based on current Apollo API limits
     */
    private function rateLimit($endpoint = 'people_search')
    {
        $rateLimits = $this->getCurrentRateLimits($endpoint);
        
        // Check if we're approaching limits
        $minuteLimit = $rateLimits['per_minute'];
        $hourLimit = $rateLimits['per_hour'];
        $dayLimit = $rateLimits['per_day'];
        
        // Use a conservative approach - always maintain at least 3 seconds between requests
        $baseInterval = self::DEFAULT_MIN_REQUEST_INTERVAL;
        
        // If we're running low on minute limits, increase the interval significantly
        if ($minuteLimit['remaining'] <= 5) {
            $baseInterval = 10.0; // 10 seconds when very low on minute limits
        } elseif ($minuteLimit['remaining'] <= 10) {
            $baseInterval = 6.0; // 6 seconds when low on minute limits
        } elseif ($minuteLimit['remaining'] <= 20) {
            $baseInterval = 4.0; // 4 seconds when moderate on minute limits
        }
        
        // If we're running low on hour limits, increase the interval
        if ($hourLimit['remaining'] <= 20) {
            $baseInterval = max($baseInterval, 5.0);
        }
        
        // If we're running low on day limits, increase the interval
        if ($dayLimit['remaining'] <= 50) {
            $baseInterval = max($baseInterval, 8.0);
        }
        
        // Add some randomness to prevent thundering herd
        $jitter = (rand(0, 100) / 100) * 1.0; // 0-1 second of jitter
        $finalInterval = $baseInterval + $jitter;
        
        if ($this->lastRequestTime) {
            $timeSinceLastRequest = microtime(true) - $this->lastRequestTime;
            if ($timeSinceLastRequest < $finalInterval) {
                $sleepTime = $finalInterval - $timeSinceLastRequest;
                $sleepMicroseconds = (int)($sleepTime * 1000000);
                
                if ($sleepMicroseconds > 0) {
                    Log::debug("Conservative rate limiting: sleeping for {$sleepTime}s (minute: {$minuteLimit['remaining']} left, hour: {$hourLimit['remaining']} left, day: {$dayLimit['remaining']} left)");
                    usleep($sleepMicroseconds);
                }
            }
        }
        
        $this->lastRequestTime = microtime(true);
        
        // Log warning if approaching limits
        if ($minuteLimit['remaining'] <= 5) {
            Log::warning("Apollo API minute limit nearly reached: {$minuteLimit['remaining']} requests remaining");
        }
        if ($hourLimit['remaining'] <= 20) {
            Log::warning("Apollo API hour limit nearly reached: {$hourLimit['remaining']} requests remaining");
        }
        if ($dayLimit['remaining'] <= 50) {
            Log::warning("Apollo API day limit nearly reached: {$dayLimit['remaining']} requests remaining");
        }
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
        
        // Get current rate limits for comparison
        $rateLimits = $this->getCurrentRateLimits('people_search');
        $minuteLimit = $rateLimits['per_minute']['limit'];
        
        if ($newUsage > $minuteLimit) {
            Log::warning("Apollo API rate limit potentially exceeded: {$newUsage} requests in current minute for {$endpoint} (limit: {$minuteLimit})");
        }
        
        Log::debug("Apollo API usage: {$newUsage} requests in current minute for {$endpoint} (limit: {$minuteLimit})");
    }

    /**
     * Make API request with retry logic for 429 errors
     */
    private function makeApiRequestWithRetry(callable $requestCallback, $operationName = 'API request')
    {
        $maxRetries = 3;
        $baseDelay = 30; // Start with 30 seconds delay
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $requestCallback();
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();
                
                if ($statusCode === 429 && $attempt < $maxRetries) {
                    // Clear rate limit cache to get fresh information
                    Cache::forget('apollo_rate_limits');
                    
                    $delay = $baseDelay * pow(2, $attempt - 1); // Exponential backoff: 30s, 60s, 120s
                    Log::warning("Rate limit hit for {$operationName} (attempt {$attempt}/{$maxRetries}). Waiting {$delay} seconds before retry.");
                    sleep($delay);
                    continue;
                }
                
                // For other client errors or final retry attempt, re-throw
                Log::error("Error in {$operationName} (attempt {$attempt}/{$maxRetries}): " . $e->getMessage());
                throw $e;
            } catch (\Exception $e) {
                // For non-HTTP errors, re-throw immediately
                Log::error("Error in {$operationName} (attempt {$attempt}/{$maxRetries}): " . $e->getMessage());
                throw $e;
            }
        }
        
        // This should never be reached, but just in case
        throw new \Exception("Max retries exceeded for {$operationName}");
    }

    public function findPeopleForCompany($companyName)
    {
        Log::info("Searching people for company: {$companyName}");
        
        // Check if we can make API calls
        $canProceed = $this->canMakeApiCall();
        if (!$canProceed['can_proceed']) {
            Log::warning("Cannot make Apollo API call - rate limits reached: minute: {$canProceed['minute_remaining']}, hour: {$canProceed['hour_remaining']}, day: {$canProceed['day_remaining']}");
            throw new \Exception("Apollo API rate limits reached. Please wait before making more requests.");
        }
        
        return $this->makeApiRequestWithRetry(function() use ($companyName) {
            // Apply conservative rate limiting before making request
            $this->rateLimit('people_search');
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
        }, "Apollo People Search for {$companyName}");
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

            // Check if we can make API calls
            $canProceed = $this->canMakeApiCall();
            if (!$canProceed['can_proceed']) {
                Log::warning("Cannot make Apollo API call - rate limits reached: minute: {$canProceed['minute_remaining']}, hour: {$canProceed['hour_remaining']}, day: {$canProceed['day_remaining']}");
                throw new \Exception("Apollo API rate limits reached. Please wait before making more requests.");
            }
            
            $enrichedData = $this->makeApiRequestWithRetry(function() use ($detailsForApi) {
                // Apply conservative rate limiting before making request
                $this->rateLimit('bulk_enrich');
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
                return $data['matches'] ?? $data['people'] ?? [];
            }, "Bulk People Enrichment batch " . ($batchIndex + 1));

            if (!empty($enrichedData)) {
                foreach ($enrichedData as $personIndex => $enrichedPerson) {
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
        }

        Log::info("Successfully enriched and found emails for " . count($foundContactsWithEmail) . " people");
        return $foundContactsWithEmail;
    }

    /**
     * Get current rate limits (for external use)
     * 
     * @return array
     */
    public function getRateLimits()
    {
        return $this->getCurrentRateLimits('people_search');
    }

    /**
     * Get the current daily limit (adjusted with safety margin)
     * 
     * @return int
     */
    public function getCurrentDailyLimit()
    {
        $rateLimits = $this->getCurrentRateLimits('people_search');
        return $rateLimits['per_day']['limit'];
    }

    /**
     * Get the current daily usage
     * 
     * @return int
     */
    public function getCurrentDailyUsage()
    {
        $rateLimits = $this->getCurrentRateLimits('people_search');
        return $rateLimits['per_day']['used'];
    }

    /**
     * Get the remaining daily requests
     * 
     * @return int
     */
    public function getRemainingDailyRequests()
    {
        $rateLimits = $this->getCurrentRateLimits('people_search');
        return $rateLimits['per_day']['remaining'];
    }

    /**
     * Check if we can make API calls based on current limits
     * 
     * @return array
     */
    public function canMakeApiCall()
    {
        $rateLimits = $this->getCurrentRateLimits('people_search');
        
        $minuteRemaining = $rateLimits['per_minute']['remaining'];
        $hourRemaining = $rateLimits['per_hour']['remaining'];
        $dayRemaining = $rateLimits['per_day']['remaining'];
        
        return [
            'can_proceed' => $minuteRemaining > 0 && $hourRemaining > 0 && $dayRemaining > 0,
            'minute_remaining' => $minuteRemaining,
            'hour_remaining' => $hourRemaining,
            'day_remaining' => $dayRemaining,
            'limits' => $rateLimits
        ];
    }

    /**
     * Get Apollo API usage statistics and rate limits
     * 
     * @return array|null
     */
    public function getApiUsageStats()
    {
        // Check if master API key is configured
        $masterApiKey = $this->config['apollo_master_api_key'] ?? null;
        $regularApiKey = $this->config['apollo_api_key'] ?? null;
        
        if (!$masterApiKey) {
            Log::warning("Apollo master API key not configured. Usage stats endpoint requires a master API key.");
            return [
                'error' => 'master_key_required',
                'message' => 'Master API key required for usage stats. Please configure apollo_master_api_key in config.'
            ];
        }

        try {
            // Apply rate limiting before making request
            $this->rateLimit();
            
            $response = $this->client->post('https://api.apollo.io/api/v1/usage_stats/api_usage_stats', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $masterApiKey
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            // Log the full response for debugging
            Log::info("Apollo API usage stats raw response: " . json_encode($data));
            
            // The response is the usage stats object directly
            if (is_array($data) && !empty($data)) {
                Log::info("Successfully retrieved Apollo API usage stats");
                
                // Extract rate limit info from headers
                $headers = $response->getHeaders();
                $rateLimits = [
                    'per_minute' => [
                        'limit' => (int)($headers['x-rate-limit-minute'][0] ?? 0),
                        'used' => (int)($headers['x-minute-usage'][0] ?? 0),
                        'remaining' => (int)($headers['x-minute-requests-left'][0] ?? 0)
                    ],
                    'per_hour' => [
                        'limit' => (int)($headers['x-rate-limit-hourly'][0] ?? 0),
                        'used' => (int)($headers['x-hourly-usage'][0] ?? 0),
                        'remaining' => (int)($headers['x-hourly-requests-left'][0] ?? 0)
                    ],
                    'per_day' => [
                        'limit' => (int)($headers['x-rate-limit-24-hour'][0] ?? 0),
                        'used' => (int)($headers['x-24-hour-usage'][0] ?? 0),
                        'remaining' => (int)($headers['x-24-hour-requests-left'][0] ?? 0)
                    ]
                ];
                
                // Calculate totals and extract quota information
                $totalRequestsToday = 0;
                $endpointUsage = [];
                $quotaInfo = [];
                
                foreach ($data as $endpoint => $usage) {
                    if (isset($usage['day']['consumed'])) {
                        $totalRequestsToday += $usage['day']['consumed'];
                        
                        // Extract endpoint name for display
                        $endpointName = str_replace(['["', '"]', '", "'], ['', '', ' - '], $endpoint);
                        $endpointUsage[$endpointName] = $usage['day']['consumed'];
                        
                        // Extract quota information for each endpoint
                        $quotaInfo[$endpointName] = [
                            'day' => [
                                'limit' => $usage['day']['limit'] ?? 0,
                                'consumed' => $usage['day']['consumed'] ?? 0,
                                'remaining' => $usage['day']['left_over'] ?? 0,
                                'percentage_used' => $usage['day']['limit'] > 0 ? round(($usage['day']['consumed'] / $usage['day']['limit']) * 100, 1) : 0
                            ],
                            'hour' => [
                                'limit' => $usage['hour']['limit'] ?? 0,
                                'consumed' => $usage['hour']['consumed'] ?? 0,
                                'remaining' => $usage['hour']['left_over'] ?? 0,
                                'percentage_used' => $usage['hour']['limit'] > 0 ? round(($usage['hour']['consumed'] / $usage['hour']['limit']) * 100, 1) : 0
                            ],
                            'minute' => [
                                'limit' => $usage['minute']['limit'] ?? 0,
                                'consumed' => $usage['minute']['consumed'] ?? 0,
                                'remaining' => $usage['minute']['left_over'] ?? 0,
                                'percentage_used' => $usage['minute']['limit'] > 0 ? round(($usage['minute']['consumed'] / $usage['minute']['limit']) * 100, 1) : 0
                            ]
                        ];
                    }
                }
                
                // Calculate overall quota summary
                $overallQuota = [
                    'day' => [
                        'total_limit' => 0,
                        'total_consumed' => 0,
                        'total_remaining' => 0
                    ],
                    'hour' => [
                        'total_limit' => 0,
                        'total_consumed' => 0,
                        'total_remaining' => 0
                    ],
                    'minute' => [
                        'total_limit' => 0,
                        'total_consumed' => 0,
                        'total_remaining' => 0
                    ]
                ];
                
                foreach ($quotaInfo as $endpoint => $quota) {
                    $overallQuota['day']['total_limit'] += $quota['day']['limit'];
                    $overallQuota['day']['total_consumed'] += $quota['day']['consumed'];
                    $overallQuota['day']['total_remaining'] += $quota['day']['remaining'];
                    
                    $overallQuota['hour']['total_limit'] += $quota['hour']['limit'];
                    $overallQuota['hour']['total_consumed'] += $quota['hour']['consumed'];
                    $overallQuota['hour']['total_remaining'] += $quota['hour']['remaining'];
                    
                    $overallQuota['minute']['total_limit'] += $quota['minute']['limit'];
                    $overallQuota['minute']['total_consumed'] += $quota['minute']['consumed'];
                    $overallQuota['minute']['total_remaining'] += $quota['minute']['remaining'];
                }
                
                // Calculate overall percentages
                foreach (['day', 'hour', 'minute'] as $period) {
                    $overallQuota[$period]['percentage_used'] = $overallQuota[$period]['total_limit'] > 0 
                        ? round(($overallQuota[$period]['total_consumed'] / $overallQuota[$period]['total_limit']) * 100, 1) 
                        : 0;
                }
                
                return [
                    'total_requests_today' => $totalRequestsToday,
                    'total_requests_this_month' => $totalRequestsToday, // Apollo doesn't provide monthly totals
                    'total_requests_this_year' => $totalRequestsToday, // Apollo doesn't provide yearly totals
                    'plan_requests_per_month' => 600, // Default limit
                    'rate_limits' => $rateLimits,
                    'endpoint_usage' => $endpointUsage,
                    'quota_info' => $quotaInfo,
                    'overall_quota' => $overallQuota,
                    'raw_data' => $data // Keep raw data for debugging
                ];
            } else {
                Log::warning("Apollo API usage stats response is empty or invalid");
                return [
                    'error' => 'invalid_response',
                    'message' => 'Empty or invalid response from Apollo API'
                ];
            }

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $responseBody = $e->getResponse()->getBody()->getContents();
            
            if ($statusCode === 403) {
                Log::error("Apollo API usage stats: 403 Forbidden - Master API key may be invalid or insufficient permissions");
                return [
                    'error' => 'forbidden',
                    'message' => 'Access denied. Please check your master API key permissions.'
                ];
            } elseif ($statusCode === 401) {
                Log::error("Apollo API usage stats: 401 Unauthorized - Invalid master API key");
                return [
                    'error' => 'unauthorized',
                    'message' => 'Invalid master API key. Please check your configuration.'
                ];
            } else {
                Log::error("Apollo API usage stats HTTP error {$statusCode}: " . $responseBody);
                return [
                    'error' => 'http_error',
                    'message' => "HTTP error {$statusCode}: " . $e->getMessage()
                ];
            }
        } catch (\Exception $e) {
            Log::error("Error fetching Apollo API usage stats: " . $e->getMessage());
            return [
                'error' => 'general_error',
                'message' => 'Network or general error: ' . $e->getMessage()
            ];
        }
    }
} 