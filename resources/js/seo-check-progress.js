/**
 * SEO Check Progress - Real-time WebSocket updates
 * Handles live progress updates during SEO health checks
 */

class SeoCheckProgress {
    constructor(seoCheckId) {
        this.seoCheckId = seoCheckId;
        this.isConnected = false;
        this.lastTableRefresh = 0;
        this.refreshTimeout = null;
        this.init();
    }

    init() {
        if (typeof window.Echo === 'undefined') {
            console.warn('Echo is not available. Falling back to polling.');
            this.fallbackToPolling();
            return;
        }

        this.connectToWebSocket();
    }

    connectToWebSocket() {
        try {
            console.log(`Attempting to connect to SEO check progress channel: seo-checks.${this.seoCheckId}`);
            console.log('Echo object available:', !!window.Echo);
            
            // Listen for progress updates
            const channel = window.Echo.channel(`seo-checks.${this.seoCheckId}`);
            
            // Add connection event listeners
            channel.subscribed(() => {
                console.log(`✅ Successfully subscribed to channel: seo-checks.${this.seoCheckId}`);
                this.isConnected = true;
                this.updateConnectionStatus('WebSocket connected');
            });
            
            channel.error((error) => {
                console.error('❌ Channel subscription error:', error);
                this.updateConnectionStatus('WebSocket subscription failed');
                this.fallbackToPolling();
            });
            
            // Listen for crawl progress updates - try different event name formats
            channel.listen('crawl-progress-updated', (e) => {
                console.log('📊 Received crawl progress event (no dot):', e);
                this.handleProgressUpdate(e);
            });
            
            channel.listen('.crawl-progress-updated', (e) => {
                console.log('📊 Received crawl progress event (with dot):', e);
                this.handleProgressUpdate(e);
            });
            
            // Listen for crawl completion
            channel.listen('crawl-completed', (e) => {
                console.log('✅ Received crawl completion event (no dot):', e);
                this.handleCompletion(e);
            });
            
            channel.listen('.crawl-completed', (e) => {
                console.log('✅ Received crawl completion event (with dot):', e);
                this.handleCompletion(e);
            });
            
            // Listen for crawl failure
            channel.listen('crawl-failed', (e) => {
                console.log('❌ Received crawl failure event (no dot):', e);
                this.handleFailure(e);
            });
            
            channel.listen('.crawl-failed', (e) => {
                console.log('❌ Received crawl failure event (with dot):', e);
                this.handleFailure(e);
            });
            
        } catch (error) {
            console.error('❌ Failed to connect to WebSocket:', error);
            this.updateConnectionStatus('WebSocket connection failed');
            this.fallbackToPolling();
        }
    }

    handleProgressUpdate(data) {
        console.log('📊 Processing progress update with data:', data);
        
        // Dispatch Livewire event to update the component
        if (window.Livewire) {
            try {
                window.Livewire.dispatch('seo-check-progress-updated');
                console.log('🔄 Dispatching Livewire event: seo-check-progress-updated');
            } catch (e) {
                console.log('Livewire component no longer available for progress updates');
            }
        }
        
        // Also update DOM directly as backup
        setTimeout(() => {
            // Update progress bar
            this.updateProgressBar(data.progress);
            
            // Update stats
            this.updateStats({
                urlsCrawled: data.urlsCrawled,
                totalUrls: data.totalUrls,
                issuesFound: data.issuesFound,
                currentUrl: data.currentUrl
            });

            // Update estimated time with ETA from server
            this.updateEstimatedTime(data.urlsCrawled, data.totalUrls, data.etaSeconds);
            
            // Update connection status
            this.updateConnectionStatus('Real-time updates active');
        }, 100); // 100ms delay
    }

    handleCompletion(data) {
        // Update final stats
        this.updateStats({
            urlsCrawled: data.totalUrlsCrawled,
            totalUrls: data.totalUrlsCrawled,
            issuesFound: data.totalIssuesFound,
            healthScore: data.healthScore
        });

        // Show completion message
        this.showCompletion(data);
        
        // Dispatch completion event to refresh parent page sections
        if (window.Livewire) {
            try {
                window.Livewire.dispatch('seo-check-completed');
                window.Livewire.dispatch('seo-check-finished'); // Also dispatch for parent page updates
            } catch (e) {
                console.log('Livewire component no longer available for completion events');
            }
        }
        
        // Stop any ongoing operations
        this.stopPolling();
        this.cleanup();
    }

