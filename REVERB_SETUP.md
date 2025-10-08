# Laravel Reverb Setup Guide for Production (Ploi.io)

## What is Reverb?
Laravel Reverb is a WebSocket server that enables real-time features in the application (like live SEO check progress, notifications, etc.).

---

## Quick Setup Steps

### 1. Environment Configuration

Copy `.env.example` to `.env` and configure the following:

#### **Must Change:**
```bash
APP_URL=https://your-actual-domain.com
REVERB_HOST=your-actual-domain.com
```

#### **Recommended: Generate Secure Keys**
Run these commands to generate unique keys:
```bash
# Generate APP_KEY (if not already set)
php artisan key:generate

# Generate Reverb credentials
echo "REVERB_APP_KEY=base64:$(openssl rand -base64 32)"
echo "REVERB_APP_SECRET=base64:$(openssl rand -base64 32)"
```

Copy the output and update your `.env` file.

---

### 2. Ploi.io Configuration

#### **Step A: Set Environment Variables**
1. Log in to Ploi.io
2. Go to your site
3. Navigate to **Environment** tab
4. Paste all your `.env` variables
5. Save

#### **Step B: Create Reverb Daemon**
1. Go to **Daemons** tab
2. Click **"Add Daemon"**
3. Configure:
   - **Command:** `php artisan reverb:start`
   - **User:** Same as your site user
   - **Directory:** Your site root directory
4. Click **"Add Daemon"**

This will keep Reverb running 24/7 in the background.

---

### 3. Build Frontend Assets

After setting environment variables, build the frontend:

```bash
npm install
npm run build
```

Or in Ploi.io, you can set this as a **Deploy Script**.

---

### 4. Verify Reverb is Running

Check if Reverb is active:

```bash
# Via SSH or Ploi.io terminal
php artisan reverb:ping
```

Or check the daemon status in Ploi.io dashboard.

---

## Configuration Reference

### Environment Variables Explained

| Variable | Example | Description |
|----------|---------|-------------|
| `REVERB_HOST` | `checkybot.com` | Your production domain (no https://) |
| `REVERB_PORT` | `443` | Port for client connections (443 for HTTPS) |
| `REVERB_SCHEME` | `https` | Protocol (always `https` in production) |
| `REVERB_APP_ID` | `1` | Application ID (can be any unique string) |
| `REVERB_APP_KEY` | `base64:...` | Secret key for authentication |
| `REVERB_APP_SECRET` | `base64:...` | Secret for server authentication |
| `REVERB_SERVER_HOST` | `0.0.0.0` | Server bind address (don't change) |
| `REVERB_SERVER_PORT` | `8080` | Internal port (don't change) |

---

## Troubleshooting

### Issue: Reverb Won't Start
- Check daemon logs in Ploi.io
- Ensure port 8080 is not blocked
- Verify `.env` variables are correct

### Issue: WebSocket Connection Failed
- Ensure SSL certificate is active
- Check `REVERB_HOST` matches your domain
- Verify `VITE_*` variables are set correctly
- Rebuild frontend: `npm run build`

### Issue: "Connection Refused"
- Make sure Reverb daemon is running
- Check Redis is running: `redis-cli ping`
- Verify firewall settings in Ploi.io

---

## Testing Reverb Locally

For development/testing:

```bash
# Start Reverb server
php artisan reverb:start

# In another terminal, start frontend dev server
npm run dev
```

Visit your application and check browser console for WebSocket connection status.

---

## Additional Resources

- [Laravel Reverb Documentation](https://laravel.com/docs/11.x/reverb)
- [Ploi.io Documentation](https://ploi.io/documentation)
- Check `config/reverb.php` for advanced configuration

---

**Last Updated:** October 2025  
**Laravel Version:** 11.x  
**Reverb Package:** laravel/reverb
