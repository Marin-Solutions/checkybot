<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ServerInformationHistory extends Model
{
    use HasFactory;

    protected $table = 'server_information_history';

    protected $fillable = [
        'server_id',
        'cpu_load',
        'ram_free_percentage',
        'ram_free',
        'disk_free_percentage',
        'disk_free_bytes',
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
        $sih = new ServerInformationHistory;
        $sih->server_id = $server_id;
        $sih->user = $user;
        $server = Server::where('id', $server_id)->first();

        $sih->token = $server->token;
        $serverOwner = $server->created_by;
        if ($sih->user == $serverOwner) {
            $content = $sih->contentShellScript();
            $status = 200;
        } else {
            $content = 'Error: Server Owner not match with user request, try with your server. thank you';
            $status = 401;
        }

        $response = response()->make($content, $status);
        $response->header('Content-Type', 'application/x-sh');
        $response->header('Content-Disposition', 'attachment; filename="reporter_server_info.sh"');

        return $response;
    }

    protected function contentShellScript(): string
    {
        $content = "#!/bin/bash \n\n";

        // Set the API endpoint URL
        $server_id = $this->server_id;
        $token = $this->token;
        $url = $_ENV['APP_URL'];

        $content .= "API_URL='$url/api/v1/server-history' \n";
        $content .= "TOKEN_ID='$token'\n";
        $content .= "SERVER_ID='$server_id'\n\n";

        // Get CPU information and calculate true usage percentage
        $content .= "CPU_CORES=$(nproc)\n";
        $content .= "CPU_LOAD=$(uptime | grep -oP '(?<=average:).*' | awk '{print \$1}' | sed 's/,//')\n";
        $content .= "CPU_USE=$(awk \"BEGIN {printf \\\"%.2f\\\", (\$CPU_LOAD/\$CPU_CORES)*100}\")\n\n";

        // Get RAM information - remove % sign
        $content .= "RAM_FREE_PERCENTAGE=$(free | awk '/Mem/ {print \$7*100/\$2}' )\n";
        $content .= "RAM_FREE=$(free | awk '/Mem/ {print \$7}')\n\n";

        // Get Disk information - remove % sign
        $content .= "DISK_FREE_PERCENTAGE=$(df --output=pcent / | awk 'NR==2{print 100-\$1}')\n";
        $content .= "DISK_FREE_BYTES=$(df --output=avail / | awk 'NR==2{print \$1}')\n\n";

        // Send data to API
        $content .= "curl -4 -s -X POST \\\n";
        $content .= " \$API_URL \\\n";
        $content .= " -H 'Authorization: Bearer '\$TOKEN_ID \\\n";
        $content .= " -H 'Content-Type: application/json' \\\n";
        $content .= " -H 'Accept: application/json' \\\n";
        $content .= " -d '{\n";
        $content .= "    \"cpu_load\": \"'\$CPU_LOAD'\",\n";
        $content .= "    \"cpu_cores\": \"'\$CPU_CORES'\",\n";
        $content .= "    \"cpu_use\": \"'\$CPU_USE'\",\n";
        $content .= "    \"s\": \"'\$SERVER_ID'\",\n";
        $content .= "    \"ram_free_percentage\": \"'\$RAM_FREE_PERCENTAGE'\",\n";
        $content .= "    \"ram_free\": \"'\$RAM_FREE'\",\n";
        $content .= "    \"disk_free_percentage\": \"'\$DISK_FREE_PERCENTAGE'\",\n";
        $content .= "    \"disk_free_bytes\": \"'\$DISK_FREE_BYTES'\"\n";
        $content .= "}'\n";

        return $content;
    }

    /**
     * copy command, that download a script for monitoring servers
     */
    public static function copyCommand($server): string
    {
        $user = Auth::user()->id;
        $command = "wget https://checkybot.com/reporter/$server/$user -O reporter_server_info.sh ";
        $command .= '&& chmod +x $(pwd)/reporter_server_info.sh ';
        $command .= '&& CRON_CMD="$(pwd)/reporter_server_info.sh" ';
        $command .= '&& (crontab -l | grep -Fq "*/1 * * * * $CRON_CMD" || ';
        $command .= '(crontab -l 2>/dev/null; echo "*/1 * * * * $CRON_CMD") | crontab -)';

        return $command;
    }
}
