<?php

declare(strict_types=1);

use PlinCode\JobBoards\Workable\WorkableClient;

return [

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | The Workable widget root. Every endpoint hangs off it. Override it to
    | point the connector at a recorded fixture server.
    |
    */

    'base_url' => env('JOB_BOARDS_WORKABLE_BASE_URL', WorkableClient::API_BASE_URL),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds. "timeout" covers listing a whole board, "lookup_timeout" the
    | cheaper account calls behind validateSlug() and
    | fetchCompanyDescription(). Honoured only by PSR-18 clients that implement
    | PlinCode\JobBoards\Http\SupportsTimeout; other clients keep the timeout
    | they were built with.
    |
    */

    'timeout' => env('JOB_BOARDS_WORKABLE_TIMEOUT', 30),

    'lookup_timeout' => env('JOB_BOARDS_WORKABLE_LOOKUP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Request Headers
    |--------------------------------------------------------------------------
    |
    | Sent with every request. The public widget endpoint needs no
    | authentication, so Accept is all Workable asks for.
    |
    */

    'headers' => [
        'Accept' => 'application/json',
    ],

];
