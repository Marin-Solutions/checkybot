<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ServerResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ip' => $this->ip,
            'description' => $this->description,
            'cpu_cores' => $this->cpu_cores,
            'last_reporter_ip' => $this->last_reporter_ip,
            'last_reporter_user_agent' => $this->last_reporter_user_agent,
            'last_reporter_seen_at' => $this->last_reporter_seen_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