    handleFailure(data) {
        console.log('❌ Received crawl failure event:', data);
        
        // Hide progress elements
        const progressSection = document.querySelector('.progress-section');
        if (progressSection) {
            progressSection.style.display = 'none';
        }

        // Hide live progress section (Filament Livewire component)
        const liveProgressSection = document.querySelector('[wire\\:id*="seo-check-progress"]');
        if (liveProgressSection) {
            liveProgressSection.style.display = 'none';
        }

        // Show failure message
        const completionSection = document.querySelector('.completion-section');
        if (completionSection) {
            completionSection.style.display = 'block';
        }

        const completionMessage = document.querySelector('.completion-message');
        if (completionMessage) {
            completionMessage.textContent = 'SEO Health Check failed. Please try again.';
            completionMessage.classList.add('text-danger');
        }
        
        // Dispatch failure event to refresh parent page sections
        if (window.Livewire) {
            try {
                window.Livewire.dispatch('seo-check-failed');
                window.Livewire.dispatch('seo-check-finished'); // Also dispatch for parent page updates
            } catch (e) {
                console.log('Livewire component no longer available for failure events');
            }
        }
        
        // Stop any ongoing operations
        this.stopPolling();
        this.cleanup();
    }

    updateProgressBar(progress) {
        const progressBar = document.querySelector('.progress-bar');
        const progressText = document.querySelector('.progress-text');

        if (progressBar) {
            progressBar.style.width = `${progress}%`;
            console.log('📊 Updated progress bar to:', progress + '%');
        } else {
            console.warn('⚠️ Progress bar element not found');
        }

        if (progressText) {
            progressText.textContent = `${progress}%`;
            console.log('📊 Updated progress text to:', progress + '%');
        } else {
            console.warn('⚠️ Progress text element not found');
        }
    }

    updateStats(stats) {
        // Update URLs crawled - try multiple selectors
        let urlsCrawledElement = document.querySelector('.urls-crawled');
        if (!urlsCrawledElement) {
            urlsCrawledElement = document.querySelector('[class*="urls-crawled"]');
        }
        if (!urlsCrawledElement) {
            urlsCrawledElement = document.querySelector('span[class*="crawled"]');
        }
        
        if (urlsCrawledElement) {
            urlsCrawledElement.textContent = stats.urlsCrawled;
            console.log('📊 Updated URLs crawled to:', stats.urlsCrawled);
        } else {
            console.warn('⚠️ URLs crawled element not found');
            console.log('🔍 All elements with "crawled" in class:', document.querySelectorAll('[class*="crawled"]'));
        }

        // Update total URLs - try multiple selectors
        let totalUrlsElement = document.querySelector('.total-urls');
        if (!totalUrlsElement) {
            totalUrlsElement = document.querySelector('[class*="total-urls"]');
        }
        if (!totalUrlsElement) {
            totalUrlsElement = document.querySelector('span[class*="total"]');
        }
        
        if (totalUrlsElement) {
            totalUrlsElement.textContent = stats.totalUrls;
            console.log('📊 Updated total URLs to:', stats.totalUrls);
        } else {
            console.warn('⚠️ Total URLs element not found');
            console.log('🔍 All elements with "total" in class:', document.querySelectorAll('[class*="total"]'));
        }

        // Update issues found
        const issuesFoundElement = document.querySelector('.issues-found');
        if (issuesFoundElement) {
            issuesFoundElement.textContent = stats.issuesFound;
        }

        // Update current URL
        const currentUrlElement = document.querySelector('.current-url');
        const currentUrlTextElement = document.querySelector('.current-url-text');
        if (currentUrlElement && currentUrlTextElement && stats.currentUrl) {
            currentUrlTextElement.textContent = stats.currentUrl;
            currentUrlElement.style.display = 'block';
        }

        // Update health score if available
        if (stats.healthScore !== undefined) {
            const healthScoreElement = document.querySelector('.health-score');
            if (healthScoreElement) {
                healthScoreElement.textContent = `${stats.healthScore}%`;
            }
        }
    }

