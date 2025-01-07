<?php

namespace App\Models;



use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServerInformationHistory extends Model
{
    use HasFactory;
    var string $server_id;
    var string $token;

    protected $fillable = [
        'server_id',
        'cpu_load',
        'cpu_cores',
        'ram_free_percentage',
        'ram_free',
        'disk_free_percentage',
        'disk_free_bytes'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public static function isValidToken()
    {
        return true;
    }

    public static function doShellScript(int $server_id, int $user): Response
    {
        $sih = new ServerInformationHistory();
        $sih->server_id = $server_id;
        $sih->user = $user;
        $server = Server::where('id', $server_id)->first();

        $sih->token = $server->token;
        $serverOwner = $server->created_by;
        if ($sih->user == $serverOwner) {
            $content = $sih->contentShellScript();
            $status = 200;
        } else {
            $content = "Error: Server Owner not match with user request, try with your server. thank you";
            $status = 401;
        }

        $response = response()->make($content, $status);
        $response->header('Content-Type', 'application/x-sh');
        $response->header('Content-Disposition', 'attachment; filename="reporter_server_info.sh"');
        return $response;
    }

    protected function contentShellScript(): string
    {
        $content = "#!/bin/bash \n";

        // Set the API endpoint URL
        $server_id = $this->server_id;
        $token = $this->token;
        $url  = $_ENV['APP_URL'];

        $content .= "API_URL='$url/api/v1/server-history' \n";

        // Set the token-id variable
        $content .= "TOKEN_ID='$token'\n";

        $content .= "SERVER_ID='$server_id'\n";

        // Get the RAM usage
        $content .= "RAM_FREE_PERCENTAGE=$(free | awk '/Mem/ {print $7*100/$2\"%\"}' )\n";
        $content .= "RAM_FREE=$(free | awk '/Mem/ {print $7}')\n";

        // Get the CPU usage
        $content .= "CPU_LOAD=$(uptime  | grep -oP '(?<=average:).*'|awk '{print $1}'|sed 's/,$//')\n";

        // Get the free disk
        $content .= "DISK_FREE_PERCENTAGE=$(df --output=pcent / | awk 'NR==2{print 100-$1\"%\"}')\n";
        $content .= "DISK_FREE_BYTES=$(df --output=avail / | awk 'NR==2{print $1}')\n";

        // Send the request to the API endpoint
        $content .= "curl -4 -s -X POST \\\n";
        $content .= ' $API_URL\\' . "\n";
        $content .= ' -H \'Authorization: Bearer \'$TOKEN_ID \\' . "\n";
        $content .= ' -H \'Content-Type: application/json\' \\' . "\n";
        $content .= ' -H \'Accept: application/json\' \\' . "\n";
        $content .= ' -d \'{"cpu_load": "\'$CPU_LOAD\'", ';
        $content .= ' "s":"\'$SERVER_ID\'", "ram_free_percentage": "\'$RAM_FREE_PERCENTAGE\'", "ram_free": "\'$RAM_FREE\'", ';
        $content .= ' "disk_free_percentage": "\'$DISK_FREE_PERCENTAGE\'", "disk_free_bytes": "\'$DISK_FREE_BYTES\'"} \' ';

        return $content;
    }

    /**
     * copy command, that download a script for monitoring servers
     *
     * @return string
     */
    public static function copyCommand($server): string
    {
        $user = Auth::user()->id;
        $command  = "wget https://checkybot.com/reporter/$server/$user -O reporter_server_info.sh ";
        $command .= "&& chmod +x $(pwd)/reporter_server_info.sh ";
        $command .= "&& (crontab -l ; echo \"*/1 * * * * $(pwd)/reporter_server_info.sh\") | crontab -";
        return $command;
    }
}
