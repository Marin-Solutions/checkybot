<?php

namespace App\Http\Controllers;

use App\Filters\WebsiteFilter;
use Spatie\Dns\Dns;
use App\Models\Website;
use Illuminate\Http\Request;
use App\Http\Resources\WebsiteCollection;
use App\Http\Requests\StoreWebsiteRequest;
use App\Http\Requests\UpdateWebsiteRequest;

class WebsiteController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     *  include relationships in filter!!
     *  this segment set a variable $includeCustomers with $request->query('includeCustomers')
     *  if variable is true
     *  then $website call method with() adding user relationships
     */
    public function index(Request $request)
    {
        $filter= new WebsiteFilter();
        $queryItems = $filter->transform($request);


        $includeCustomers= $request->query('includeCustomers');

        $websites = Website::where($queryItems);
        if($includeCustomers){
            $websites=$websites->with('user');
        }

        return new WebsiteCollection($websites->paginate()->appends($request->query()));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $dns = new Dns();
        $dns->getRecords('spatie.be');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWebsiteRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Website $website)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Website $website)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWebsiteRequest $request, Website $website)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Website $website)
    {
        //
    }
}
