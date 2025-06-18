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
    | Schedule Settings
    |--------------------------------------------------------------------------
    |
    | Configure how often the lead generation process should run.
    | Scheduling is enabled by default only in production environments.
    | You can override this by setting CH_LEAD_GEN_SCHEDULE_ENABLED in your .env file.
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
    | Search Parameters
    |--------------------------------------------------------------------------
    |
    | Configure the search parameters for finding companies.
    |
    */
    'search' => [
        'months_ago' => 11,
        'company_status' => 'active',
        'company_type' => 'ltd',
        'allowed_countries' => ['GB', 'US'],
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