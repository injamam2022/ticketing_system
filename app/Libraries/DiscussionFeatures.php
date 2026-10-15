<?php

namespace App\Libraries;

use DomainException;
use InvalidArgumentException;
use OutOfBoundsException;

trait DiscussionFeatures
{
    public const REACTIONS = ['👍', '👎', '❤️', '🎉', '🚀', '👀', '✅', '🙏', '💡', '😂', '😕', '🔥'];

    private function discussionComment(int $issue, int $id, bool $allowDeleted = false): array
    {
        $comment = $this->run('SELECT * FROM comments WHERE id=? AND issue_id=?', [$id, $issue])->fetch();
        if (!$comment || (!$allowDeleted && $comment['deleted_at'])) { throw new OutOfBoundsException('Comment not found.'); }
        return $comment;
    }

    private function ownComment(int $issue, int $id, int $user): array
    {
        $comment = $this->discussionComment($issue, $id);
        if ((int) $comment['user_id'] !== $user) { throw new DomainException('You can only edit or delete your own comments.'); }
        return $comment;
    }

    public function discussion(int $issue, int $user): array
    {
        if (!$this->issue($issue)) { throw new OutOfBoundsException('Issue not found.'); }
        $comments = $this->comments($issue);
        foreach ($comments as &$comment) {
            $comment['can_edit'] = !$comment['deleted_at'] && (int) $comment['user_id'] === $user;
            $comment['body'] = $comment['deleted_at'] ? '' : $comment['body'];
            $comment['reactions'] = [];
            if (!$comment['deleted_at']) {
                foreach ($this->run('SELECT r.emoji,r.user_id,u.name FROM comment_reactions r JOIN users u ON u.id=r.user_id WHERE r.comment_id=? ORDER BY u.name', [$comment['id']])->fetchAll() as $reaction) {
                    $emoji = $reaction['emoji'];
                    $comment['reactions'][$emoji] ??= ['emoji' => $emoji, 'count' => 0, 'mine' => false, 'names' => []];
                    $comment['reactions'][$emoji]['count']++;
                    $comment['reactions'][$emoji]['mine'] |= (int) $reaction['user_id'] === $user;
                    $comment['reactions'][$emoji]['names'][] = $reaction['name'];
                }
            }
            $comment['reactions'] = array_values($comment['reactions']);
            $comment['attachments'] = $comment['deleted_at'] ? [] : $this->run('SELECT id,filename,mime,size FROM comment_attachments WHERE comment_id=? ORDER BY id', [$comment['id']])->fetchAll();
            $root = $comment['parent_id'] ?: $comment['id'];
            $comment['following'] = (bool) $this->run('SELECT 1 FROM comment_subscriptions WHERE comment_id=? AND user_id=?', [$root, $user])->fetchColumn();
            $comment['linked_issues'] = $this->run('SELECT i.id,i.title,l.parent_issue_id FROM comment_issue_links l JOIN issues i ON i.id=l.new_issue_id WHERE l.comment_id=?', [$comment['id']])->fetchAll();
        }
        return ['comments' => $comments, 'events' => $this->run('SELECT e.*,u.name FROM issue_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.issue_id=? ORDER BY e.id', [$issue])->fetchAll()];
    }

