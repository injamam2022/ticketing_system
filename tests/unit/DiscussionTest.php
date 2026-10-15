<?php
use App\Libraries\WorkspaceStore;
use CodeIgniter\Test\CIUnitTestCase;

final class DiscussionTest extends CIUnitTestCase
{
    public function testOwnershipVersionsReactionsAndThreads(): void
    {
        $s = new WorkspaceStore(':memory:');
        $issue = $s->saveIssue(['title'=>'Discussion test']);
        $id = $s->postDiscussion($issue,1,'Original');
        try { $s->editDiscussion($issue,$id,2,'Forbidden',1); $this->fail(); } catch (DomainException) { $this->assertSame('Original',$s->comments($issue)[0]['body']); }
        $s->editDiscussion($issue,$id,1,'Edited',1);
        try { $s->editDiscussion($issue,$id,1,'Stale',1); $this->fail(); } catch (RuntimeException) { $this->assertSame('Edited',$s->comments($issue)[0]['body']); }
        $s->reactToComment($issue,$id,1,'👍'); $s->reactToComment($issue,$id,2,'👍');
        $this->assertSame(2,$s->discussion($issue,1)['comments'][0]['reactions'][0]['count']);
        $s->reactToComment($issue,$id,1,'👍');
        $this->assertSame(1,$s->discussion($issue,1)['comments'][0]['reactions'][0]['count']);
        $reply=$s->postDiscussion($issue,2,'Reply',$id);
        $this->assertCount(1,$s->commentNotifications(1));
        $s->threadAction($issue,$id,1,'resolve');
        try { $s->postDiscussion($issue,1,'Blocked',$id); $this->fail(); } catch (InvalidArgumentException) { $this->assertCount(2,$s->comments($issue)); }
        $s->threadAction($issue,$id,1,'reopen');
        try { $s->deleteDiscussion($issue,$id,2); $this->fail(); } catch (DomainException) { $this->assertCount(2,$s->comments($issue)); }
        $s->deleteDiscussion($issue,$id,1);
        $this->assertSame('',$s->comments($issue)[0]['body']);
        $this->assertSame($reply,$s->comments($issue)[1]['id']);
    }

    public function testMentionsCrossIssueProtectionAndSubIssues(): void
    {
        $s = new WorkspaceStore(':memory:');
        $issue=$s->saveIssue(['title'=>'Parent','project_id'=>1]);
        $other=$s->saveIssue(['title'=>'Other']);
        $id=$s->postDiscussion($issue,1,'Hello @[Jamie](user:2)');
        $this->assertSame('mention',$s->commentNotifications(2)[0]['kind']);
        $this->assertSame([],$s->commentNotifications(1));
        try { $s->postDiscussion($other,1,'Wrong issue',$id); $this->fail(); } catch (OutOfBoundsException) { $this->assertSame([],$s->comments($other)); }
        $child=$s->issueFromComment($issue,$id,'Follow-up','subissue',1);
        $this->assertSame(1,$s->issue($child)['project_id']);
        $this->assertSame($issue,$s->relatedCommentIssues($child)[0]['id']);
        $this->assertSame($child,$s->relatedCommentIssues($issue)[0]['id']);
    }
}
