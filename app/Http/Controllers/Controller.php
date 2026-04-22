<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *      version="1.0.0",
 *      title="API Documentation for CHECKYBOT",
 *      description="API Documentation",
 *
 *      @OA\License(
 *          name="Apache 2.0",
 *          url="http://www.apache.org/licenses/LICENSE-2.0.html"
 *      )
 * )
 *
 * @OA\Server(
 *      url=L5_SWAGGER_CONST_HOST,
 *      description="API Server"
 * )
 *
 * @OA\SecurityScheme(
 *      securityScheme="checkybotApiKey",
 *      type="http",
 *      scheme="bearer",
 *      bearerFormat="Checkybot API key",
 *      description="Use an API key generated in the Checkybot admin."
 * )
 */
abstract class Controller {}
