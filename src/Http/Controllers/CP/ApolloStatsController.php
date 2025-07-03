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
        $canMakeApiCall = $this->apolloService->canMakeApiCall();

        return view('ch-lead-gen::apollo-stats', [
            'apolloApiUsage' => $apolloApiUsage,
            'rateLimits' => $rateLimits,
            'canMakeApiCall' => $canMakeApiCall,
        ]);
    }
} 