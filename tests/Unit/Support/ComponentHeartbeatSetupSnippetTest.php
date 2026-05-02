<?php

use App\Models\Project;
use App\Models\ProjectComponent;
use App\Support\ComponentHeartbeatSetupSnippet;

test('component api snippet shell quotes component names and avoids expandable heredocs', function () {
    config(['app.url' => 'https://checkybot.example.com/']);

    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'queue $(touch /tmp/checkybot-owned)',
        'declared_interval' => '5m',
    ]);

    $snippet = ComponentHeartbeatSetupSnippet::componentCurl($component);

    expect($snippet)
        ->toContain("COMPONENT_NAME='queue $(touch /tmp/checkybot-owned)'")
        ->toContain('jq -n')
        ->toContain('--arg name "$COMPONENT_NAME"')
        ->toContain("https://checkybot.example.com/api/v1/projects/{$project->id}/components/sync")
        ->not->toContain('<<JSON')
        ->not->toContain('<<\'JSON\'');
});

test('component snippets floor zero interval minutes at one minute', function () {
    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->make([
        'project_id' => $project->id,
        'declared_interval' => null,
        'interval_minutes' => 0,
    ]);
    $component->setRelation('project', $project);

    expect(ComponentHeartbeatSetupSnippet::componentPackageDefinition($component))
        ->toContain("->every('1m')");

    expect(ComponentHeartbeatSetupSnippet::componentCurl($component))
        ->toContain("COMPONENT_INTERVAL='1m'");
});

test('component snippets do not emit legacy zero declared intervals', function () {
    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->make([
        'project_id' => $project->id,
        'declared_interval' => '0m',
        'interval_minutes' => 0,
    ]);
    $component->setRelation('project', $project);

    expect(ComponentHeartbeatSetupSnippet::componentPackageDefinition($component))
        ->toContain("->every('1m')")
        ->not->toContain("->every('0m')");

    expect(ComponentHeartbeatSetupSnippet::componentCurl($component))
        ->toContain("COMPONENT_INTERVAL='1m'")
        ->not->toContain("COMPONENT_INTERVAL='0m'");
});

test('guided setup component definitions identify placeholder scheduler signal', function () {
    expect(ComponentHeartbeatSetupSnippet::projectPackageDefinitions())
        ->toContain('Replace this cache key with your scheduler or job success signal.');
});
