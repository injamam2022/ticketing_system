<?php

use App\Libraries\TicketImages;
use App\Libraries\WorkspaceStore;
use CodeIgniter\Test\CIUnitTestCase;

final class TicketImagesTest extends CIUnitTestCase
{
    private function account(string $email = 'images@example.com'): array
    {
        return ['name' => 'Image Client', 'company' => 'Example', 'email' => $email, 'password' => 'Client-password-123', 'projects' => [1], 'active' => '1'];
    }

    private function image(string $key): array
    {
        return ['storage_key' => str_repeat($key, 32), 'filename' => 'Screenshot.png', 'mime' => 'image/png', 'size' => 100];
    }

    public function testImagesBelongToTicketAndFollowClientAccess(): void
    {
        $store = new WorkspaceStore(':memory:');
        $client = $store->saveClient($this->account());
        $other = $store->saveClient($this->account('other@example.com'));
        $id = $store->submitClientTicket($client, ['title' => 'Problem with screenshots', 'description' => 'Details', 'project_id' => 1], [$this->image('a'), $this->image('b')]);
        $images = $store->ticketImages($id, $client);
        $this->assertCount(2, $images);
        $this->assertArrayNotHasKey('storage_key', $images[0]);
        $this->assertSame($id, $store->ticketImage($images[0]['id'], $client)['issue_id']);
        try { $store->ticketImage($images[0]['id'], $other); $this->fail('Other client accessed image'); } catch (DomainException) { $this->assertTrue(true); }
        $store->saveClient(array_replace($this->account(), ['projects' => [2]]), $client);
        try { $store->ticketImage($images[0]['id'], $client); $this->fail('Removed project access retained'); } catch (DomainException) { $this->assertTrue(true); }
        $this->assertCount(2, $store->ticketImages($id));
        $store->deleteIssue($id);
        $this->assertSame([], $store->ticketImages($id));
        $this->expectException(DomainException::class);
        $store->ticketImage($images[0]['id']);
    }

    public function testImageInsertFailureRollsBackEntireTicket(): void
    {
        $store = new WorkspaceStore(':memory:');
        $client = $store->saveClient($this->account());
        $before = count($store->data()['issues']);
        try {
            $store->submitClientTicket($client, ['title' => 'Should roll back', 'description' => 'Details', 'project_id' => 1], [$this->image('a'), $this->image('a')]);
            $this->fail('Duplicate storage key was allowed');
        } catch (PDOException) { $this->assertCount($before, $store->data()['issues']); }
        $this->assertSame([], $store->clientTickets($client));
    }

    public function testInvalidImageMetadataDoesNotCreateTicket(): void
    {
        $store = new WorkspaceStore(':memory:');
        $client = $store->saveClient($this->account());
        foreach ([array_fill(0, 6, $this->image('a')), array_fill(0, 4, array_replace($this->image('a'), ['size' => 2 * 1024 * 1024])), [array_replace($this->image('a'), ['mime' => 'image/svg+xml'])], [array_replace($this->image('a'), ['size' => 3 * 1024 * 1024])], [array_replace($this->image('a'), ['storage_key' => '../file'])]] as $images) {
            try { $store->submitClientTicket($client, ['title' => 'Invalid', 'description' => 'Details', 'project_id' => 1], $images); $this->fail('Invalid images accepted'); }
            catch (InvalidArgumentException) { $this->assertSame([], $store->clientTickets($client)); }
        }
        $this->expectException(InvalidArgumentException::class);
        TicketImages::path('../public/image.png');
    }
}
