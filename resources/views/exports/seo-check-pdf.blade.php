<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEO Health Check Report - {{ $website->name }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            text-align: center;
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #1e40af;
            margin: 0;
            font-size: 2.5em;
        }

        .header .subtitle {
            color: #6b7280;
            font-size: 1.2em;
            margin-top: 10px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }

        .summary-card h3 {
            margin: 0 0 10px 0;
            color: #374151;
            font-size: 1.1em;
        }

        .summary-card .value {
            font-size: 2em;
            font-weight: bold;
            margin: 10px 0;
        }

        .health-score {
            color: #059669;
        }

        .errors {
            color: #dc2626;
        }

        .warnings {
            color: #d97706;
        }

        .notices {
            color: #2563eb;
        }

        .section {
            margin-bottom: 40px;
        }

        .section h2 {
            color: #1e40af;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .issue-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .issue-table th,
        .issue-table td {
            border: 1px solid #d1d5db;
            padding: 12px;
            text-align: left;
        }

        .issue-table th {
            background-color: #f3f4f6;
            font-weight: bold;
            color: #374151;
        }

        .issue-table tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .severity-error {
            background-color: #fef2f2;
            color: #dc2626;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
        }

        .severity-warning {
            background-color: #fffbeb;
            color: #d97706;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
        }

        .severity-notice {
            background-color: #eff6ff;
            color: #2563eb;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
        }

        .url {
            color: #3b82f6;
            word-break: break-all;
        }

        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #6b7280;
        }

        @media print {
            body {
                margin: 0;
                padding: 15px;
            }

            .summary-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>SEO Health Check Report</h1>
        <div class="subtitle">{{ $website->name }}</div>
        <div class="subtitle">{{ $website->url }}</div>
        <div class="subtitle">Generated on {{ now()->format('F j, Y \a\t g:i A') }}</div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card">
            <h3>Health Score</h3>
            <div class="value health-score">{{ number_format($seoCheck->computed_health_score ?? 0, 1) }}%</div>
        </div>
        <div class="summary-card">
            <h3>URLs Crawled</h3>
            <div class="value">{{ number_format($seoCheck->total_urls_crawled) }}</div>
        </div>
        <div class="summary-card">
            <h3>Errors</h3>
            <div class="value errors">{{ $seoCheck->computed_errors_count ?? 0 }}</div>
        </div>
        <div class="summary-card">
            <h3>Warnings</h3>
            <div class="value warnings">{{ $seoCheck->computed_warnings_count ?? 0 }}</div>
        </div>
        <div class="summary-card">
            <h3>Notices</h3>
            <div class="value notices">{{ $seoCheck->computed_notices_count ?? 0 }}</div>
        </div>
        <div class="summary-card">
            <h3>Duration</h3>
            <div class="value">{{ $seoCheck->started_at && $seoCheck->finished_at ? $seoCheck->started_at->diffForHumans($seoCheck->finished_at, true) : 'N/A' }}</div>
        </div>
    </div>

    <!-- Check Details -->
    <div class="section">
        <h2>Check Details</h2>
        <table class="issue-table">
            <tr>
                <th>Started At</th>
                <td>{{ $seoCheck->started_at ? $seoCheck->started_at->format('F j, Y \a\t g:i A') : 'N/A' }}</td>
            </tr>
            <tr>
                <th>Finished At</th>
                <td>{{ $seoCheck->finished_at ? $seoCheck->finished_at->format('F j, Y \a\t g:i A') : 'N/A' }}</td>
            </tr>
            <tr>
                <th>Status</th>
                <td><span class="severity-{{ $seoCheck->status === 'completed' ? 'notice' : ($seoCheck->status === 'failed' ? 'error' : 'warning') }}">{{ ucfirst($seoCheck->status) }}</span></td>
            </tr>
            <tr>
                <th>Sitemap Used</th>
                <td>{{ $seoCheck->sitemap_used ? 'Yes' : 'No' }}</td>
            </tr>
            <tr>
                <th>Robots.txt Checked</th>
                <td>{{ $seoCheck->robots_txt_checked ? 'Yes' : 'No' }}</td>
            </tr>
        </table>
    </div>

    <!-- Issues Summary -->
    @if($issues->count() > 0)
    <div class="section">
        <h2>Issues Summary</h2>
        <table class="issue-table">
            <thead>
                <tr>
                    <th>URL</th>
                    <th>Issue Type</th>
                    <th>Severity</th>
                    <th>Description</th>
                    <th>Status Code</th>
                </tr>
            </thead>
            <tbody>
                @foreach($issues as $issue)
                <tr>
                    <td class="url">{{ $issue->url }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $issue->type)) }}</td>
                    <td><span class="severity-{{ $issue->severity->value }}">{{ ucfirst($issue->severity->value) }}</span></td>
                    <td>{{ $issue->description }}</td>
                    <td>{{ $issue->seoCrawlResult ? $issue->seoCrawlResult->status_code : 'N/A' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="section">
        <h2>Issues Summary</h2>
        <p style="text-align: center; color: #059669; font-size: 1.2em; padding: 20px;">
            🎉 No SEO issues found! Your website is in great shape.
        </p>
    </div>
    @endif

    <!-- Recommendations -->
    <div class="section">
        <h2>Recommendations</h2>
        @php
        $errorCount = $seoCheck->computed_errors_count ?? 0;
        $warningCount = $seoCheck->computed_warnings_count ?? 0;
        $healthScore = $seoCheck->computed_health_score ?? 0;
        @endphp

        <ul style="padding-left: 20px;">
            @if($errorCount > 0)
            <li><strong>Critical Issues:</strong> Address the {{ $errorCount }} critical SEO {{ $errorCount === 1 ? 'error' : 'errors' }} immediately. These issues can significantly impact your search rankings.</li>
            @endif

            @if($warningCount > 0)
            <li><strong>Warning Issues:</strong> Review and fix the {{ $warningCount }} warning{{ $warningCount === 1 ? '' : 's' }} to improve your SEO performance.</li>
            @endif

            @if($healthScore < 70)
                <li><strong>Low Health Score:</strong> Your SEO health score is below 70%. Focus on fixing critical and warning issues to improve your overall score.</li>
                @elseif($healthScore >= 90)
                <li><strong>Excellent Score:</strong> Your website has an excellent SEO health score! Keep monitoring and maintaining these high standards.</li>
                @endif

                <li><strong>Regular Monitoring:</strong> Set up scheduled SEO health checks to monitor your website's performance over time.</li>

                <li><strong>Continuous Improvement:</strong> SEO is an ongoing process. Regularly review and update your content, fix broken links, and optimize page performance.</li>
        </ul>
    </div>

    <div class="footer">
        <p>Generated by CheckyBot SEO Health Check System</p>
        <p>Report ID: {{ $seoCheck->id }} | Generated: {{ now()->format('Y-m-d H:i:s') }}</p>
    </div>

    <script>
        // Auto-print when page loads (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>

</html>