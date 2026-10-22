<?php

use App\Libraries\WorkspaceStore;
use CodeIgniter\Test\CIUnitTestCase;

final class ClientPortalTest extends CIUnitTestCase
{
    private WorkspaceStore $store;
    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new WorkspaceStore(':memory:');
        $this->client = $this->store->saveClient($this->account());
    }

    private function account(): array
    {
        return ['name' => 'Taylor James', 'company' => 'Acme', 'email' => 'taylor@example.com', 'password' => 'A-long-password-123', 'active' => '1', 'projects' => [1]];
    }

    private function submit(): int
    {
        return $this->store->submitClientTicket($this->client, ['title' => 'Help with my project', 'description' => 'A request from a client', 'project_id' => 1]);
    }

    public function testSubmissionEntersStaffWorkspaceWithTrustedOwnership(): void
    {
        $id = $this->store->submitClientTicket($this->client, ['title' => 'Client issue', 'description' => 'Details', 'project_id' => 1, 'status' => 'Done', 'assignee_id' => 2, 'client_id' => 999]);
        $issue = $this->store->issue($id);
        $this->assertSame('Todo', $issue['status']);
        $this->assertNull($issue['assignee_id']);
        $this->assertSame($this->client, $issue['client_id']);
        $this->assertSame('Taylor James', $this->store->data()['issues'][0]['client_name']);
        $this->assertCount(1, $this->store->clientTickets($this->client));
    }

    public function testUnassignedProjectCannotReceiveTicket(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->submitClientTicket($this->client, ['title' => 'Forbidden', 'description' => 'Details', 'project_id' => 2]);
    }

    public function testOtherClientsCannotReadOrReplyEvenOnSharedProject(): void
    {
        $id = $this->submit();
        $other = $this->store->saveClient(array_replace($this->account(), ['email' => 'other@example.com']));
        $this->assertSame([], $this->store->clientTickets($other));
        try { $this->store->clientTicket($other, $id); $this->fail('Read allowed'); } catch (DomainException) { $this->assertTrue(true); }
        $this->expectException(DomainException::class);
        $this->store->addClientMessage($id, 'Should not be allowed', $other);
    }

    public function testRepliesStaySeparateFromInternalComments(): void
    {
        $id = $this->submit();
        $this->store->addComment($id, 1, 'Private staff discussion');
        $this->store->addClientMessage($id, 'Client reply', $this->client);
        $this->store->addClientMessage($id, 'Team reply', null, 1);
        $snapshot = $this->store->clientConversation($id, $this->client);
        $this->assertSame(['Client reply', 'Team reply'], array_column($snapshot['messages'], 'body'));
        $this->assertArrayNotHasKey('description', $snapshot['ticket']);
        $this->assertFalse($snapshot['ticket']['closed']);
        $messages = $this->store->clientMessages($id);
        $this->assertSame(['Client reply', 'Team reply'], array_column($messages, 'body'));
        $this->assertSame([0, 1], array_column($messages, 'from_team'));
        $this->assertArrayNotHasKey('assignee_id', $this->store->clientTicket($this->client, $id));
        $this->store->saveIssue(['status' => 'Done'], $id);
        $this->assertSame('Done', $this->store->clientTicket($this->client, $id)['status']);
    }

    public function testRevokingProjectOrDisablingAccountRemovesAccess(): void
    {
        $id = $this->submit();
        $this->store->saveClient(array_replace($this->account(), ['projects' => [2], 'password' => '']), $this->client);
        $this->assertSame([], $this->store->clientTickets($this->client));
        try { $this->store->clientTicket($this->client, $id); $this->fail('Revoked access allowed'); } catch (DomainException) { $this->assertTrue(true); }
        $input = $this->account(); unset($input['active']);
        $this->store->saveClient($input, $this->client);
        $this->assertSame([], $this->store->clientProjects($this->client));
        $this->expectException(InvalidArgumentException::class);
        $this->submit();
    }

    public function testSubmissionRecordsTheInstituteForMastersProjectWork(): void
    {
        $north = $this->store->saveInstitute(['name' => 'Northbridge Institute']);
        $south = $this->store->saveInstitute(['name' => 'Southbridge Institute']);
        $work = $this->store->saveProject(['name' => 'Thesis portal', 'description' => 'Masters project', 'color' => '#9b8afb', 'institute_id' => $north]);
        $other = $this->store->saveProject(['name' => 'Field study', 'description' => 'Another masters project', 'color' => '#75b8e9', 'institute_id' => $south]);
        $this->store->saveClient(array_replace($this->account(), ['projects' => [1, $work, $other], 'password' => '']), $this->client);
        $id = $this->store->submitClientTicket($this->client, ['title' => 'Help with the thesis', 'description' => 'Details', 'project_id' => $work, 'institute_id' => $north]);
        $ticket = $this->store->clientTicket($this->client, $id);
        $this->assertSame('Northbridge Institute', $ticket['institute_name']);
        $this->assertSame('Thesis portal', $ticket['project_name']);
        $this->assertSame($north, (int) $this->store->issue($id)['institute_id']);
    }

    public function testUnlinkedProjectRecordsTheInstituteTheClientChooses(): void
    {
        $north = $this->store->saveInstitute(['name' => 'Northbridge Institute']);
        $id = $this->store->submitClientTicket($this->client, ['title' => 'Help with my project', 'description' => 'Details', 'project_id' => 1, 'institute_id' => $north]);
        $this->assertSame('Northbridge Institute', $this->store->clientTicket($this->client, $id)['institute_name']);
        $this->assertSame($north, (int) $this->store->issue($id)['institute_id']);
    }

    public function testChangingToAnotherInstituteRejectsThatProjectWork(): void
    {
        $north = $this->store->saveInstitute(['name' => 'Northbridge Institute']);
        $south = $this->store->saveInstitute(['name' => 'Southbridge Institute']);
        $work = $this->store->saveProject(['name' => 'Thesis portal', 'description' => 'Masters project', 'color' => '#9b8afb', 'institute_id' => $north]);
        $this->store->saveClient(array_replace($this->account(), ['projects' => [$work], 'password' => '']), $this->client);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Choose the institute for this master\'s project work.');
        $this->store->submitClientTicket($this->client, ['title' => 'Wrong institute', 'description' => 'Details', 'project_id' => $work, 'institute_id' => $south]);
    }

    public function testClientsHaveHashedPasswordsAndUniqueEmails(): void
    {
        $account = $this->store->clientByEmail('TAYLOR@EXAMPLE.COM');
        $this->assertTrue(password_verify('A-long-password-123', $account['password']));
        $this->assertArrayNotHasKey('password', $this->store->clients()[0]);
        $this->expectException(InvalidArgumentException::class);
        $this->store->saveClient($this->account());
    }

    public function testExistingDatabaseUpgradePreservesIssues(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'orbit-upgrade-');
        try {
            $legacy = new PDO('sqlite:' . $path);
            $legacy->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, password TEXT NOT NULL, color TEXT NOT NULL);
                INSERT INTO users VALUES (1,"Existing owner","owner@example.com","unused-test-hash","#9b8afb");
                CREATE TABLE issues (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", status TEXT NOT NULL, priority TEXT NOT NULL, assignee_id INTEGER, project_id INTEGER, label TEXT NOT NULL DEFAULT "", due_date TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
                INSERT INTO issues (id,title,status,priority,created_at,updated_at) VALUES (77,"Existing customer work","Todo","High","2026-09-29","2026-09-29");');
            $legacy = null;
            $upgraded = new WorkspaceStore($path);
            $this->assertSame('Existing customer work', $upgraded->issue(77)['title']);
            $this->assertNull($upgraded->issue(77)['client_id']);
            $upgraded = null;
            $reopened = new WorkspaceStore($path);
            $this->assertSame('Existing customer work', $reopened->issue(77)['title']);
            $reopened = null;
        } finally { unlink($path); }
    }
}
