# Rate Limit Detection and Graceful Job Termination

This document explains the new rate limit detection feature that allows jobs to gracefully stop when Apollo API rate limits are approaching, ensuring that partial results are saved rather than losing all progress.

## Overview

The system now monitors Apollo API rate limits during job execution and can automatically stop processing when limits are approaching. This prevents jobs from failing completely due to rate limits and ensures that any contacts found so far are processed and saved.

## How It Works

### 1. Rate Limit Monitoring

During job execution, the system checks rate limits before processing each company:

- **Hourly Limit**: Stops when less than 10 requests per hour remaining (configurable)
- **Minute Limit**: Stops when less than 3 requests per minute remaining (configurable)

### 2. Graceful Termination

When rate limits are approaching:

1. The job stops processing new companies
2. Any contacts found so far are processed and added to Instantly
3. The job is marked as `completed_partial` instead of `failed`
4. Partial results are logged and displayed in the dashboard

### 3. Configuration

Rate limit thresholds can be configured in `config/ch-lead-gen.php`:

```php
'apollo' => [
    'rate_limit_thresholds' => [
        'hourly_stop_threshold' => 10,    // Stop when < 10 hourly requests remaining
        'minute_stop_threshold' => 3,     // Stop when < 3 minute requests remaining
        'enable_graceful_stop' => true,   // Enable/disable this feature
    ],
],
```

## Job Status Types

The system now supports these job statuses:

- `running`: Job is currently executing
- `completed`: Job completed successfully with all companies processed
- `completed_partial`: Job stopped early due to rate limits but saved partial results
- `failed`: Job failed due to an error
- `cancelled`: Job was manually cancelled

## Dashboard Display

The dashboard now shows different status cards:

- **Blue card**: Running job with progress
- **Yellow card**: Job completed with partial results due to rate limits
- **Green card**: Job completed successfully
- **Red card**: Job failed

## Testing

Use the test command to verify rate limit detection:

```bash
# Test rate limit detection
php artisan ch-lead-gen:test-rate-limits

# Test with simulation of low limits
php artisan ch-lead-gen:test-rate-limits --simulate-low-limits
```

## Benefits

1. **No Lost Progress**: Contacts found before rate limits are reached are still processed
2. **Better User Experience**: Clear indication when jobs stop due to rate limits
3. **Configurable**: Thresholds can be adjusted based on your Apollo plan
4. **Transparent**: Detailed logging and dashboard display of what happened

## Logging

The system logs rate limit events with detailed information:

```
[WARNING] Hourly rate limit approaching - stopping processing
{
    "hourly_remaining": 8,
    "minute_remaining": 2,
    "hourly_threshold": 10,
    "minute_threshold": 3,
    "message": "Rate limits approaching: hourly remaining 8, minute remaining 2"
}
```

## Troubleshooting

### Job stops too early
- Increase the thresholds in the configuration
- Check your Apollo API plan limits

### Job doesn't stop when expected
- Verify `enable_graceful_stop` is set to `true`
- Check Apollo API key permissions
- Review logs for any errors in rate limit checking

### Partial results not showing
- Check that the job status is `completed_partial`
- Verify the dashboard is refreshing to show the new status
- Review job tracking logs for any issues 