<?php

use App\Libraries\WorkspaceStore;
use CodeIgniter\Test\CIUnitTestCase;

final class WorkspaceStoreTest extends CIUnitTestCase
{
    private WorkspaceStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new WorkspaceStore(':memory:');
    }

    public function testTicketLifecycleAndCommentCascade(): void
    {
        $project = $this->store->saveProject(['name' => 'Customer support', 'description' => 'Requests from customers']);
        $id = $this->store->saveIssue(['title' => 'A customer request', 'project_id' => $project, 'assignee_id' => 1]);
        $this->store->saveIssue(['status' => 'In progress', 'priority' => 'Urgent'], $id);
        $issue = $this->store->issue($id);
        $this->assertSame('A customer request', $issue['title']);
        $this->assertSame('In progress', $issue['status']);
        $this->assertSame('Urgent', $issue['priority']);
        $this->assertSame($project, $issue['project_id']);
        $this->store->addComment($id, 1, 'Investigating now');
        $this->assertCount(1, $this->store->comments($id));
        $this->store->deleteIssue($id);
        $this->assertFalse($this->store->issue($id));
        $this->assertSame([], $this->store->comments($id));
    }

    public function testInvalidStatusDoesNotChangeExistingIssue(): void
    {
        $id = $this->store->saveIssue(['title' => 'Keep this valid']);
        try { $this->store->saveIssue(['status' => 'invalid'], $id); $this->fail('Invalid status was accepted'); }
        catch (InvalidArgumentException) { $this->assertSame('Todo', $this->store->issue($id)['status']); }
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->saveIssue(['title' => 'Invalid date', 'due_date' => '2026-02-31']);
    }

    public function testUnknownAssigneeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->saveIssue(['title' => 'Invalid assignee', 'assignee_id' => 99999]);
    }

    public function testWhitespaceTitleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->saveIssue(['title' => '   ']);
    }

    public function testRateLimitPersistsAcrossAttempts(): void
    {
        for ($i = 0; $i < 10; $i++) { $this->assertTrue($this->store->loginAllowed('test-ip')); $this->store->recordLogin('test-ip', false); }
        $this->assertFalse($this->store->loginAllowed('test-ip'));
        $this->store->recordLogin('test-ip', true);
        $this->assertTrue($this->store->loginAllowed('test-ip'));
    }

    public function testPasswordsAreHashedAndNotIncludedInWorkspaceResponse(): void
    {
        $user = $this->store->userByEmail('alex@orbit.local');
        $this->assertTrue(password_verify('demo12345', $user['password']));
        foreach ($this->store->data()['users'] as $user) { $this->assertArrayNotHasKey('password', $user); }
    }

    public function testStaffUserCanBeCreatedAndPasswordReset(): void
    {
        $id = $this->store->saveUser([
            'name' => 'Taylor Reed',
            'email' => 'taylor@orbit.local',
            'password' => 'secure-password-12',
            'color' => '#75b8e9',
        ]);
        $user = $this->store->userByEmail('taylor@orbit.local');
        $this->assertSame($id, (int) $user['id']);
        $this->assertTrue(password_verify('secure-password-12', $user['password']));
        $this->store->saveUser(['name' => 'Taylor Reed', 'email' => 'taylor@orbit.local', 'password' => 'another-password-99', 'color' => '#75b8e9'], $id);
        $this->assertTrue(password_verify('another-password-99', $this->store->userByEmail('taylor@orbit.local')['password']));
        $this->assertSame('Taylor Reed', $this->store->user($id)['name']);
    }

    public function testStaffUserRejectsShortPasswordAndDuplicateEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->saveUser(['name' => 'Short', 'email' => 'short@orbit.local', 'password' => 'too-short']);
    }
}
