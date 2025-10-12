<?php

namespace App\Filters;

/**
 * Class for customs filters
 * Websites Filters
 * work with filters via URL
 * url: example.url?name[eq]=nameTest
 *  In this example we filter by name, name must be equal to nameTest
 * acronym:
 *      eq  =
 *      lt  <
 *      lte <=
 *      gt  >
 *      gte >=
 */
class WebsiteFilter extends ApiFilter
{
    protected $safeParams = [
        'name' => ['eq'],
        'url' => ['eq'],
        'createdBy' => ['eq'],
        'createdAt' => ['eq', 'lt', 'gt'],
        'updatedAt' => ['eq', 'lt', 'gt'],
        'uptimeCheck' => ['eq', 'lt', 'gt'],
        'uptimeInterval' => ['eq', 'lt', 'gt'],
    ];

    protected $columnMap = [
        'createdBy' => 'created_by',
        'uptimeCheck' => 'uptime_check',
        'createdAt' => 'created_at',
        'updatedAt' => 'updated_at',
        'uptimeInterval' => 'uptime_interval',
    ];

    protected $operatorMap = [
        'eq' => '=',
        'lt' => '<',
        'lte' => '<=',
        'gt' => '>',
        'gte' => '>=',
    ];
}
