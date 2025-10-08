<div class="space-y-6" data-seo-check-id="{{ $seoCheck->id }}">
    @if($isRunning)
    <!-- Live Progress Section -->
    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    SEO Health Check in Progress
                </h3>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                    Running
                </span>
            </div>

            <!-- Progress Bar -->
            <div class="mb-6 progress-section">
                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-2">
                    <span>Progress: <span class="progress-text">{{ $progress }}%</span></span>
                    <span><span class="urls-crawled">{{ $urlsCrawled }}</span> of <span class="total-urls">{{ $totalUrls }}</span> URLs crawled</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3 dark:bg-gray-700">
                    <div class="bg-blue-600 h-3 rounded-full transition-all duration-300 ease-out progress-bar" style="width: {{ $progress }}%"></div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-gray-900 dark:text-white urls-crawled">{{ $urlsCrawled }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">URLs Crawled</div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-orange-600 dark:text-orange-400 issues-found">{{ $issuesFound }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">Issues Found</div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-green-600 dark:text-green-400 estimated-time">
                        @if($estimatedTime)
                        {{ $estimatedTime }}
                        @else
                        Calculating...
                        @endif
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">Estimated Time</div>
                </div>
            </div>

            <!-- Current Activity -->
            <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg current-url" style="display: none;">
                <div class="text-sm text-blue-800 dark:text-blue-200">
                    <strong>Currently crawling:</strong> <span class="current-url-text"></span>
                </div>
            </div>

            <!-- Crawl Strategy Info -->
            @if($seoCheck->crawl_summary && isset($seoCheck->crawl_summary['crawl_strategy']))
            <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <div class="text-sm text-blue-800 dark:text-blue-200">
                    <strong>Crawl Strategy:</strong>
                    @if($seoCheck->crawl_summary['crawl_strategy'] === 'sitemap_preload')
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                        Sitemap Preload
                    </span>
                    <span class="ml-2">{{ $seoCheck->crawl_summary['sitemap_urls_found'] ?? 0 }} URLs detected from sitemap</span>
                    @else
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                        Dynamic Discovery
                    </span>
                    <span class="ml-2">Discovering URLs as we crawl</span>
                    @endif
                </div>
            </div>
            @endif

            <!-- Real-time indicator -->
            <div class="mt-4 text-xs text-gray-500 dark:text-gray-400 flex items-center">
                <svg class="animate-pulse w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                </svg>
                <span class="connection-status">Real-time updates active</span>
            </div>
        </div>
    </div>
    @else
    <!-- Completed/Failed State -->
    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg border border-gray-200 dark:border-gray-700 completion-section" style="display: none;">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
                    @if($seoCheck->isCompleted())
                    <svg class="w-5 h-5 text-green-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    SEO Health Check Completed
                    @elseif($seoCheck->isFailed())
                    <svg class="w-5 h-5 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    SEO Health Check Failed
                    @else
                    <svg class="w-5 h-5 text-gray-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    SEO Health Check Pending
                    @endif
                </h3>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                        @if($seoCheck->isCompleted()) bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                        @elseif($seoCheck->isFailed()) bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                        @else bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200 @endif">
                    {{ ucfirst($seoCheck->status) }}
                </span>
            </div>

            <!-- Completion Message -->
            <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
                <div class="text-green-800 dark:text-green-200 completion-message">
                    SEO Health Check completed! Health Score: {{ $seoCheck->getHealthScoreFormattedAttribute() }}
                </div>
            </div>

            <!-- Final Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $urlsCrawled }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">URLs Crawled</div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">{{ $issuesFound }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">Issues Found</div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-green-600 dark:text-green-400 health-score">{{ $seoCheck->getHealthScoreFormattedAttribute() }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">Health Score</div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">
                        @if($seoCheck->finished_at)
                        {{ $seoCheck->started_at->diffForHumans($seoCheck->finished_at, true) }}
                        @else
                        N/A
                        @endif
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">Duration</div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>