# Reverb Setup Guide for Production (Ploi.io)

This guide explains how to set up Laravel Reverb for real-time broadcasting in production.

## What is Reverb?

Laravel Reverb is a WebSocket server that enables real-time communication between your application and users. In CheckyBot, it's used for:
- Live SEO check progress updates
- Real-time notifications
- Live data updates without page refresh

## Production Setup on Ploi.io

### Step 1: Environment Variables

Copy these variables to your production `.env` file in Ploi:

```bash
# Broadcasting
BROADCAST_CONNECTION=reverb

# Reverb Credentials (IMPORTANT: Generate new secure keys)
REVERB_APP_ID=1
REVERB_APP_KEY=base64:dGhpc2lzYXJhbmRvbWtleWZvcnJldmVyYmFwcA==
REVERB_APP_SECRET=base64:dGhpc2lzYXJhbmRvbXNlY3JldGZvcnJldmVyYg==

# Production Domain (CHANGE THIS!)
REVERB_HOST=yourdomain.com
REVERB_PORT=443
REVERB_SCHEME=https

# Server Configuration
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Frontend Variables (IMPORTANT: Must match above)
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Step 2: Generate Secure Keys (RECOMMENDED)

On your server, generate new secure keys:

```bash
# Generate App Key
echo "base64:$(openssl rand -base64 32)"

# Generate App Secret
echo "base64:$(openssl rand -base64 32)"
```

Replace the default keys in your `.env` with the generated values.

### Step 3: Set Up Reverb Daemon in Ploi

1. Log in to Ploi.io
2. Go to your site
3. Navigate to **"Daemons"** tab
4. Click **"Add Daemon"**
5. Fill in:
   - **Command**: `php artisan reverb:start`
   - **User**: Your deployment user (usually `ploi`)
   - **Directory**: Your site's root directory
6. Click **"Add Daemon"**

This keeps Reverb running 24/7 in the background.

### Step 4: Configure Nginx (Optional - if needed)

If you need WebSocket proxy support, Ploi usually handles this automatically. If not, add this to your Nginx config:

```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_cache_bypass $http_upgrade;
}
```

### Step 5: Build Frontend Assets

After updating `.env`, rebuild your frontend:

```bash
npm run build
```

Or in Ploi, add this to your deployment script:
```bash
npm install
npm run build
```

### Step 6: Test the Connection

After deployment, check if Reverb is running:

```bash
# SSH into your server
ps aux | grep reverb
```

You should see the Reverb process running.

## Troubleshooting

### Reverb Not Connecting?

1. **Check daemon status** in Ploi - make sure it's running
2. **Check logs**: `tail -f storage/logs/laravel.log`
3. **Verify environment variables** are set correctly
4. **Ensure SSL certificate** is active for your domain
5. **Check firewall** - port 8080 should be accessible (Ploi usually handles this)

### Console Errors in Browser?

Check browser console for WebSocket errors. Common issues:
- Wrong `REVERB_HOST` (should match your domain)
- Frontend assets not rebuilt after `.env` changes
- Mixed content (HTTP/HTTPS) issues

## Security Notes

- **Always use HTTPS** in production (`REVERB_SCHEME=https`)
- **Generate unique keys** - don't use the defaults in production
- **Keep keys secret** - never commit actual keys to git
- Consider enabling `REVERB_SCALING_ENABLED=true` with Redis for multiple servers

## What Values to Change

✅ **MUST CHANGE:**
- `REVERB_HOST` - Your actual production domain

⚠️ **SHOULD CHANGE (for security):**
- `REVERB_APP_KEY` - Generate new random key
- `REVERB_APP_SECRET` - Generate new random secret

✓ **Can keep as-is:**
- `REVERB_APP_ID` (can be `1` or any identifier)
- `REVERB_PORT` (443 for HTTPS)
- `REVERB_SCHEME` (https for production)
- `REVERB_SERVER_PORT` (8080 is standard)

## Support

If you encounter issues, check:
1. Laravel logs: `storage/logs/laravel.log`
2. Ploi daemon logs in the Ploi dashboard
3. Browser console for frontend errors
