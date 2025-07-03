<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Keys
    |--------------------------------------------------------------------------
    |
    | Your API keys for Companies House, Apollo, and Instantly.
    |
    */
    'companies_house_api_key' => env('COMPANIES_HOUSE_API_KEY', ''),
    'apollo_api_key' => env('APOLLO_API_KEY', ''),
    'instantly_api_key' => env('INSTANTLY_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Values for New Rules
    |--------------------------------------------------------------------------
    |
    | Default values used when creating new rules in the control panel.
    |
    */
    'defaults' => [
        'days_ago' => 180,
        'company_status' => 'active',
        'company_type' => 'ltd',
        'allowed_countries' => ['GB'],
        'max_results' => 50,
        'check_confirmation_statement' => false,
        'schedule_enabled' => true,
        'frequency' => 'daily',
        'time' => '09:00',
        'webhook_enabled' => false,
        'instantly_enabled' => false,
        'enable_enrichment' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead Generation Rules
    |--------------------------------------------------------------------------
    |
    | Define multiple rules for lead generation. Each rule can have different
    | search parameters, schedules, and target lead lists.
    |
    */
    'rules' => [
        'six_month_companies' => [
            'name' => '6 Month Old Companies',
            'description' => 'Find companies that are 6 months old',
            'enabled' => true,
            'search_parameters' => [
                'months_ago' => 6,
                'company_status' => 'active',
                'company_type' => 'ltd',
                'allowed_countries' => ['GB', 'US'],
                'max_results' => 200,
                'check_confirmation_statement' => false, // Just find companies, don't check statements
            ],
            'schedule' => [
                'enabled' => true,
                'frequency' => 'weekly', // daily, weekly, monthly
                'time' => '09:00',
                'day_of_week' => 1, // 1=Monday, 7=Sunday (for weekly)
                'day_of_month' => 1, // 1-31 (for monthly)
            ],
            'instantly' => [
                'lead_list_name' => 'CH - 6 Month Companies',
                'enable_enrichment' => false,
            ],
        ],
        'confirmation_statement_missing' => [
            'name' => 'Companies Missing Confirmation Statements',
            'description' => 'Find companies 350+ days old missing confirmation statements',
            'enabled' => true,
            'search_parameters' => [
                'months_ago' => 11, // Search around 11 months ago
                'days_ago_min' => 350, // Minimum 350 days old
                'company_status' => 'active',
                'company_type' => 'ltd',
                'allowed_countries' => ['GB', 'US'],
                'max_results' => 500,
                'check_confirmation_statement' => true, // This is the current logic
            ],
            'schedule' => [
                'enabled' => true,
                'frequency' => 'daily',
                'time' => '10:00',
                'day_of_week' => null,
                'day_of_month' => null,
            ],
            'instantly' => [
                'lead_list_name' => 'CH - Missing Confirmation Statements',
                'enable_enrichment' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Schedule Settings (Legacy)
    |--------------------------------------------------------------------------
    |
    | These settings are kept for backward compatibility but individual
    | rule schedules take precedence.
    |
    */
    'schedule' => [
        'enabled' => env('CH_LEAD_GEN_SCHEDULE_ENABLED') !== null 
            ? filter_var(env('CH_LEAD_GEN_SCHEDULE_ENABLED'), FILTER_VALIDATE_BOOLEAN)
            : env('APP_ENV', 'production') === 'production',
        'frequency' => env('CH_LEAD_GEN_SCHEDULE_FREQUENCY', 'daily'), // daily, weekly, monthly
        'time' => env('CH_LEAD_GEN_SCHEDULE_TIME', '09:00'), // 24-hour format
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Search Parameters (Deprecated)
    |--------------------------------------------------------------------------
    |
    | These are kept for backward compatibility but should be migrated
    | to the new rules system above.
    |
    */
    'search' => [
        'months_ago' => 11,
        'company_status' => 'active',
        'company_type' => 'ltd',
        'allowed_countries' => ['GB'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Instantly Settings (Deprecated)
    |--------------------------------------------------------------------------
    |
    | These are kept for backward compatibility but each rule now has
    | its own Instantly configuration.
    |
    */
    'instantly' => [
        'lead_list_name' => env('CH_LEAD_GEN_INSTANTLY_LEAD_LIST_NAME', 'CH Lead Generation'),
        'enable_enrichment' => false, // Enrichment is already done via Apollo
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistics & Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure how API usage statistics are tracked and stored.
    |
    */
    'stats' => [
        'enabled' => true,
        'retention_days' => 90, // How long to keep detailed stats
        'track_api_usage' => true,
        'track_rule_performance' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging settings for the lead generation process.
    |
    */
    'logging' => [
        'enabled' => true,
        'retention_days' => 30,
    ],
];