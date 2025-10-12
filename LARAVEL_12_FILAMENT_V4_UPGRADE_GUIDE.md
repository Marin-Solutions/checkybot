# Laravel 12 + Filament v4 Production Deployment Guide

This guide covers what needs to be done AFTER merging the upgrade branch to production.

## 📋 Overview

- **Laravel Version:** 11.x → 12.33.0
- **Filament Version:** v3 → v4.1.6
- **PHP Version:** 8.3.20+ (REQUIRED)
- **Node.js Version:** 18+ (REQUIRED for Vite 5.0)

---

## ⚠️ BEFORE MERGING TO PRODUCTION

### 1. Update Server Requirements

**In Ploi.io (or your hosting control panel):**

- ✅ **PHP Version:** Update to PHP 8.3.20 or higher
- ✅ **Node.js Version:** Update to Node.js 18 or higher

**CRITICAL:** These must be done BEFORE merging, or the application will fail.

---

## 🚀 AFTER MERGING TO PRODUCTION

### Step 1: SSH into Production Server

```bash
cd /path/to/your/application
```

### Step 2: Update Dependencies

```bash
# Update Composer dependencies
composer install --optimize-autoloader --no-dev

# Update NPM dependencies
npm ci --production=false
```

### Step 3: Build Production Assets (CRITICAL)

```bash
# Build frontend assets - THIS IS REQUIRED
npm run build
```

### Step 4: Run Migrations

```bash
php artisan migrate --force
```

### Step 5: Publish Shield Resources (CRITICAL)

```bash
# Publish Filament Shield for v4 compatibility
php artisan shield:publish --force
```

### Step 6: Clear All Caches

```bash
# Clear all Laravel caches
php artisan optimize:clear

# Or run individually:
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
```

### Step 7: Optimize for Production

```bash
# Cache configuration and routes
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Optimize Filament
php artisan filament:optimize
```

### Step 8: Restart Queue Workers (If Using Horizon)

```bash
php artisan horizon:terminate
```

---

## ✅ VERIFICATION STEPS

After deployment, verify everything is working:

```bash
# 1. Check versions
php artisan about

# Expected output:
#   Laravel Version: 12.33.0
#   PHP Version: 8.3.20
#   Filament Version: v4.1.6

# 2. Check routes are registered
php artisan route:list --path=admin | head -20

# 3. Test Shield is working
# Visit: https://yourdomain.com/admin/shield/roles
```

---

## 🔥 QUICK DEPLOYMENT SCRIPT

You can run all commands at once:

```bash
#!/bin/bash
set -e

echo "🚀 Starting Laravel 12 + Filament v4 deployment..."

# Dependencies
composer install --optimize-autoloader --no-dev
npm ci --production=false

# Build assets (CRITICAL)
npm run build

# Migrations
php artisan migrate --force

# Shield resources (CRITICAL)
php artisan shield:publish --force

# Clear caches
php artisan optimize:clear

# Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan filament:optimize

# Restart workers
php artisan horizon:terminate

echo "✅ Deployment completed successfully!"
```

---

## 🐛 TROUBLESHOOTING

### Issue: "Class not found" errors
**Solution:**
```bash
composer dump-autoload
php artisan optimize:clear
```

### Issue: Shield pages show errors
**Solution:**
```bash
php artisan shield:publish --force
php artisan optimize:clear
```

### Issue: Filament pages not styled correctly
**Solution:**
```bash
npm run build
php artisan optimize:clear
```

### Issue: Forms showing half-width sections
**Solution:** Clear browser cache and hard refresh (Ctrl+Shift+R)

---

## 📞 ROLLBACK (If Needed)

If deployment fails:

```bash
# In Ploi, revert to previous deployment
# Or manually:
git checkout HEAD~1
composer install --optimize-autoloader --no-dev
npm run build
php artisan migrate:rollback
php artisan optimize:clear
```

---

## ✅ POST-DEPLOYMENT CHECKLIST

- [ ] PHP version is 8.3.20+
- [ ] Node.js version is 18+
- [ ] `npm run build` completed successfully
- [ ] `php artisan about` shows Laravel 12.33.0 and Filament v4.1.6
- [ ] Shield roles page loads: `/admin/shield/roles`
- [ ] All resource pages load without errors
- [ ] Forms display with proper full-width sections
- [ ] Queue workers are running (if using Horizon)

---

**Note:** This upgrade is backward compatible. All existing functionality will work as expected after following these steps.
