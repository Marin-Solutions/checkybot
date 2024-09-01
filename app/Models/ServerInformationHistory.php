<?php

namespace App\Models;

use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Http\Response;

class ServerInformationHistory extends Model
{
    use HasFactory;
    var string $server_id;
    var string $token;

    protected $fillable = [
        'cpu_load',
        'server_id',
        'ram_free_percentage',
        'ram_free',
        'disk_free_percentage',
        'disk_free_bytes'
    ];

    public static function isValidToken($token)
    {
        return true;
    }

    public static function doShellScript(int $server_id,string $token) :Response
    {
        $sih = new ServerInformationHistory();
        $sih->server_id = $server_id;
        $sih->token = $token;


        $content = $sih->contentShellScript();
        // Crear una respuesta de tipo archivo
        $response = response()->make($content, 200);
        $response->header('Content-Type', 'application/x-sh');
        $response->header('Content-Disposition', 'attachment; filename="reporter_server_info.sh"');

        return $response;
    }

    protected function contentShellScript() :string
    {
        $content= "#!/bin/bash \n";

        // Set the API endpoint URL
        $server_id=$this->server_id;
        $token =$this->token;
        $url  = $_ENV['APP_URL'];

        $content.= "API_URL='$url/api/v1/server-history' \n";

        // Set the token-id variable
        $content.= "TOKEN_ID='$token'\n";

        $content.= "SERVER_ID='$server_id'\n";

        // Get the IP address of the server
        //$content.= "IP_SERVER=$(ip addr show | awk '/inet / {print $2}' | cut -d/ -f1)\n";

        // Get the RAM usage
        $content.= "RAM_FREE_PERCENTAGE=$(free -h | awk '/Mem/ {print $7*100/$2\"%\"}' )\n";
        $content.= "RAM_FREE=$(free -h | awk '/Mem/ {print $7}')\n";

        // Get the CPU usage
        $content.= "CPU_LOAD=$(uptime | awk '{print $10}')\n";
        //$content.= "CPU_LOAD_2=$(uptime | awk '{print $11}')\n";
        //$content.= "CPU_LOAD_3=$(uptime | awk '{print $12}')\n";

        // Get the free disk
        $content.= "DISK_FREE_PERCENTAGE=$(df -h --output=pcent / | awk 'NR==2{print 100-$1\"%\"}')\n";
        $content.= "DISK_FREE_BYTES=$(df -h --output=avail / | awk 'NR==2{print $1}')\n";

        // Send the request to the API endpoint
        $content.= "curl -X POST \\\n";
        $content.= ' $API_URL\\'."\n";
        $content.= ' -H \'Authorization: Bearer \'$TOKEN_ID \\'."\n" ;
        $content.= ' -H \'Content-Type: application/json\' \\'."\n";
        $content.= ' -H \'Accept: application/json\' \\'."\n";
        $content.= ' -d \'{"cpu_load": "\'$CPU_LOAD\'", ';
        $content.= ' "s":"\'$SERVER_ID\'", "ram_free_percentage": "\'$RAM_FREE_PERCENTAGE\'", "ram_free": "\'$RAM_FREE\'", ';
        $content.= ' "disk_free_percentage": "\'$DISK_FREE_PERCENTAGE\'", "disk_free_bytes": "\'$DISK_FREE_BYTES\'"} \' ';

        return $content;
    }
}


