# Companies House Lead Generation - Multi-Rule System

A flexible, multi-stage lead generation system for Statamic that searches Companies House for target companies, finds contacts using Apollo, and adds them to Instantly lead lists.

## 🚀 New Features (v2.0)

### Multi-Rule System
- **Flexible Rules**: Define multiple lead generation rules with different search criteria
- **Individual Scheduling**: Each rule can have its own schedule (daily/weekly/monthly)
- **Separate Lead Lists**: Each rule can target different Instantly lead lists
- **Statistics Tracking**: Detailed API usage and performance stats per rule
- **Force Run**: Override schedules and run rules manually

### Pre-Configured Rules
The system comes with two example rules:

1. **6 Month Old Companies** - Finds companies that are 6 months old
2. **Missing Confirmation Statements** - Finds companies 350+ days old missing confirmation statements

## 📋 Configuration

### Basic Setup
1. Configure API keys in `.env`:
```bash
COMPANIES_HOUSE_API_KEY=your_key_here
APOLLO_API_KEY=your_key_here
INSTANTLY_API_KEY=your_key_here
```

### Rule Configuration
Edit `config/ch-lead-gen.php` to customize rules:

```php
'rules' => [
    'custom_rule_key' => [
        'name' => 'My Custom Rule',
        'description' => 'Description of what this rule does',
        'enabled' => true,
        'search_parameters' => [
            'months_ago' => 6,                    // Company age in months
            'company_status' => 'active',         // active, dissolved, etc.
            'company_type' => 'ltd',              // ltd, plc, etc.
            'allowed_countries' => ['GB', 'US'],  // Country filter
            'max_results' => 200,                 // Max companies to process
            'check_confirmation_statement' => false, // Filter by missing statements
        ],
        'schedule' => [
            'enabled' => true,
            'frequency' => 'weekly',              // daily, weekly, monthly
            'time' => '09:00',                    // 24-hour format
            'day_of_week' => 1,                   // 1=Monday (for weekly)
            'day_of_month' => 1,                  // 1-31 (for monthly)
        ],
        'instantly' => [
            'lead_list_name' => 'My Lead List',
            'enable_enrichment' => false,         // Apollo handles enrichment
        ],
    ],
],
```

## 🎯 Usage

### Dashboard Interface
- **View Rules**: See all configured rules with status and statistics
- **Run Individual Rules**: Execute specific rules manually
- **Force Run**: Run disabled or off-schedule rules
- **Statistics Modal**: View detailed performance metrics per rule
- **API Usage Overview**: Monitor API consumption across all services

### Command Line Interface

#### List all rules:
```bash
php artisan ch-lead-gen:rules list
```

#### Show rule details:
```bash
php artisan ch-lead-gen:rules show six_month_companies
```

#### View rule statistics:
```bash
php artisan ch-lead-gen:rules stats confirmation_statement_missing
```

#### Test/run a specific rule:
```bash
php artisan ch-lead-gen:rules test six_month_companies
```

#### Run scheduled rules:
```bash
php artisan ch-lead-gen:run
```

#### Run a specific rule:
```bash
php artisan ch-lead-gen:run --rule=six_month_companies
```

## 🔄 Queue Management with Horizon

This addon includes Laravel Horizon for robust queue management to prevent timeout issues during lead generation.

### Setup

1. **Publish Horizon configuration:**
   ```bash
   php artisan vendor:publish --tag=ch-lead-gen-horizon
   ```

2. **Configure Redis in your `.env`:**
   ```env
   QUEUE_CONNECTION=redis
   REDIS_HOST=127.0.0.1
   REDIS_PASSWORD=null
   REDIS_PORT=6379
   ```

3. **Start Horizon:**
   ```bash
   php artisan horizon
   ```

### Production Setup

For production servers, set up Horizon to run automatically:

#### Using Supervisor (Recommended):
```bash
# Create supervisor config
sudo nano /etc/supervisor/conf.d/laravel-horizon.conf
```

Add this content:
```ini
[program:laravel-horizon]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/artisan horizon
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/horizon.log
stopwaitsecs=3600
```

