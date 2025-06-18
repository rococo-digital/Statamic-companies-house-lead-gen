<?php

namespace Rococo\ChLeadGen\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Rococo\ChLeadGen\Services\CompaniesHouseService;
use Rococo\ChLeadGen\Services\ApolloService;
use Rococo\ChLeadGen\Services\InstantlyService;
use Illuminate\Support\Facades\Log;

class RunLeadGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(CompaniesHouseService $companiesHouse, ApolloService $apollo, InstantlyService $instantly)
    {
        try {
            Log::info('=== Starting lead generation process from dashboard ===');
            
            // Get config
            $config = config('ch-lead-gen');
            Log::info('Using config:', $config);

            // Check if API key is configured
            if (empty($config['companies_house_api_key'])) {
                Log::error('Companies House API key not configured');
                return;
            }

            // Build search parameters from config - use a date range instead of single date
            $fromDate = now()->subMonths($config['search']['months_ago'] + 1)->format('Y-m-d');
            $toDate = now()->subMonths($config['search']['months_ago'])->format('Y-m-d');
            
            $searchParams = [
                'incorporated_from' => $fromDate,
                'incorporated_to' => $toDate,
                'company_status' => $config['search']['company_status'],
                'company_type' => $config['search']['company_type'],
            ];
            
            Log::info('Search parameters with date range:', $searchParams);

            // Get companies from Companies House with enhanced pagination (up to 500 companies)
            $companies = $companiesHouse->searchCompanies($searchParams, 500);

            if (!$companies) {
                Log::error('Failed to fetch companies from Companies House');
                return;
            }

            Log::info('Raw API response structure:', ['keys' => array_keys($companies)]);

            if (!isset($companies['items'])) {
                Log::warning('No items found in Companies House response', ['response' => $companies]);
                return;
            }

            $companiesCount = count($companies['items']);
            Log::info("Found {$companiesCount} companies from API");

            if ($companiesCount === 0) {
                Log::info('No companies found matching criteria');
                return;
            }

            // Log details about the first few companies to see their structure
            for ($i = 0; $i < min(3, $companiesCount); $i++) {
                $company = $companies['items'][$i];
                Log::info("Sample company {$i}:", [
                    'company_number' => $company['company_number'] ?? 'N/A',
                    'title' => $company['title'] ?? 'N/A',
                    'company_status' => $company['company_status'] ?? 'N/A',
                    'address' => $company['address'] ?? 'N/A',
                    'date_of_creation' => $company['date_of_creation'] ?? 'N/A'
                ]);
            }

            // TESTING: Skip country filtering for now and limit to more companies for better testing
            $maxCompaniesToProcess = 10; // Increased from 2 to 10 for more comprehensive testing
            $companiesToProcess = array_slice($companies['items'], 0, $maxCompaniesToProcess);
            
            Log::info("=== TESTING MODE: Processing {$maxCompaniesToProcess} companies with enhanced pagination ===");

            $allContacts = [];
            $processedCount = 0;

            foreach ($companiesToProcess as $company) {
                Log::info('Processing company:', [
                    'company_number' => $company['company_number'],
                    'company_name' => $company['title'] ?? 'Unknown',
                    'company_status' => $company['company_status'] ?? 'Unknown'
                ]);

                // Get detailed company information
                $profile = $companiesHouse->getCompanyProfile($company['company_number']);
                
                if (!$profile) {
                    Log::warning('Failed to fetch profile for company: ' . $company['company_number']);
                    continue;
                }

                $companyName = $profile['company_name'] ?? $company['title'] ?? 'Unknown';
                
                Log::info('Company profile retrieved:', [
                    'company_number' => $company['company_number'],
                    'company_name' => $companyName,
                    'date_of_creation' => $profile['date_of_creation'] ?? 'Unknown'
                ]);

                // Find people for this company using Apollo
                if (!empty($config['apollo_api_key'])) {
                    try {
                        Log::info("Searching for people at {$companyName}...");
                        $people = $apollo->findPeopleForCompany($companyName);
                        
                        if (!empty($people)) {
                            Log::info("Found " . count($people) . " people for {$companyName}");
                            
                            // Enrich people details to get email addresses
                            $contacts = $apollo->enrichPeopleDetails($people);
                            
                            if (!empty($contacts)) {
                                $allContacts = array_merge($allContacts, $contacts);
                                Log::info("Added " . count($contacts) . " contacts with emails for {$companyName}");
                            }
                        } else {
                            Log::info("No people found for {$companyName}");
                        }
                    } catch (\Exception $e) {
                        Log::error("Error processing Apollo data for {$companyName}: " . $e->getMessage());
                        // Continue processing other companies even if one fails
                    }
                }

                $processedCount++;
                
                // Add delay between companies to be extra safe with rate limiting
                Log::info("Waiting 3 seconds before processing next company...");
                sleep(3);
            }

            Log::info("=== Lead generation process completed successfully ===");
            Log::info("Processed {$processedCount} companies, found " . count($allContacts) . " total contacts");

            // Add contacts to Instantly if we have any
            if (!empty($allContacts) && !empty($config['instantly_api_key'])) {
                try {
                    Log::info("Adding " . count($allContacts) . " contacts to Instantly...");
                    $instantly->addContacts($allContacts);
                    Log::info("Successfully added contacts to Instantly");
                } catch (\Exception $e) {
                    Log::error("Error adding contacts to Instantly: " . $e->getMessage());
                }
            }

            Log::info('=== Lead generation job finished successfully ===');

        } catch (\Exception $e) {
            Log::error('=== Error in lead generation process ===');
            Log::error('Error message: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }
} 