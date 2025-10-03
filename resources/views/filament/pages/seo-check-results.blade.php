<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
            Crawl Results ({{ $results->total() }} URLs found)
        </h3>
        <div class="text-sm text-gray-500 dark:text-gray-400">
            Showing {{ $results->firstItem() ?? 0 }} to {{ $results->lastItem() ?? 0 }} of {{ $results->total() }} results
        </div>
    </div>

    @if($results->count() > 0)
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        URL
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Status
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Title
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Size
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Response Time
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Links
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Issues
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($results as $result)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <a href="{{ $result->url }}" target="_blank" class="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 truncate max-w-xs">
                                {{ $result->url }}
                            </a>
                            @if($result->canonical_url && $result->canonical_url !== $result->url)
                            <span class="ml-2 text-xs text-gray-500 dark:text-gray-400" title="Canonical: {{ $result->canonical_url }}">
                                🔗
                            </span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    @if($result->status_code >= 200 && $result->status_code < 300) bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                    @elseif($result->status_code >= 300 && $result->status_code < 400) bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                                    @elseif($result->status_code >= 400) bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                                    @else bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200
                                    @endif">
                            {{ $result->status_code }}
                            @if($result->is_soft_404)
                            <span class="ml-1" title="Soft 404">⚠️</span>
                            @endif
                            @if($result->is_redirect_loop)
                            <span class="ml-1" title="Redirect Loop">🔄</span>
                            @endif
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm text-gray-900 dark:text-white">
                            @if($result->title)
                            <div class="truncate max-w-xs" title="{{ $result->title }}">
                                {{ $result->title }}
                            </div>
                            @else
                            <span class="text-gray-500 dark:text-gray-400 italic">No title</span>
                            @endif
                        </div>
                        @if($result->h1)
                        <div class="text-xs text-gray-600 dark:text-gray-400 truncate max-w-xs" title="{{ $result->h1 }}">
                            H1: {{ $result->h1 }}
                        </div>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                        {{ number_format($result->page_size_bytes / 1024, 1) }} KB
                        @if($result->image_count > 0)
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $result->image_count }} images
                        </div>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                        {{ number_format($result->response_time_ms, 0) }}ms
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                        <div class="flex flex-col">
                            <span class="text-green-600 dark:text-green-400">
                                {{ $result->internal_link_count }} internal
                            </span>
                            <span class="text-blue-600 dark:text-blue-400">
                                {{ $result->external_link_count }} external
                            </span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($result->issues && count($result->issues) > 0)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                            {{ count($result->issues) }} issues
                        </span>
                        @else
                        <span class="text-green-600 dark:text-green-400 text-sm">✓ Clean</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    @if($results->hasPages())
    <div class="mt-6">
        {{ $results->links() }}
    </div>
    @endif
    @else
    <div class="text-center py-12">
        <div class="text-gray-500 dark:text-gray-400">
            <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No crawl results found</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                The SEO crawl hasn't found any pages yet, or the crawl is still in progress.
            </p>
        </div>
    </div>
    @endif
</div>