Then:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-horizon:*
```

#### Using Cron:
```bash
# Add to crontab
* * * * * cd /path/to/your/project && php artisan horizon:terminate
* * * * * cd /path/to/your/project && php artisan horizon > /dev/null 2>&1 &
```

### Monitoring

- **Horizon Dashboard**: Access via `/cp/ch-lead-gen/horizon` in the Statamic control panel
- **Queue Status**: Monitor job processing, failures, and performance
- **Real-time Metrics**: View throughput, wait times, and job counts

### Benefits

- **No Timeouts**: Jobs run in background, preventing web request timeouts
- **Better Performance**: Redis-based queues are faster than database
- **Automatic Retries**: Failed jobs are automatically retried
- **Monitoring**: Built-in dashboard for queue management
- **Scalability**: Can handle multiple concurrent jobs

#### Force run a rule:
```bash
php artisan ch-lead-gen:run --rule=six_month_companies --force
```

## 📊 Statistics & Monitoring

### Tracked Metrics
- **Execution Statistics**: Total runs, success rate, execution time
- **Company Metrics**: Companies found, contacts discovered, contacts added
- **API Usage**: Requests per service (Companies House, Apollo, Instantly)
- **Performance History**: Recent executions with detailed results

### API Usage Tracking
The system tracks API usage per rule and service:
- **Companies House**: Search, filing history, company profile requests
- **Apollo**: People search, bulk enrichment requests
- **Instantly**: Contact additions, lead list operations

## 🔄 Scheduling

### Cron Setup
Add to your crontab to enable automatic scheduling:
```bash
* * * * * cd /path/to/your/project && php artisan schedule:run >> /dev/null 2>&1
```

### Schedule Types
- **Daily**: Runs every day at specified time
- **Weekly**: Runs on specific day of week at specified time
- **Monthly**: Runs on specific day of month at specified time
- **Manual**: No automatic scheduling, manual execution only

## 🛠 Migration from Legacy System

If you're upgrading from the previous version:

1. **Backup**: Your existing configuration is preserved as "legacy" settings
2. **Rules**: Add rule configuration to your config file
3. **Testing**: Use `ch-lead-gen:rules test` to verify rules work correctly
4. **Gradual Migration**: Rules and legacy system can coexist

## 🔧 Troubleshooting

### No Rules Configured
If no rules are configured, the system falls back to legacy behavior using your existing search parameters.

### Rule Not Running
- Check if rule is enabled: `ch-lead-gen:rules show <rule_key>`
- Verify schedule configuration
- Use force run to test: `ch-lead-gen:rules test <rule_key>`

### API Rate Limits
- Monitor API usage in dashboard
- Adjust rule schedules to spread load
- Check service-specific rate limits

### Statistics Not Showing
- Ensure statistics are enabled in config: `'stats' => ['enabled' => true]`
- Cache-based statistics may take time to populate
- Check log files for any errors

## 📝 Example Rule Configurations

### High-Volume Daily Rule
```php
'daily_new_companies' => [
    'name' => 'Daily New Companies',
    'enabled' => true,
    'search_parameters' => [
        'months_ago' => 1,
        'max_results' => 1000,
        'check_confirmation_statement' => false,
    ],
    'schedule' => [
        'enabled' => true,
        'frequency' => 'daily',
        'time' => '08:00',
    ],
    'instantly' => [
        'lead_list_name' => 'Daily New Companies',
    ],
],
```

### Weekly Compliance Check
```php
'weekly_compliance_check' => [
    'name' => 'Weekly Compliance Check',
    'enabled' => true,
    'search_parameters' => [
        'months_ago' => 12,
        'max_results' => 500,
        'check_confirmation_statement' => true,
    ],
    'schedule' => [
        'enabled' => true,
        'frequency' => 'weekly',
        'time' => '09:00',
        'day_of_week' => 1, // Monday
    ],
    'instantly' => [
        'lead_list_name' => 'Compliance Issues',
    ],
],
```

## 🔍 API Reference

### Rule Manager Service
- `getAllRules()`: Get all configured rules
- `getEnabledRules()`: Get only enabled rules
- `executeRule($ruleKey, $forceRun)`: Execute specific rule
- `getRulesDueToRun()`: Get rules scheduled to run now

### Statistics Service
- `getRuleSummaryStats($ruleKey)`: Get rule performance summary
- `getRuleApiUsage($ruleKey, $days)`: Get API usage for rule
- `getOverallApiUsage($days)`: Get overall API usage
- `getAllRulesStats()`: Get statistics for all rules

## 🎯 Best Practices

1. **Start Small**: Begin with one or two rules, monitor performance
2. **Stagger Schedules**: Avoid running multiple rules simultaneously
3. **Monitor API Usage**: Keep track of rate limits across all services
4. **Test Thoroughly**: Use `ch-lead-gen:rules test` before enabling rules
5. **Regular Monitoring**: Check dashboard statistics regularly
6. **Backup Configuration**: Keep your rule configurations in version control

## 📞 Support

For issues or questions:
1. Check the dashboard activity logs
2. Use `ch-lead-gen:rules list` to verify configuration
3. Review Laravel logs for detailed error information
4. Test individual components with force run options
