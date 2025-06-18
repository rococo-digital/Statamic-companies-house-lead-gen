<?php

namespace Rococo\ChLeadGen\Commands;

use Illuminate\Console\Command;
use Rococo\ChLeadGen\Services\CompaniesHouseService;
use Rococo\ChLeadGen\Services\ApolloService;
use Rococo\ChLeadGen\Services\InstantlyService;
use Illuminate\Support\Facades\Log;

class RunLeadGeneration extends Command
{
    protected $signature = 'ch-lead-gen:run';
    protected $description = 'Run the Companies House lead generation process';

    private $companiesHouseService;
    private $apolloService;
    private $instantlyService;

    public function __construct(
        CompaniesHouseService $companiesHouseService,
        ApolloService $apolloService,
        InstantlyService $instantlyService
    ) {
        parent::__construct();
        $this->companiesHouseService = $companiesHouseService;
        $this->apolloService = $apolloService;
        $this->instantlyService = $instantlyService;
    }

    public function handle()
    {
        $this->info('Starting Companies House lead generation process...');

        try {
            // Step 1: Find recent companies
            $this->info('Finding recent companies...');
            $companies = $this->companiesHouseService->findRecentCompanies();
            $this->info('Found ' . count($companies) . ' companies');

            // Step 2: Check for missing confirmation statements
            $this->info('Checking for missing confirmation statements...');
            $companiesMissingStatements = [];
            foreach ($companies as $company) {
                $result = $this->companiesHouseService->checkConfirmationStatement($company);
                if ($result['missing']) {
                    $companiesMissingStatements[] = $result['company'];
                }
            }
            $this->info('Found ' . count($companiesMissingStatements) . ' companies missing confirmation statements');

            // Step 3: Find people for each company using Apollo
            $this->info('Finding people for companies...');
            $allPeople = [];
            foreach ($companiesMissingStatements as $company) {
                $people = $this->apolloService->findPeopleForCompany($company['company_name']);
                $allPeople = array_merge($allPeople, $people);
            }
            $this->info('Found ' . count($allPeople) . ' potential people');

            // Step 4: Enrich people details to get email addresses
            $this->info('Enriching people details...');
            $contactsWithEmail = $this->apolloService->enrichPeopleDetails($allPeople);
            $this->info('Found ' . count($contactsWithEmail) . ' contacts with email addresses');

            // Step 5: Create lead list in Instantly
            $this->info('Creating lead list in Instantly...');
            $added = $this->instantlyService->createLeadList($contactsWithEmail);
            $this->info("Added {$added} contacts to Instantly");

            $this->info('Lead generation process completed successfully!');

        } catch (\Exception $e) {
            $this->error('Error in lead generation process: ' . $e->getMessage());
            Log::error('Lead generation process failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
} 