# Error Reports Performance Optimization Guide

## Problem
The `error_reports` table was causing MySQL memory allocation errors:
```
SQLSTATE[HY001]: Memory allocation error: 1038 Out of sort memory, consider increasing server sort buffer size
```

This occurs when MySQL runs out of sort memory while trying to order large datasets without proper indexes.

## Root Causes
1. **No indexes** on frequently queried columns (`project_id`, `exception_class`)
2. **SELECT * queries** loading unnecessary columns into memory
3. **Large datasets** being sorted without optimization
4. **Redundant WHERE conditions** in generated queries

## Solutions Implemented

### 1. Database Indexes Migration
**File**: `database/migrations/2025_05_29_090724_add_indexes_to_error_reports_table.php`

**Indexes Added**:
- `project_id` - Fast filtering by project
- `exception_class` - Efficient sorting by exception type
- `[project_id, exception_class]` - Composite index for combined queries
- `seen_at` - Time-based queries
- `[project_id, seen_at]` - Project + time filtering
- `created_at` - General sorting

**To Apply**:
```bash
php artisan migrate
```

### 2. Optimized Filament Relation Manager
**File**: `app/Filament/Resources/ProjectsResource/RelationManagers/ErrorReportedRelationManager.php`

**Optimizations**:
- **Selective column loading** - Only loads necessary columns instead of `SELECT *`
- **Default sorting** - Applies `ORDER BY exception_class` by default
- **Smaller pagination** - Limits to 10 records per page
- **Useful filters** - Adds "Last 7 days" and "Has message" filters

### 3. Maintenance Command
**File**: `app/Console/Commands/OptimizeErrorReportsTable.php`

**Features**:
```bash
# Analyze current table state
php artisan error-reports:optimize --analyze

# Clean old records (dry run)
php artisan error-reports:optimize --days=30 --dry-run

# Actually delete old records
php artisan error-reports:optimize --days=30

# Clean records older than 90 days
php artisan error-reports:optimize --days=90
```

### 4. Bootstrap Configuration Fix
**File**: `bootstrap/app.php`

Fixed the Flare error reporting configuration that was causing command failures.

## Production Deployment Steps

### Step 1: Apply Database Indexes
```bash
# In production environment
php artisan migrate
```

### Step 2: Verify Indexes Were Created
```bash
# Check indexes in MySQL
SHOW INDEX FROM error_reports;
```

### Step 3: Analyze Current Data
```bash
# Get overview of your data
php artisan error-reports:optimize --analyze
```

### Step 4: Clean Old Data (Optional)
```bash
# See what would be deleted (safe)
php artisan error-reports:optimize --days=90 --dry-run

# Actually clean old data if needed
php artisan error-reports:optimize --days=90
```

### Step 5: Monitor Performance
- The original problematic query should now be fast
- Filament error reports page should load much faster
- Monitor MySQL slow query log for any remaining issues

## Expected Performance Improvements

### Before Optimization
- Queries causing "Out of sort memory" errors
- Full table scans on large datasets
- High memory usage during sorting
- Slow page loads in Filament admin

### After Optimization
- ✅ **Instant filtering** by `project_id` using index
- ✅ **Fast sorting** by `exception_class` using index  
- ✅ **Reduced memory usage** with selective column loading
- ✅ **Faster pagination** with smaller page sizes
- ✅ **Efficient queries** with composite indexes

## Maintenance Recommendations

### Regular Cleanup
```bash
# Weekly cleanup (keep last 30 days)
php artisan error-reports:optimize --days=30

# Monthly analysis
php artisan error-reports:optimize --analyze
```

### Monitoring
1. **Monitor table size**: Keep an eye on `error_reports` table growth
2. **Archive old data**: For compliance, export old records before deletion
3. **MySQL configuration**: Consider increasing `sort_buffer_size` if needed
4. **Disk space**: Monitor available space as indexes require additional storage

### Optional MySQL Configuration
If you still experience memory issues with very large datasets:

```sql
# In MySQL configuration (my.cnf)
sort_buffer_size = 256M
read_buffer_size = 128M
read_rnd_buffer_size = 256M
```

## Query Performance Examples

### Before (Problematic)
```sql
SELECT * FROM error_reports 
WHERE project_id = 3 
  AND project_id IS NOT NULL 
ORDER BY exception_class ASC 
LIMIT 10 OFFSET 0;
```
- Full table scan
- Sorts entire result set in memory
- Memory allocation error on large datasets

### After (Optimized)
```sql
SELECT id, project_id, exception_class, message, seen_at, created_at, updated_at 
FROM error_reports 
WHERE project_id = 3 
ORDER BY exception_class ASC 
LIMIT 10 OFFSET 0;
```
- Uses `idx_error_reports_project_exception` composite index
- Only loads necessary columns
- Fast execution with index-assisted sorting

## Troubleshooting

### If Migration Fails
```bash
# Check current indexes
SHOW INDEX FROM error_reports;

# If some indexes exist, you may need to modify the migration
```

### If Commands Fail
```bash
# Check error logs
tail -f storage/logs/laravel.log

# Verify database connection
php artisan tinker
>>> DB::connection()->getPdo();
```

### Performance Still Slow
1. Check if indexes were actually created
2. Run `ANALYZE TABLE error_reports;` to update statistics
3. Consider partitioning for extremely large tables (millions of records)
4. Monitor MySQL slow query log

## Files Modified

1. ✅ `database/migrations/2025_05_29_090724_add_indexes_to_error_reports_table.php` - **NEW**
2. ✅ `app/Filament/Resources/ProjectsResource/RelationManagers/ErrorReportedRelationManager.php` - **MODIFIED**
3. ✅ `app/Console/Commands/OptimizeErrorReportsTable.php` - **NEW**
4. ✅ `bootstrap/app.php` - **MODIFIED** (temporarily disabled Flare)

The optimizations should resolve the "Out of sort memory" error and significantly improve the performance of error report queries. 