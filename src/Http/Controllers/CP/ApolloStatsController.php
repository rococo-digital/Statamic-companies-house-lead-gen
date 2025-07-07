<?php

namespace Rococo\ChLeadGen\Http\Controllers\CP;

use Illuminate\Http\Request;
use Statamic\Http\Controllers\Controller;
use Rococo\ChLeadGen\Services\ApolloService;

class ApolloStatsController extends Controller
{
    protected $apolloService;

    public function __construct(ApolloService $apolloService)
    {
        $this->apolloService = $apolloService;
    }

    public function index()
    {
        // Get Apollo API usage stats
        $apolloApiUsage = $this->apolloService->getApiUsageStats();
        
        // Get current rate limits and API call status
        $rateLimits = $this->apolloService->getRateLimits();
        
        // Get specific people search endpoint usage
        $peopleSearchUsage = $this->apolloService->getPeopleSearchEndpointUsage();

        // Use people search endpoint usage for canMakeApiCall if available
        if ($peopleSearchUsage) {
            $minuteRemaining = $peopleSearchUsage['minute']['remaining'] ?? 0;
            $hourRemaining = $peopleSearchUsage['hour']['remaining'] ?? 0;
            $dayRemaining = $peopleSearchUsage['day']['remaining'] ?? 0;
            $minuteUsed = $peopleSearchUsage['minute']['consumed'] ?? 0;
            $minuteLimit = $peopleSearchUsage['minute']['limit'] ?? 0;
            $hourUsed = $peopleSearchUsage['hour']['consumed'] ?? 0;
            $hourLimit = $peopleSearchUsage['hour']['limit'] ?? 0;
            $dayUsed = $peopleSearchUsage['day']['consumed'] ?? 0;
            $dayLimit = $peopleSearchUsage['day']['limit'] ?? 0;
            $minuteThreshold = 10;
            $hourThreshold = 50;
            $dayThreshold = 25;
            $canProceed = $minuteRemaining >= $minuteThreshold && $hourRemaining >= $hourThreshold && $dayRemaining >= $dayThreshold;
            $canMakeApiCall = [
                'can_proceed' => $canProceed,
                'minute_remaining' => $minuteRemaining,
                'hour_remaining' => $hourRemaining,
                'day_remaining' => $dayRemaining,
                'minute_used' => $minuteUsed,
                'minute_limit' => $minuteLimit,
                'hour_used' => $hourUsed,
                'hour_limit' => $hourLimit,
                'day_used' => $dayUsed,
                'day_limit' => $dayLimit,
                'minute_threshold' => $minuteThreshold,
                'hour_threshold' => $hourThreshold,
                'day_threshold' => $dayThreshold,
                'adjusted_limits' => $rateLimits,
                'raw_limits' => [
                    'per_minute' => [ 'used' => $minuteUsed, 'limit' => $minuteLimit, 'remaining' => $minuteRemaining ],
                    'per_hour' => [ 'used' => $hourUsed, 'limit' => $hourLimit, 'remaining' => $hourRemaining ],
                    'per_day' => [ 'used' => $dayUsed, 'limit' => $dayLimit, 'remaining' => $dayRemaining ],
                ],
            ];
        } else {
            $canMakeApiCall = $this->apolloService->canMakeApiCall();
        }
        
        // Get raw API limits for comparison
        $rawApiLimits = $this->apolloService->getRawApiLimits();
        
        // Get estimated API calls for rule execution
        $estimatedApiCalls = $this->apolloService->getEstimatedApiCallsForRule();
        
        // Get raw API response for debugging
        $rawApolloResponse = $this->apolloService->getRawApolloApiResponse();

        return view('ch-lead-gen::apollo-stats', [
            'apolloApiUsage' => $apolloApiUsage,
            'rateLimits' => $rateLimits,
            'canMakeApiCall' => $canMakeApiCall,
            'rawApiLimits' => $rawApiLimits,
            'estimatedApiCalls' => $estimatedApiCalls,
            'peopleSearchUsage' => $peopleSearchUsage,
            'rawApolloResponse' => $rawApolloResponse,
        ]);
    }
} 