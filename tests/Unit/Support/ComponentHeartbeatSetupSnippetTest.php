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
        ->toContain('Requires jq for safe JSON quoting.')
        ->toContain("COMPONENT_NAME='queue $(touch /tmp/checkybot-owned)'")
        ->toContain('DECLARED_COMPONENTS_JSON=')
        ->toContain('jq -n')
        ->toContain('--arg name "$COMPONENT_NAME"')
        ->toContain('--argjson declared_components "$DECLARED_COMPONENTS_JSON"')
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

test('component api snippet declares active sibling package components', function () {
    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'queue',
        'declared_interval' => '5m',
    ]);
    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'worker',
        'declared_interval' => '1m',
    ]);
    ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'name' => 'retired',
        'declared_interval' => '1h',
    ]);

    $snippet = ComponentHeartbeatSetupSnippet::componentCurl($component);

    expect($snippet)
        ->toContain('"name":"queue"')
        ->toContain('"name":"worker"')
        ->not->toContain('"name":"retired"');
});

test('component api snippet keeps manual components out of declarations', function () {
    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'manual-cron',
        'source' => 'manual',
        'declared_interval' => '5m',
    ]);
    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'package-worker',
        'source' => 'package',
        'declared_interval' => '1m',
    ]);

    $snippet = ComponentHeartbeatSetupSnippet::componentCurl($component);

    expect($snippet)
        ->toContain("COMPONENT_NAME='manual-cron'")
        ->toContain('"name":"package-worker"')
        ->not->toContain('"name":"manual-cron"');
});

test('guided setup component definitions identify placeholder scheduler signal', function () {
    expect(ComponentHeartbeatSetupSnippet::projectPackageDefinitions())
        ->toContain('Replace this cache key with your scheduler or job success signal.');
});
