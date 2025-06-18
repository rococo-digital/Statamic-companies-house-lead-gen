<?php

namespace Rococo\ChLeadGen\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class CompaniesHouseService
{
    protected $client;
    protected $config;
    protected $lastRequestTime;

    public function __construct()
    {
        $this->config = config('ch-lead-gen');
        $this->client = new Client([
            'base_uri' => 'https://api.company-information.service.gov.uk',
            'auth' => [$this->config['companies_house_api_key'], ''],
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
    }

    public function searchCompanies($params = [], $maxResults = 200)
    {
        try {
            // Use advanced-search endpoint if date parameters are provided
            if (isset($params['incorporated_from']) || isset($params['incorporated_to'])) {
                $endpoint = '/advanced-search/companies';
                $baseQuery = [
                    'size' => 100, // Maximum per request for advanced search
                    'start_index' => 0,
                ];
            } else {
                $endpoint = '/search/companies';
                $baseQuery = [
                    'q' => '*',
                    'items_per_page' => 100, // Maximum per request for basic search
                    'start_index' => 0,
                ];
            }

            $allCompanies = [];
            $currentPage = 0;
            $pageSize = 100;
            $maxPages = ceil($maxResults / $pageSize);

            Log::info("Using Companies House endpoint: {$endpoint} to retrieve up to {$maxResults} results");

            while (count($allCompanies) < $maxResults && $currentPage < $maxPages) {
                $startIndex = $currentPage * $pageSize;
                $query = array_merge($baseQuery, $params, [
                    $endpoint === '/advanced-search/companies' ? 'start_index' : 'start_index' => $startIndex
                ]);

                Log::debug("Fetching page " . ($currentPage + 1) . " (start_index: {$startIndex})");

                // Apply rate limiting
                if ($currentPage > 0) {
                    $this->rateLimit();
                }

                $response = $this->client->get($endpoint, ['query' => $query]);
                $data = json_decode($response->getBody(), true);

                if (!isset($data['items']) || empty($data['items'])) {
                    Log::info("No more companies found on page " . ($currentPage + 1));
                    break;
                }

                $pageItems = $data['items'];
                $allCompanies = array_merge($allCompanies, $pageItems);

                Log::debug("Page " . ($currentPage + 1) . ": Retrieved " . count($pageItems) . " companies. Total: " . count($allCompanies));

                // Check if we have all available results
                $totalCount = $data['total_results'] ?? $data['total_count'] ?? 0;
                if (count($allCompanies) >= $totalCount || count($pageItems) < $pageSize) {
                    Log::info("Retrieved all available results: " . count($allCompanies) . "/{$totalCount}");
                    break;
                }

                $currentPage++;
            }

            // Prepare the response in the expected format
            $result = [
                'total_results' => count($allCompanies),
                'items' => array_slice($allCompanies, 0, $maxResults), // Ensure we don't exceed maxResults
                'page_number' => 1,
                'items_per_page' => count($allCompanies),
                'start_index' => 0,
                'kind' => 'search#companies'
            ];

            Log::info("Final result: " . count($result['items']) . " companies retrieved");

            return $result;
        } catch (\Exception $e) {
            Log::error('Companies House API Error: ' . $e->getMessage());
            return null;
        }
    }

    public function getCompanyProfile($companyNumber)
    {
        try {
            $response = $this->client->get("/company/{$companyNumber}");
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error('Companies House API Error: ' . $e->getMessage());
            return null;
        }
    }

    private function rateLimit()
    {
        if ($this->lastRequestTime) {
            $timeSinceLastRequest = microtime(true) - $this->lastRequestTime;
            if ($timeSinceLastRequest < 0.5) {
                $sleepMicroseconds = (int)(($this->lastRequestTime + 0.5 - microtime(true)) * 1000000);
                if ($sleepMicroseconds > 0) {
                    usleep($sleepMicroseconds);
                }
            }
        }
        $this->lastRequestTime = microtime(true);
    }

    public function findRecentCompanies()
    {
        $elevenMonthsAgo = now()->subMonths($this->config['search']['months_ago']);
        
        try {
            $this->rateLimit();
            $response = $this->client->get('/advanced-search/companies', [
                'query' => [
                    'incorporated_from' => $elevenMonthsAgo->format('Y-m-d'),
                    'incorporated_to' => $elevenMonthsAgo->format('Y-m-d'),
                    'size' => '100',
                    'company_status' => $this->config['search']['company_status'],
                    'company_type' => $this->config['search']['company_type']
                ]
            ]);

            $companiesData = json_decode($response->getBody(), true);
            
            if (!isset($companiesData['items']) || empty($companiesData['items'])) {
                Log::info("No companies found for incorporation date " . $elevenMonthsAgo->format('Y-m-d'));
                return [];
            }

            $filteredCompanies = [];
            foreach ($companiesData['items'] as $company) {
                $country = $company['registered_office_address']['country'] ?? 'Unknown';
                
                // Normalize country names to match config
                $normalizedCountry = $this->normalizeCountry($country);
                
                if (in_array($normalizedCountry, $this->config['search']['allowed_countries'])) {
                    $filteredCompanies[] = $company;
                } else {
                    Log::info("Skipping company '{$company['company_name']}' due to country: {$country} (normalized: {$normalizedCountry})");
                }
            }

            return $filteredCompanies;

        } catch (\Exception $e) {
            Log::error("Error in Companies House search: " . $e->getMessage());
            throw $e;
        }
    }

    public function checkConfirmationStatement($company, $maxPages = 3)
    {
        try {
            $this->rateLimit();
            
            $allFilings = [];
            $currentPage = 0;
            $hasMorePages = true;
            
            // Fetch multiple pages to get more comprehensive results
            while ($hasMorePages && $currentPage < $maxPages) {
                $startIndex = $currentPage * 100;
                
                Log::debug("Fetching filing history page " . ($currentPage + 1) . " for company {$company['company_number']}");
                
                $response = $this->client->get("/company/{$company['company_number']}/filing-history", [
                    'query' => [
                        'items_per_page' => 100, // Maximum allowed by Companies House
                        'start_index' => $startIndex,
                        'category' => 'confirmation-statement'
                    ]
                ]);

                $filings = json_decode($response->getBody(), true);
                
                if (!isset($filings['items'])) {
                    Log::warning("No filing items found in response for {$company['company_number']}");
                    break;
                }
                
                $pageItems = $filings['items'];
                $allFilings = array_merge($allFilings, $pageItems);
                
                // Check if there are more pages
                $totalResults = $filings['total_count'] ?? 0;
                $itemsRetrieved = ($currentPage + 1) * 100;
                $hasMorePages = $itemsRetrieved < $totalResults && count($pageItems) === 100;
                
                Log::debug("Page " . ($currentPage + 1) . ": Retrieved " . count($pageItems) . " items. Total so far: " . count($allFilings) . "/{$totalResults}");
                
                $currentPage++;
                
                // Add rate limiting between pages
                if ($hasMorePages && $currentPage < $maxPages) {
                    $this->rateLimit();
                }
            }
            
            Log::info("Retrieved " . count($allFilings) . " total confirmation statement filings for {$company['company_number']}");
            
            // Analyze the filings for missing confirmation statements
            $result = $this->analyzeConfirmationStatements($allFilings, $company);
            
            return $result;

        } catch (\Exception $e) {
            Log::error("Error checking filing history for {$company['company_number']}: " . $e->getMessage());
            throw $e;
        }
    }

    private function analyzeConfirmationStatements($filings, $company)
    {
        if (empty($filings)) {
            return [
                'missing' => true,
                'company' => $company,
                'reason' => 'No confirmation statements found',
                'total_filings' => 0
            ];
        }

        // Sort filings by date (most recent first)
        usort($filings, function($a, $b) {
            $dateA = strtotime($a['date'] ?? '1900-01-01');
            $dateB = strtotime($b['date'] ?? '1900-01-01');
            return $dateB - $dateA;
        });

        $latestFiling = $filings[0];
        $latestDate = $latestFiling['date'] ?? null;
        
        if (!$latestDate) {
            return [
                'missing' => true,
                'company' => $company,
                'reason' => 'No valid filing dates found',
                'total_filings' => count($filings)
            ];
        }

        // Check if the latest confirmation statement is overdue
        // Companies must file within 14 days of their confirmation date
        $companyIncorporationDate = $company['date_of_creation'] ?? null;
        $latestFilingDate = new \DateTime($latestDate);
        $now = new \DateTime();
        
        // Calculate expected confirmation date (anniversary of incorporation)
        if ($companyIncorporationDate) {
            $incorporationDate = new \DateTime($companyIncorporationDate);
            $thisYearConfirmationDate = new \DateTime($incorporationDate->format('Y-m-d'));
            $thisYearConfirmationDate->setDate($now->format('Y'), $incorporationDate->format('m'), $incorporationDate->format('d'));
            
            // If this year's confirmation date has passed, check if filing is overdue
            if ($thisYearConfirmationDate < $now) {
                $overdueDate = clone $thisYearConfirmationDate;
                $overdueDate->add(new \DateInterval('P14D')); // Add 14 days grace period
                
                if ($latestFilingDate < $overdueDate && $now > $overdueDate) {
                    return [
                        'missing' => true,
                        'company' => $company,
                        'reason' => 'Confirmation statement overdue',
                        'latest_filing_date' => $latestDate,
                        'expected_by' => $overdueDate->format('Y-m-d'),
                        'days_overdue' => $now->diff($overdueDate)->days,
                        'total_filings' => count($filings)
                    ];
                }
            }
        }

        return [
            'missing' => false,
            'company' => $company,
            'latest_filing_date' => $latestDate,
            'total_filings' => count($filings)
        ];
    }

    private function normalizeCountry($country)
    {
        $country = strtolower(trim($country));
        
        // Map various UK country formats to 'GB'
        $ukVariants = [
            'united kingdom',
            'england',
            'scotland',
            'wales',
            'northern ireland',
            'great britain',
            'uk'
        ];
        
        if (in_array($country, $ukVariants)) {
            return 'GB';
        }
        
        // Map US variants
        $usVariants = [
            'united states',
            'united states of america',
            'usa',
            'us'
        ];
        
        if (in_array($country, $usVariants)) {
            return 'US';
        }
        
        // Return original if no mapping found
        return strtoupper($country);
    }
} 