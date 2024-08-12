<?php

namespace App\Filters;

use App\Filters\ApiFilter;
use Illuminate\Http\Request;

/**
 * Class for customs filters
 */

class WebsiteFilter extends ApiFilter
{
    protected $safeParams = [
        'name'=>['eq'],
        'url'=>['eq'],
        'createdBy'=>['eq,lt,gt'],
        'uptimeCheck'=>['eq,lt,gt'],
        'uptimeInterval'=>['eq,lt,gt'],
    ];
    protected $columnMap = [
        'createdBy'=> 'created_by',
        'uptimeCheck'=> 'uptime_check',
        'uptimeInterval'=> 'uptime_interval',
    ];
    protected $operatorMap = [
        'eq'=> '=',
        'lt'=> '<',
        'lte'=> '<=',
        'gt'=> '>',
        'gte'=> '>=',
    ];

}
