<?php

    namespace App\Services;

    use App\Models\PloiServers;
    use Exception;
    use Illuminate\Http\Client\ConnectionException;
    use Illuminate\Support\Facades\Http;

    class PloiServerImportService
    {
        protected string $token;
        protected int $userId;
        protected int $ploiAccountId;

        public function __construct( string $token, int $userId, int $ploiAccountId )
        {
            $this->token  = $token;
            $this->userId = $userId;
            $this->ploiAccountId = $ploiAccountId;
        }

        /**
         * @throws ConnectionException
         * @throws Exception
         */
        public function import(): int
        {
            $page     = 1;
            $imported = 0;

            do {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ])->get('https://ploi.io/api/servers', [
                    'per_page' => 50,
                    'page'     => $page,
                ]);

                if ( !$response->ok() ) {
                    throw new Exception('Failed to fetch servers: ' . $response->body());
                }

                $body = $response->json();
                foreach ( $body[ 'data' ] as $item ) {
                    PloiServers::updateOrCreate(
                        [
                            'server_id'  => $item[ 'id' ],
                            'created_by' => $this->userId,
                            'ploi_account_id' => $this->ploiAccountId,
                        ],
                        [
                            'type'          => $item[ 'type' ],
                            'name'          => $item[ 'name' ],
                            'ip_address'    => $item[ 'ip_address' ],
                            'php_version'   => $item[ 'php_version' ],
                            'mysql_version' => $item[ 'mysql_version' ],
                            'sites_count'   => $item[ 'sites_count' ],
                            'status'        => $item[ 'status' ],
                            'status_id'     => $item[ 'status_id' ],
                        ]
                    );
                    $imported++;
                }

                $page++;
            } while ( isset($body[ 'meta' ][ 'last_page' ]) && $page <= $body[ 'meta' ][ 'last_page' ] );

            return $imported;
        }
    }
