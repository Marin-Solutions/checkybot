<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Http\Response;
    use Illuminate\Support\Facades\Auth;

    class ServerLogFileHistory extends Model
    {
        use HasFactory;

        protected $table = 'server_log_file_histories';

        protected $fillable = [
            'server_log_category_id',
            'log_file_name'
        ];

        public function logCategory(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(ServerLogCategory::class);
        }

        public static function doShellScript( int $server_id, int $user ): Response
        {
            $slf            = new ServerLogFileHistory();
            $slf->user_id   = $user;
            $slf->server_id = $server_id;
            $server         = Server::query()->where('id', $server_id)->firstOrFail();

            if ( $server->id == $slf->server_id && $server->created_by == $slf->user_id ) {
                $slf->token    = $server->token;
                $logCategories = $server->logCategories->where('should_collect', true)->toArray();
                $content       = $slf->contentShellScript($logCategories);
                $status        = 200;
            } else {
                $content = "Error: Server Owner not match with user request, try with your server. thank you";
                $status  = 401;
            }

            $response = response()->make($content, $status);
            $response->header('Content-Type', 'application/x-sh');
            $response->header('Content-Disposition', 'attachment; filename="log_reporter_server_info.sh"');
            return $response;
        }

        protected function contentShellScript( $logCategories = [] ): string
        {
            $content = "#!/bin/bash \n";

            // Set the API endpoint URL
            $server_id = $this->server_id;
            $token     = $this->token;
            $url       = $_ENV[ 'APP_URL' ];

            $content .= "API_LOG_URL='$url/api/v1/server-log-history' \n";

            // Set the token-id variable
            $content .= "TOKEN_ID='$token'\n";

            $content .= "SERVER_ID='$server_id'\n";
            if ( !empty($logCategories) ) {
                foreach ( $logCategories as $logCategory ) {
                    $tmpLog = '/tmp/' . $logCategory[ 'name' ] . '_log.log';

                    // Send the request to the API endpoint
                    $content .= "\n";
                    $content .= 'tail -c 2097152 ' . $logCategory[ 'log_directory' ] . ' > ' . $tmpLog;
                    $content .= "\n\n";
                    $content .= "curl -4 -s -X POST \\\n";
                    $content .= ' $API_LOG_URL\\' . "\n";
                    $content .= ' -H \'Authorization: Bearer \'$TOKEN_ID \\' . "\n";;
                    $content .= ' -F \'log=@' . $tmpLog . '\' \\' . "\n";
                    $content .= ' -F \'li=' . $logCategory[ 'id' ] . '\' \\' . "\n\n";
                    $content .= 'rm ' . $tmpLog;
                }
            }

            return $content;
        }

        /**
         * copy command, that download a script for monitoring servers
         *
         * @return string
         */
        public static function copyCommand( $server ): string
        {
            $user    = Auth::user()->id;
            $command = "wget https://checkybot.test/log-reporter/$server/$user -O log-reporter_server_info.sh ";
            $command .= "&& chmod +x $(pwd)/log-reporter_server_info.sh ";
            $command .= "&& (crontab -l ; echo \"0 * * * * $(pwd)/log_reporter_server_info.sh\") | crontab -";
            return $command;
        }
    }
