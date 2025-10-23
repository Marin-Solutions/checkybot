<style>
    @keyframes spin {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }
    }
</style>
<div style="padding: 0; margin: 0;" data-seo-check-id="{{ $seoCheck->id }}">
    @if($isRunning)
    <!-- Live Progress Section -->
    <div style="background: transparent; border: none; padding: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 18px; font-weight: 600; color: #111827; display: flex; align-items: center; margin: 0;">
                <svg style="animation: spin 1s linear infinite; margin-right: 12px; width: 20px; height: 20px; color: #3b82f6;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                SEO Health Check in Progress
            </h3>
            <span style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 500; background: #dbeafe; color: #1e40af;">
                Running
            </span>
        </div>

        <!-- Progress Bar -->
        <div style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; font-size: 14px; color: #6b7280; margin-bottom: 8px;">
                <span>Progress: <span style="font-weight: 600;">{{ $progress }}%</span></span>
                <span><span style="font-weight: 600;">{{ $urlsCrawled }}</span> of <span style="font-weight: 600;">{{ $totalUrls }}</span> URLs crawled</span>
            </div>
            <div style="width: 100%; background: #e5e7eb; border-radius: 6px; height: 8px; overflow: hidden; position: relative;">
                <div style="background: linear-gradient(90deg, #3b82f6, #1d4ed8); height: 100%; border-radius: 6px; transition: width 0.5s ease-out; width: {{ $progress }}%; position: absolute; top: 0; left: 0;"></div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 24px; font-weight: 700; color: #1e293b; margin-bottom: 4px;">{{ $urlsCrawled }}</div>
                <div style="font-size: 14px; color: #64748b;">URLs Crawled</div>
            </div>
            <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 24px; font-weight: 700; color: #dc2626; margin-bottom: 4px;">{{ $issuesFound }}</div>
                <div style="font-size: 14px; color: #64748b;">Issues Found</div>
            </div>
            <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 24px; font-weight: 700; color: #059669; margin-bottom: 4px;">
                    @if($estimatedTime)
                    {{ $estimatedTime }}
                    @else
                    Calculating...
                    @endif
                </div>
                <div style="font-size: 14px; color: #64748b;">Estimated Time</div>
            </div>
        </div>

        <!-- Current Activity -->
        <div style="background: #dbeafe; padding: 16px; border-radius: 8px; margin-top: 16px; display: none;" class="current-url">
            <div style="font-size: 14px; color: #1e40af;">
                <strong>Currently crawling:</strong> <span class="current-url-text"></span>
            </div>
        </div>

        <!-- Crawl Strategy Info -->
        @if($seoCheck->crawl_summary && isset($seoCheck->crawl_summary['crawl_strategy']))
        <div style="margin-top: 16px; padding: 12px; background: #dbeafe; border-radius: 8px;">
            <div style="font-size: 14px; color: #1e40af;">
                <strong>Crawl Strategy:</strong>
                @if($seoCheck->crawl_summary['crawl_strategy'] === 'sitemap_preload')
                <span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 9999px; font-size: 12px; font-weight: 500; background: #dcfce7; color: #166534; margin-left: 8px;">
                    Sitemap Preload
                </span>
                <span style="margin-left: 8px;">{{ $seoCheck->crawl_summary['sitemap_urls_found'] ?? 0 }} URLs detected from sitemap</span>
                @else
                <span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 9999px; font-size: 12px; font-weight: 500; background: #fef3c7; color: #92400e; margin-left: 8px;">
                    Dynamic Discovery
                </span>
                <span style="margin-left: 8px;">Discovering URLs as we crawl</span>
                @endif
            </div>
        </div>
        @endif

        <!-- Real-time indicator -->
        <div style="margin-top: 16px; font-size: 12px; color: #6b7280; display: flex; align-items: center;">
            <svg style="animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; width: 12px; height: 12px; margin-right: 4px;" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
            </svg>
            <span class="connection-status">Real-time updates active</span>
        </div>
    </div>
    @else
    <!-- Completed/Failed State -->
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none;" class="completion-section">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 18px; font-weight: 600; color: #111827; display: flex; align-items: center; margin: 0;">
                @if($seoCheck->isCompleted())
                <svg style="width: 20px; height: 20px; color: #16a34a; margin-right: 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                SEO Health Check Completed
                @elseif($seoCheck->isFailed())
                <svg style="width: 20px; height: 20px; color: #dc2626; margin-right: 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                SEO Health Check Failed
                @else
                <svg style="width: 20px; height: 20px; color: #6b7280; margin-right: 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                SEO Health Check Pending
                @endif
            </h3>
            <span style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 500; 
                    @if($seoCheck->isCompleted()) background: #dcfce7; color: #166534;
                    @elseif($seoCheck->isFailed()) background: #fee2e2; color: #dc2626;
                    @else background: #f3f4f6; color: #6b7280; @endif">
                {{ ucfirst($seoCheck->status) }}
            </span>
        </div>

        <!-- Completion Message -->
        <div style="margin-bottom: 24px; padding: 16px; background: #dcfce7; border-radius: 8px;">
            <div style="color: #166534; font-weight: 500;" class="completion-message">
                SEO Health Check completed! Health Score: {{ $seoCheck->getHealthScoreFormattedAttribute() }}
            </div>
        </div>

        <!-- Final Stats -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
            <div style="background: #f9fafb; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <div style="font-size: 24px; font-weight: 700; color: #111827; margin-bottom: 4px;">{{ $urlsCrawled }}</div>
                <div style="font-size: 14px; color: #6b7280;">URLs Crawled</div>
            </div>
            <div style="background: #f9fafb; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <div style="font-size: 24px; font-weight: 700; color: #ea580c; margin-bottom: 4px;">{{ $issuesFound }}</div>
                <div style="font-size: 14px; color: #6b7280;">Issues Found</div>
            </div>
            <div style="background: #f9fafb; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <div style="font-size: 24px; font-weight: 700; color: #16a34a; margin-bottom: 4px;" class="health-score">{{ $seoCheck->getHealthScoreFormattedAttribute() }}</div>
                <div style="font-size: 14px; color: #6b7280;">Health Score</div>
            </div>
            <div style="background: #f9fafb; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <div style="font-size: 24px; font-weight: 700; color: #111827; margin-bottom: 4px;">
                    @if($seoCheck->finished_at)
                    {{ $seoCheck->started_at->diffForHumans($seoCheck->finished_at, true) }}
                    @else
                    N/A
                    @endif
                </div>
                <div style="font-size: 14px; color: #6b7280;">Duration</div>
            </div>
        </div>
    </div>
    @endif
</div>