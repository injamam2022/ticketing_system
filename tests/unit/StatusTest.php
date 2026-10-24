<?php

use App\Libraries\WorkspaceStore;
use CodeIgniter\Test\CIUnitTestCase;

final class StatusTest extends CIUnitTestCase
{
    public function testCustomStatusRenamePreservesIssuesAndOrder(): void
    {
        $store = new WorkspaceStore(':memory:');
        $id = $store->saveStatus(['name' => 'Client Approval', 'color' => '#00aabb', 'category' => 'review']);
        $issue = $store->saveIssue(['title' => 'Ready for client', 'status' => 'Client Approval']);
        $store->saveStatus(['name' => 'Approved', 'color' => '#11aa77', 'category' => 'done'], $id);
        $this->assertSame('Approved', $store->issue($issue)['status']);
        $this->assertSame('done', array_values(array_filter($store->statuses(), fn ($s) => $s['id'] === $id))[0]['category']);
        $store->moveStatus($id, 'up');
        $this->assertSame('Approved', $store->statuses()[4]['name']);
        $this->assertSame('Done', $store->statuses()[5]['name']);
    }

    public function testRenamedTodoRemainsDefaultAfterReopening(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'orbit-status-');
        try {
            $store = new WorkspaceStore($path);
            $todo = array_values(array_filter($store->statuses(), fn ($s) => $s['system_key'] === 'Todo'))[0];
            $store->saveStatus(['name' => 'New request', 'color' => '#aabbcc', 'category' => 'todo'], $todo['id']);
            $store = new WorkspaceStore($path);
            $this->assertCount(5, $store->statuses());
            $issue = $store->saveIssue(['title' => 'Default status']);
            $this->assertSame('New request', $store->issue($issue)['status']);
            $client = $store->saveClient(['name' => 'Test', 'company' => 'Test', 'email' => 'status@example.com', 'password' => 'A-client-password-123', 'projects' => [1], 'active' => '1']);
            $ticket = $store->submitClientTicket($client, ['title' => 'Portal default', 'description' => 'Help please', 'project_id' => 1, 'due_date' => '2026-11-15']);
            $this->assertSame('New request', $store->clientTicket($client, $ticket)['status']);
        } finally { $store = null; unlink($path); }
    }

    public function testInvalidStatusesAreRejectedWithoutChangingWorkflow(): void
    {
        $store = new WorkspaceStore(':memory:');
        foreach ([['name' => 'todo'], ['name' => ''], ['name' => str_repeat('a', 31)], ['color' => 'red'], ['category' => 'invalid'], ['name' => ['bad']]] as $override) {
            try {
                $store->saveStatus(array_merge(['name' => 'QA', 'color' => '#112233', 'category' => 'review'], $override));
                $this->fail('Invalid status accepted');
            } catch (InvalidArgumentException $error) { $this->assertCount(5, $store->statuses()); }
        }
        $this->expectException(InvalidArgumentException::class);
        $store->saveIssue(['title' => 'Unknown status', 'status' => 'Unknown']);
    }
}
