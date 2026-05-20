<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class ServerLogFileHistory extends Model
{
    use HasFactory;

    protected $table = 'server_log_file_histories';

    protected $fillable = [
        'server_log_category_id',
        'log_file_name',
    ];

    public function logCategory(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServerLogCategory::class, 'server_log_category_id');
    }

    public static function doShellScript(int $server_id, int $user): Response
    {
        $slf = new ServerLogFileHistory;
        $slf->user_id = $user;
        $slf->server_id = $server_id;
        $server = Server::query()->where('id', $server_id)->firstOrFail();

        if ($server->id == $slf->server_id && $server->created_by == $slf->user_id) {
            $slf->token = $server->token;
            $logCategories = $server->logCategories->where('should_collect', true)->toArray();
            $content = $slf->contentShellScript($logCategories);
            $status = 200;
        } else {
            $content = 'Error: Server Owner not match with user request, try with your server. thank you';
            $status = 401;
        }

        $response = response()->make($content, $status);
        $response->header('Content-Type', 'application/x-sh');
        $response->header('Content-Disposition', 'attachment; filename="log_reporter_server_info.sh"');

        return $response;
    }

    protected function contentShellScript($logCategories = []): string
    {
        $content = "#!/bin/bash \n";

        // Set the API endpoint URL
        $server_id = $this->server_id;
        $token = $this->token;
        $url = config('app.url');

        $content .= 'API_LOG_URL='.escapeshellarg($url.'/api/v1/server-log-history')." \n";

        // Set the token-id variable
        $content .= 'TOKEN_ID='.escapeshellarg((string) $token)."\n";

        $content .= 'SERVER_ID='.escapeshellarg((string) $server_id)."\n";
        if (! empty($logCategories)) {
            foreach ($logCategories as $logCategory) {
                $tmpLog = '/tmp/'.$logCategory['name'].'_log.log';
                $escapedTmpLog = escapeshellarg($tmpLog);
                $curlLogFormValue = 'log=@"'.addcslashes($tmpLog, '\\"').'"';

                // Send the request to the API endpoint
                $content .= "\n";
                $content .= 'tail -c 2097152 '.escapeshellarg($logCategory['log_directory']).' > '.$escapedTmpLog;
                $content .= "\n\n";
                $content .= "curl -4 -fsS -o /dev/null -X POST \\\n";
                $content .= ' $API_LOG_URL\\'."\n";
                $content .= ' -H \'Authorization: Bearer \'$TOKEN_ID \\'."\n";
                $content .= ' -F '.escapeshellarg($curlLogFormValue).' \\'."\n";
                $content .= ' -F '.escapeshellarg('li='.$logCategory['id']).' \\'."\n\n";
                $content .= 'rm '.$escapedTmpLog;
            }
        }

        return $content;
    }

    /**
     * copy command, that download a script for monitoring servers
     */
    public static function copyCommand($server): string
    {
        $user = Auth::id();
        if (! $user) {
            return '';
        }

        $signedUrl = URL::temporarySignedRoute('server-log.script.download', now()->addHours(24), [
            'server_id' => $server,
            'user' => $user,
        ]);

        $command = 'wget '.escapeshellarg($signedUrl).' -O log_reporter_server_info.sh ';
        $command .= '&& chmod +x $(pwd)/log_reporter_server_info.sh ';
        $command .= '&& CRON_CMD="$(pwd)/log_reporter_server_info.sh" ';
        $command .= '&& CRON_ENTRY="0 * * * * \"$CRON_CMD\"" ';
        $command .= '&& (crontab -l 2>/dev/null | grep -Fqx "$CRON_ENTRY" || ';
        $command .= '(crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -)';

        return $command;
    }
}