    public function postDiscussion(int $issue, int $user, mixed $body, mixed $parent = null, array $attachments = []): int
    {
        if (!$this->issue($issue)) { throw new OutOfBoundsException('Issue not found.'); }
        $root = null;
        if ($parent !== null && $parent !== '') {
            if (!filter_var($parent, FILTER_VALIDATE_INT)) { throw new InvalidArgumentException('Invalid thread.'); }
            $original = $this->discussionComment($issue, (int) $parent, true);
            $root = (int) ($original['parent_id'] ?: $original['id']);
            if ($this->discussionComment($issue, $root, true)['resolved_at']) { throw new InvalidArgumentException('Reopen this thread before replying.'); }
        }
        $this->validateCommentBody($body, count($attachments));
        $this->db->beginTransaction();
        try {
            $this->run('INSERT INTO comments (issue_id,user_id,body,created_at,parent_id) VALUES (?,?,?,?,?)', [$issue,$user,trim($body),gmdate('c'),$root]);
            $id = (int) $this->db->lastInsertId();
            $this->saveCommentAttachments($id, $attachments);
            $this->notifyDiscussion($id, $root ?: $id, $user, $body);
            $this->subscribeComment($root ?: $id, $user, true);
            $this->db->commit();
            return $id;
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    private function validateCommentBody(mixed $body, int $attachments): void
    {
        if (!is_string($body) || (trim($body) === '' && !$attachments) || mb_strlen($body) > 5000) { throw new InvalidArgumentException('Write a comment (up to 5,000 characters) or attach a file.'); }
        if ($attachments > 5) { throw new InvalidArgumentException('Attach up to five files per comment.'); }
    }

    private function saveCommentAttachments(int $comment, array $files): void
    {
        foreach ($files as $file) {
            $this->run('INSERT INTO comment_attachments (comment_id,storage_key,filename,mime,size) VALUES (?,?,?,?,?)', [$comment,$file['storage_key'],$file['filename'],$file['mime'],$file['size']]);
        }
    }

    public function editDiscussion(int $issue, int $id, int $user, mixed $body, int $version, array $attachments = []): void
    {
        $comment = $this->ownComment($issue, $id, $user);
        $existing = (int) $this->run('SELECT COUNT(*) FROM comment_attachments WHERE comment_id=?', [$id])->fetchColumn();
        $this->validateCommentBody($body, $existing + count($attachments));
        $this->db->beginTransaction();
        try {
            $updated = $this->run('UPDATE comments SET body=?,edited_at=?,version=version+1 WHERE id=? AND version=? AND deleted_at IS NULL', [trim($body),gmdate('c'),$id,$version]);
            if (!$updated->rowCount()) { throw new \RuntimeException('This comment changed since you opened it. Reload before editing again.'); }
            $this->saveCommentAttachments($id, $attachments);
            if ($body !== $comment['body']) { $this->notifyDiscussion($id, (int) ($comment['parent_id'] ?: $id), $user, $body, true); }
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    public function deleteDiscussion(int $issue, int $id, int $user): void
    {
        $this->ownComment($issue, $id, $user);
        // Keep a placeholder so replies and permanent links remain valid.
        $this->run('UPDATE comments SET body=?,deleted_at=?,version=version+1 WHERE id=?', ['',gmdate('c'),$id]);
        $this->run('DELETE FROM comment_reactions WHERE comment_id=?', [$id]);
    }

    public function reactToComment(int $issue, int $id, int $user, mixed $emoji): void
    {
        $this->discussionComment($issue, $id);
        if (!is_string($emoji) || !in_array($emoji, self::REACTIONS, true)) { throw new InvalidArgumentException('Choose a supported reaction.'); }
        $removed = $this->run('DELETE FROM comment_reactions WHERE comment_id=? AND user_id=? AND emoji=?', [$id,$user,$emoji]);
        if (!$removed->rowCount()) { $this->run(($this->driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . ' INTO comment_reactions VALUES (?,?,?)', [$id,$user,$emoji]); }
    }

    private function subscribeComment(int $root, int $user, bool $following): void
    {
        if ($following) { $this->run(($this->driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . ' INTO comment_subscriptions VALUES (?,?)', [$root,$user]); }
        else { $this->run('DELETE FROM comment_subscriptions WHERE comment_id=? AND user_id=?', [$root,$user]); }
    }

    public function threadAction(int $issue, int $id, int $user, string $action): void
    {
        $comment = $this->discussionComment($issue, $id, true);
        $root = (int) ($comment['parent_id'] ?: $id);
        if (in_array($action, ['follow','unfollow'], true)) { $this->subscribeComment($root, $user, $action === 'follow'); }
        elseif (in_array($action, ['resolve','reopen'], true)) { $this->run('UPDATE comments SET resolved_at=? WHERE id=?', [$action === 'resolve' ? gmdate('c') : null,$root]); }
        else { throw new InvalidArgumentException('Unknown thread action.'); }
    }

    private function notifyDiscussion(int $id, int $root, int $author, string $body, bool $mentionsOnly = false): void
    {
        preg_match_all('/@\[[^\]\r\n]+\]\(user:(\d+)\)/u', $body, $matches);
        $mentions = array_map('intval', $matches[1]);
        $followers = $mentionsOnly ? [] : array_column($this->run('SELECT user_id FROM comment_subscriptions WHERE comment_id=?', [$root])->fetchAll(), 'user_id');
        foreach (array_unique(array_merge($mentions, $followers)) as $recipient) {
            if ((int) $recipient === $author || !$this->run('SELECT id FROM users WHERE id=?', [$recipient])->fetchColumn()) { continue; }
            $this->run('INSERT INTO comment_notifications (comment_id,user_id,kind,created_at) VALUES (?,?,?,?)', [$id,$recipient,in_array((int) $recipient,$mentions,true) ? 'mention' : 'reply',gmdate('c')]);
        }
    }

    public function commentNotifications(int $user): array
    {
        return $this->run('SELECT n.id,n.kind,n.read_at,n.created_at,c.id AS comment_id,c.issue_id,u.name,i.title FROM comment_notifications n JOIN comments c ON c.id=n.comment_id JOIN users u ON u.id=c.user_id JOIN issues i ON i.id=c.issue_id WHERE n.user_id=? AND c.deleted_at IS NULL ORDER BY n.id DESC LIMIT 50', [$user])->fetchAll();
    }

    public function readCommentNotification(int $id, int $user): void
    {
        $this->run('UPDATE comment_notifications SET read_at=? WHERE id=? AND user_id=?', [gmdate('c'),$id,$user]);
    }

    public function commentAttachment(int $id): array
    {
        $file = $this->run('SELECT a.*,c.issue_id,c.user_id,c.deleted_at FROM comment_attachments a JOIN comments c ON c.id=a.comment_id WHERE a.id=?', [$id])->fetch();
        if (!$file || $file['deleted_at']) { throw new OutOfBoundsException('Attachment not found.'); }
        return $file;
    }

    public function removeCommentAttachment(int $id, int $user): array
    {
        $file = $this->commentAttachment($id);
        if ((int) $file['user_id'] !== $user) { throw new DomainException('You can only remove attachments from your own comments.'); }
        $comment = $this->discussionComment((int) $file['issue_id'], (int) $file['comment_id']);
        if (trim($comment['body']) === '' && (int) $this->run('SELECT COUNT(*) FROM comment_attachments WHERE comment_id=?', [$file['comment_id']])->fetchColumn() === 1) { throw new InvalidArgumentException('Add comment text before removing its last attachment.'); }
        $this->run('DELETE FROM comment_attachments WHERE id=?', [$id]);
        $this->run('UPDATE comments SET edited_at=?,version=version+1 WHERE id=?', [gmdate('c'),$file['comment_id']]);
        return $file;
    }

    public function issueFromComment(int $issue, int $id, mixed $title, string $kind, int $user): int
    {
        $comment = $this->discussionComment($issue, $id);
        if (!in_array($kind, ['issue','subissue'], true)) { throw new InvalidArgumentException('Choose issue or sub-issue.'); }
        $source = $this->issue($issue);
        $this->db->beginTransaction();
        try {
            $new = $this->saveIssue(['title' => $title, 'description' => $comment['body'] . "\n\nSource: ORB-" . $issue . ' / comment #' . $id, 'project_id' => $source['project_id'], 'priority' => $source['priority']]);
            $this->run('INSERT INTO comment_issue_links VALUES (?,?,?)', [$id,$new,$kind === 'subissue' ? $issue : null]);
            $this->db->commit();
            return $new;
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    public function relatedCommentIssues(int $issue): array
    {
        return $this->run('SELECT i.id,i.title,i.status,CASE WHEN l.new_issue_id=? THEN 1 ELSE 0 END AS is_parent FROM comment_issue_links l JOIN issues i ON i.id=CASE WHEN l.new_issue_id=? THEN l.parent_issue_id ELSE l.new_issue_id END WHERE l.parent_issue_id=? OR (l.new_issue_id=? AND l.parent_issue_id IS NOT NULL)', [$issue,$issue,$issue,$issue])->fetchAll();
    }

    public function logIssueChanges(int $issue, array $before, int $user): void
    {
        $after = $this->issue($issue);
        foreach (['title'=>'title','status'=>'status','priority'=>'priority','assignee_id'=>'assignee','project_id'=>'project','label'=>'label','due_date'=>'due date','description'=>'description'] as $field => $label) {
            if (($before[$field] ?? null) == ($after[$field] ?? null)) { continue; }
            $body = 'Changed ' . $label;
            if (in_array($field, ['status','priority','label','due_date'], true)) { $body .= ' from ' . ($before[$field] ?: 'none') . ' to ' . ($after[$field] ?: 'none'); }
            $this->run('INSERT INTO issue_events (issue_id,user_id,body,created_at) VALUES (?,?,?,?)', [$issue,$user,$body,gmdate('c')]);
        }
    }
}
