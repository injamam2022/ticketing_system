<?php

namespace App\Controllers;

use App\Libraries\WorkspaceStore;
use App\Libraries\CommentUploads;

class Discussion extends BaseController
{
    use SessionProtection;

    private function handle(callable $action)
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        if (!session()->has('user_id')) { return $this->response->setStatusCode(401)->setJSON(['error'=>'Please sign in as a team member.']); }
        if ($this->request->getMethod() !== 'GET' && !$this->csrfValid()) { return $this->response->setStatusCode(403)->setJSON(['error'=>'Session expired. Reload the page.']); }
        try {
            $result = $action(new WorkspaceStore(), (int) session()->get('user_id'));
            return $result instanceof \CodeIgniter\HTTP\ResponseInterface ? $result : $this->response->setJSON($result);
        } catch (\PDOException $error) {
            log_message('error', 'Discussion database error: {error}', ['error'=>$error->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error'=>'Could not save this change. Please try again.']);
        } catch (\OutOfBoundsException $error) { return $this->response->setStatusCode(404)->setJSON(['error'=>$error->getMessage()]); }
        catch (\DomainException $error) { return $this->response->setStatusCode(403)->setJSON(['error'=>$error->getMessage()]); }
        catch (\InvalidArgumentException $error) { return $this->response->setStatusCode(422)->setJSON(['error'=>$error->getMessage()]); }
        catch (\RuntimeException $error) { return $this->response->setStatusCode(409)->setJSON(['error'=>$error->getMessage()]); }
        catch (\CodeIgniter\HTTP\Exceptions\HTTPException $error) { return $this->response->setStatusCode(400)->setJSON(['error'=>'Invalid request.']); }
    }

    private function input(): array
    {
        $data = str_contains($this->request->getHeaderLine('Content-Type'), 'application/json') ? $this->request->getJSON(true) : $this->request->getPost();
        if (!is_array($data)) { throw new \InvalidArgumentException('Invalid comment data.'); }
        return $data;
    }

    public function feed(int $issue) { return $this->handle(fn ($store,$user) => $store->discussion($issue,$user)); }

    public function save(int $issue, ?int $id = null)
    {
        return $this->handle(function ($store,$user) use ($issue,$id) {
            $data = $this->input();
            $files = CommentUploads::receive($this->request->getFileMultiple('attachments') ?? []);
            try {
                if ($id) {
                    if (!filter_var($data['version'] ?? null, FILTER_VALIDATE_INT)) { throw new \InvalidArgumentException('Reload the comment before editing.'); }
                    $store->editDiscussion($issue,$id,$user,$data['body'] ?? '',(int) $data['version'],$files);
                } else { $id = $store->postDiscussion($issue,$user,$data['body'] ?? '',$data['parent_id'] ?? null,$files); }
                return ['id'=>$id];
            } catch (\Throwable $error) { CommentUploads::discard($files); throw $error; }
        });
    }

    public function delete(int $issue,int $id) { return $this->handle(function ($store,$user) use ($issue,$id) { $store->deleteDiscussion($issue,$id,$user); return ['ok'=>true]; }); }
    public function react(int $issue,int $id) { return $this->handle(function ($store,$user) use ($issue,$id) { $store->reactToComment($issue,$id,$user,$this->input()['emoji'] ?? null); return ['ok'=>true]; }); }
    public function thread(int $issue,int $id) { return $this->handle(function ($store,$user) use ($issue,$id) { $action = $this->input()['action'] ?? ''; if (!is_string($action)) { throw new \InvalidArgumentException('Invalid action.'); } $store->threadAction($issue,$id,$user,$action); return ['ok'=>true]; }); }
    public function convert(int $issue,int $id) { return $this->handle(function ($store,$user) use ($issue,$id) { $input = $this->input(); $kind = $input['kind'] ?? ''; if (!is_string($kind)) { throw new \InvalidArgumentException('Invalid issue type.'); } return ['id'=>$store->issueFromComment($issue,$id,$input['title'] ?? '',$kind,$user)]; }); }
    public function notifications() { return $this->handle(fn ($store,$user) => ['notifications'=>$store->commentNotifications($user)]); }
    public function readNotification(int $id) { return $this->handle(function ($store,$user) use ($id) { $store->readCommentNotification($id,$user); return ['ok'=>true]; }); }
    public function removeAttachment(int $id) { return $this->handle(function ($store,$user) use ($id) { $file = $store->removeCommentAttachment($id,$user); CommentUploads::discard([$file]); return ['ok'=>true]; }); }

    public function attachment(int $id)
    {
        return $this->handle(function ($store) use ($id) {
            $file = $store->commentAttachment($id);
            $path = CommentUploads::path($file['storage_key']);
            if (!is_file($path)) { throw new \OutOfBoundsException('Attachment not found.'); }
            $inline = str_starts_with($file['mime'], 'image/');
            return $this->response->setHeader('Content-Type',$file['mime'])->setHeader('X-Content-Type-Options','nosniff')
                ->setHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['filename']))
                ->setBody(file_get_contents($path));
        });
    }
}
