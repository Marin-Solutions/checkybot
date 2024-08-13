<?php

namespace App\Documentation;

use OpenApi\Annotations as OA;

/**
 * @OA\PathItem(
 *     path="/api/v1/websites",
 *     summary="WebsitesAPI",
 *     description="API for managing websites"
 * )
 */


class WebsiteDocumentation
{

    /**
     * List all websites
     * @OA\Get (
     *     path="/v1/websites",
     *     tags={"websites"},
     *     @OA\Parameter(
     *         in="query",
     *         name="name[eq]",
     *         required=false,
     *         description="filter by name using name[eq]=value ",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         in="query",
     *         name="url[eq]",
     *         required=false,
     *         description="filter by url using url[eq]=value ",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         in="query",
     *         name="createdBy[eq]",
     *         required=false,
     *         description="filter by createdBy using createdBy[eq]=value ",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         in="query",
     *         name="createdAt",
     *         required=false,
     *         description="filter by createdAt using createdAt[eq] or [lt] or [gt]=value ; example= createdAt[lt]='2024-10-10'",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         in="query",
     *         name="updatedAt",
     *         required=false,
     *         description="filter by updatedAt using updatedAt[eq] or [lt] or [gt]=value; example= updatedAt[lt]='2024-10-10'",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         in="query",
     *         name="uptimeCheck",
     *         required=false,
     *         description="filter by uptimeCheck using uptimeCheck[eq] or [lt] or [gt]=value; example= uptimeCheck[lt]='2024-10-10'",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         in="query",
     *         name="uptimeInterval",
     *         required=false,
     *         description="filter by uptimeInterval using uptimeInterval[eq] or [lt] or [gt]=value; example= uptimeInterval[lt]='2024-10-10'",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         in="query",
     *         name="includeCustomer",
     *         required=false,
     *         description="include customer data from createdBy ",
     *         @OA\Schema(type="bool")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="OK",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 type="array",
     *                 property="rows",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(
     *                         property="id",
     *                         type="number",
     *                         example="1"
     *                     ),
     *                     @OA\Property(
     *                         property="name",
     *                         type="string",
     *                         example="website name"
     *                     ),
     *                     @OA\Property(
     *                         property="url",
     *                         type="string",
     *                         example="https://example.com"
     *                     ),
     *                     @OA\Property(
     *                         property="description",
     *                         type="string",
     *                         example="little description of website"
     *                     ),
     *                     @OA\Property(
     *                         property="created_by",
     *                         type="integer",
     *                         example="1",
     *                         description="id of customer (client)"
     *                     ),
     *                     @OA\Property(
     *                         property="created_at",
     *                         type="string",
     *                         example="2023-02-23T00:09:16.000000Z"
     *                     ),
     *                     @OA\Property(
     *                         property="updated_at",
     *                         type="string",
     *                         example="2023-02-23T00:09:16.000000Z"
     *                     ),
     *                     @OA\Property(
     *                         property="delete_at",
     *                         type="string",
     *                         example="2023-02-23T00:09:16.000000Z"
     *                     ),
     *                     @OA\Property(
     *                         property="uptime_check",
     *                         type="integer unsigned",
     *                         description="expressed in minutes",
     *                         example="1"
     *                     ),
     *                     @OA\Property(
     *                         property="uptime_interval",
     *                         type="integer unsigned",
     *                         description="expressed in minutes",
     *                         example="1"
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */

    public function index()
    {
        // not logic, just documentation
    }


    /**
     * Website store
     * @OA\Post (
     *     path="/v1/websites",
     *     summary="Create a new website",
     *     tags={"websites"},
     *     @OA\RequestBody(
     *         description="Website data",
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="id",
     *                 type="number",
     *                 example="1"
     *             ),
     *             @OA\Property(
     *                 property="name",
     *                 type="string",
     *                 example="website name"
     *             ),
     *             @OA\Property(
     *                 property="url",
     *                 type="string",
     *                 example="https://example.com"
     *             ),
     *             @OA\Property(
     *                 property="description",
     *                 type="string",
     *                 example="little description of website"
     *             ),
     *             @OA\Property(
     *                 property="created_by",
     *                 type="integer",
     *                 example="1",
     *                 description="id of customer (client)"
     *             ),
     *             @OA\Property(
     *                 property="created_at",
     *                 type="string",
     *                 example="2023-02-23T00:09:16.000000Z"
     *             ),
     *             @OA\Property(
     *                 property="updated_at",
     *                 type="string",
     *                 example="2023-02-23T00:09:16.000000Z"
     *             ),
     *             @OA\Property(
     *                 property="delete_at",
     *                 type="string",
     *                 example="2023-02-23T00:09:16.000000Z"
     *             ),
     *             @OA\Property(
     *                 property="uptime_check",
     *                 type="integer unsigned",
     *                 description="expressed in minutes",
     *                 example="1"
     *             ),
     *             @OA\Property(
     *                 property="uptime_interval",
     *                 type="integer unsigned",
     *                 description="expressed in minutes",
     *                 example="1"
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Responses with data of website"),
     *     @OA\Response(response=409, description="The website is already stored in the database, try again"),
     *     @OA\Response(response=406, description="The website does not exist on the web"),
     * )
     */
    public function store()
    {
        // not logic, just documentation
    }



    /**
     * Show a website selected
     * @OA\Get (
     *     path="/v1/websites/{id}",
     *     tags={"websites"},
     *     @OA\Parameter(
     *         in="path",
     *         name="id",
     *         required=true,
     *         description="id of website",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         in="query",
     *         name="includeCustomer",
     *         required=false,
     *         description="include customer data from createdBy ",
     *         @OA\Schema(type="bool")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="OK",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 type="array",
     *                 property="rows",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(
     *                         property="id",
     *                         type="number",
     *                         example="1"
     *                     ),
     *                     @OA\Property(
     *                         property="name",
     *                         type="string",
     *                         example="website name"
     *                     ),
     *                     @OA\Property(
     *                         property="url",
     *                         type="string",
     *                         example="https://example.com"
     *                     ),
     *                     @OA\Property(
     *                         property="description",
     *                         type="string",
     *                         example="little description of website"
     *                     ),
     *                     @OA\Property(
     *                         property="created_by",
     *                         type="integer",
     *                         example="1",
     *                         description="id of customer (client)"
     *                     ),
     *                     @OA\Property(
     *                         property="created_at",
     *                         type="string",
     *                         example="2023-02-23T00:09:16.000000Z"
     *                     ),
     *                     @OA\Property(
     *                         property="updated_at",
     *                         type="string",
     *                         example="2023-02-23T00:09:16.000000Z"
     *                     ),
     *                     @OA\Property(
     *                         property="delete_at",
     *                         type="string",
     *                         example="2023-02-23T00:09:16.000000Z"
     *                     ),
     *                     @OA\Property(
     *                         property="uptime_check",
     *                         type="integer unsigned",
     *                         description="expressed in minutes",
     *                         example="1"
     *                     ),
     *                     @OA\Property(
     *                         property="uptime_interval",
     *                         type="integer unsigned",
     *                         description="expressed in minutes",
     *                         example="1"
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function show() {}
}
