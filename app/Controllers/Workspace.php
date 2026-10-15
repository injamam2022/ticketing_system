<?php

namespace App\Controllers;

use App\Libraries\WorkspaceStore;
use InvalidArgumentException;

class Workspace extends BaseController
{
    use SessionProtection;

    public function index()
    {
        if (!session()->has('user_id')) { return redirect()->to(site_url('login')); }
        $this->response->setHeader('Cache-Control', 'no-store');
        return view('workspace', ['csrf' => $this->token()]);
    }

    public function login()
    {
        if (session()->has('user_id')) { return redirect()->to(site_url()); }
        return view('login', ['csrf' => $this->token(), 'error' => session()->getFlashdata('error'), 'demo' => \App\Libraries\WorkspaceStore::demoLoginEnabled()]);
    }

    public function issuePage(int $id)
    {
        if (!session()->has('user_id')) { return redirect()->to(site_url('login')); }
        $store = new WorkspaceStore();
        $issue = $store->issue($id);
        if (!$issue) { throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Issue not found.'); }
        $data = $store->data();
        $this->response->setHeader('Cache-Control', 'no-store');
        return view('issue', [
            'issue' => $issue, 'users' => $data['users'], 'projects' => $data['projects'], 'statuses' => array_column($data['statuses'], 'name'),
            'comments' => $store->comments($id), 'client' => $issue['client_id'] ? $store->client($issue['client_id']) : null,
            'csrf' => $this->token(),
            'discussion' => $store->discussion($id, (int) session()->get('user_id')), 'currentUser' => (int) session()->get('user_id'),
            'relatedIssues' => $store->relatedCommentIssues($id),
            'ticketImages' => $store->ticketImages($id),
            'clientMessages' => $issue['client_id'] ? $store->clientMessages($id) : [],
        ]);
    }

    public function authenticate()
    {
        if (!$this->csrfValid()) { return $this->response->setStatusCode(403)->setBody('Your session expired. Reload the sign-in page.'); }
        $store = new WorkspaceStore();
        $key = hash('sha256', $this->request->getIPAddress());
        if (!$store->loginAllowed($key)) { return redirect()->to(site_url('login'))->with('error', 'Too many attempts. Please try again in 15 minutes.'); }
        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');
        $user = is_string($email) ? $store->userByEmail($email) : false;
        $success = $user && is_string($password) && password_verify($password, $user['password']);
        $store->recordLogin($key, (bool) $success);
        if (!$success) { return redirect()->to(site_url('login'))->with('error', 'Email or password is incorrect.'); }
        session()->regenerate(true);
        session()->remove('client_id');
        session()->set(['user_id' => (int) $user['id'], 'workspace_csrf' => bin2hex(random_bytes(32))]);
        return redirect()->to(site_url());
    }

    public function logout()
    {
        if (!$this->csrfValid()) { return $this->response->setStatusCode(403); }
        session()->destroy();
        return redirect()->to(site_url('login'));
    }

    private function api(callable $action)
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        if (!session()->has('user_id')) { return $this->response->setStatusCode(401)->setJSON(['error' => 'Please sign in again.']); }
        if ($this->request->getMethod() !== 'GET' && !$this->csrfValid()) { return $this->response->setStatusCode(403)->setJSON(['error' => 'Session expired. Reload this page.']); }
        try { return $this->response->setJSON($action(new WorkspaceStore())); }
        catch (InvalidArgumentException $e) { return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]); }
        catch (\CodeIgniter\HTTP\Exceptions\HTTPException $e) { return $this->response->setStatusCode(400)->setJSON(['error' => 'Invalid request.']); }
    }

    private function payload(): array
    {
        $data = $this->request->getJSON(true);
        if (!is_array($data)) { throw new InvalidArgumentException('Send a valid JSON object.'); }
        return $data;
    }

    public function data() { return $this->api(fn ($store) => $store->data() + ['currentUser' => session()->get('user_id')]); }
    public function saveIssue(?int $id = null) { return $this->api(function ($store) use ($id) { $before = $id ? $store->issue($id) : null; $saved = $store->saveIssue($this->payload(), $id); if ($before) { $store->logIssueChanges($id, $before, (int) session()->get('user_id')); } return ['id'=>$saved]; }); }
    public function deleteIssue(int $id) { return $this->api(function ($store) use ($id) { $store->deleteIssue($id); return ['ok' => true]; }); }
    public function comments(int $id) { return $this->api(fn ($store) => ['comments' => $store->comments($id)]); }
    public function addComment(int $id) { return $this->api(function ($store) use ($id) { $store->addComment($id, (int) session()->get('user_id'), $this->payload()['body'] ?? ''); return ['ok' => true]; }); }
    public function saveProject() { return $this->api(fn ($store) => ['id' => $store->saveProject($this->payload())]); }

    private function conversationResponse(WorkspaceStore $store, int $id): array
    {
        $data = $store->clientConversation($id);
        return ['html' => view('portal/messages', ['messages' => $data['messages']]), 'count' => count($data['messages']), 'ticket' => $data['ticket']];
    }

    public function clientConversation(int $id) { return $this->api(fn ($store) => $this->conversationResponse($store, $id)); }
    public function replyToClient(int $id)
    {
        return $this->api(function ($store) use ($id) {
            $store->clientConversation($id);
            $store->addClientMessage($id, $this->payload()['body'] ?? '', null, (int) session()->get('user_id'));
            return $this->conversationResponse($store, $id);
        });
    }
}