    updateEstimatedTime(urlsCrawled, totalUrls, etaSeconds = 0) {
        const estimatedTimeElement = document.querySelector('.estimated-time');
        if (!estimatedTimeElement) {
            return;
        }

        // Use ETA from server if available, otherwise fallback to simple calculation
        let estimatedSeconds = etaSeconds;
        
        if (estimatedSeconds === 0 && urlsCrawled > 0) {
            // Fallback calculation if server doesn't provide ETA
            const remainingUrls = totalUrls - urlsCrawled;
            estimatedSeconds = remainingUrls * 2; // Assume 2 seconds per URL
        }
        
        if (estimatedSeconds > 0) {
            const minutes = Math.floor(estimatedSeconds / 60);
            const seconds = estimatedSeconds % 60;
            
            if (minutes > 0) {
                estimatedTimeElement.textContent = `${minutes}m ${seconds}s`;
            } else {
                estimatedTimeElement.textContent = `${seconds}s`;
            }
        } else {
            estimatedTimeElement.textContent = 'Calculating...';
        }
    }

    updateConnectionStatus(status) {
        const connectionStatusElement = document.querySelector('.connection-status');
        if (connectionStatusElement) {
            connectionStatusElement.textContent = status;
        }
    }

    showCompletion(data) {
        // Hide progress elements
        const progressSection = document.querySelector('.progress-section');
        if (progressSection) {
            progressSection.style.display = 'none';
        }

        // Show completion section
        const completionSection = document.querySelector('.completion-section');
        if (completionSection) {
            completionSection.style.display = 'block';
        }

        // Update completion message
        const completionMessage = document.querySelector('.completion-message');
        if (completionMessage) {
            completionMessage.textContent = `SEO Health Check completed! Health Score: ${data.healthScore}%`;
        }

        // Trigger any completion callbacks
        if (typeof window.seoCheckCompleted === 'function') {
            window.seoCheckCompleted(data);
        }
    }

    fallbackToPolling() {
        // Poll every 3 seconds for updates
        this.pollingInterval = setInterval(() => {
            this.fetchProgress();
        }, 3000);

        // Initial fetch
        this.fetchProgress();
    }

    async fetchProgress() {
        try {
            const response = await fetch(`/api/seo-checks/${this.seoCheckId}/progress`);
            if (response.ok) {
                const data = await response.json();
                this.handleProgressUpdate(data);
                
                // If completed or failed, stop polling
                if (data.status === 'completed') {
                    this.handleCompletion(data);
                    this.stopPolling();
                } else if (data.status === 'failed') {
                    this.handleFailure(data);
                    this.stopPolling();
                }
            }
        } catch (error) {
            // Silently handle fetch errors
        }
    }

    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }

    cleanup() {
        // Clear any pending timeouts
        if (this.refreshTimeout) {
            clearTimeout(this.refreshTimeout);
            this.refreshTimeout = null;
        }
        
        // Stop polling
        this.stopPolling();
        
        // Clear any other references
        this.isConnected = false;
        this.lastTableRefresh = 0;
        
        console.log('🧹 SEO Check Progress cleanup completed');
    }

}

// Auto-initialize if seoCheckId is available
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, looking for SEO check ID...');
    const seoCheckIdElement = document.querySelector('[data-seo-check-id]');
    console.log('SEO check ID element:', seoCheckIdElement);
    
    if (seoCheckIdElement) {
        const seoCheckId = seoCheckIdElement.getAttribute('data-seo-check-id');
        console.log('Found SEO check ID:', seoCheckId);
        window.seoCheckProgress = new SeoCheckProgress(seoCheckId);
    } else {
        console.log('No SEO check ID element found');
        // Try to find SEO check ID from URL
        const urlMatch = window.location.pathname.match(/\/seo-checks\/(\d+)/);
        if (urlMatch) {
            const seoCheckId = urlMatch[1];
            console.log('Found SEO check ID from URL:', seoCheckId);
            window.seoCheckProgress = new SeoCheckProgress(seoCheckId);
        }
    }
});

// Export for manual initialization
window.SeoCheckProgress = SeoCheckProgress;
