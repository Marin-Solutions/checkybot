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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
