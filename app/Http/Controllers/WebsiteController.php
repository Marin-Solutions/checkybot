<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\Request;
use App\Filters\WebsiteFilter;
use App\Http\Resources\WebsiteResource;
use App\Http\Resources\WebsiteCollection;
use App\Http\Requests\StoreWebsiteRequest;
use App\Http\Requests\UpdateWebsiteRequest;




class WebsiteController extends Controller
{

    /**
     * Display a lists of the resource.
     *
     *  include relationships in filter!!
     *  this segment set a variable $includeCustomer with $request->query('includeCustomers')
     *  if variable is true
     *  then $website call method with() adding user relationships
     */


     public function index(Request $request)
    {
        $filter= new WebsiteFilter();
        $queryItems = $filter->transform($request);


        $includeCustomers= $request->query('includeCustomer');

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

    }

    public function store(StoreWebsiteRequest $request)
    {
        $website= new Website();

        $exists=$website->checkWebsiteExists($request->url);
        if($exists){
            $website = Website::firstOrNew([
                'url' => $request->input('url')
            ]);

            if ($website->exists) {
                return response()->json(['message' => __('The website is already stored in the database, try again')], 409);
            } else {
                $resources  = new WebsiteResource(Website::create($request->all()));
                return response()->json($resources, 200);
            }
        }else{
            return response()->json(['message' => __('The website does not exist on the web')], 406);
        }
    }


    public function show(Website $website)
    {
        $includeCustomers= request()->query('includeCustomer');

        if($includeCustomers){
            return new WebsiteResource($website->loadMissing('user'));
        }else{
            return new WebsiteResource($website);
        }
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
        //$website->update($request->all());
    }

    public function updateUptimeCheck(UpdateWebsiteRequest $request, Website $website)
    {

        //return $website->update($request->all());
    }

    public function updateUptimeInterval(UpdateWebsiteRequest $request, Website $website)
    {
        $website->update($request->all());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Website $website)
    {
        //
    }
}
