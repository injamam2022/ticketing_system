<?php

namespace App\Controllers;

use App\Libraries\WorkspaceStore;
use App\Libraries\TicketImages;
use DomainException;
use InvalidArgumentException;

class ClientPortal extends BaseController
{
    use SessionProtection;

    private function render(string $view, array $data = [])
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        return view('portal/' . $view, $data + [
            'statusDefinitions' => (new WorkspaceStore())->statuses(),
            'pageTitle' => match ($view) { 'login' => 'Client sign-in', 'clients' => 'Clients', 'ticket' => 'ORB-' . str_pad($data['ticket']['id'], 3, '0', STR_PAD_LEFT), default => 'Your support tickets' },
            'staff' => $view === 'clients',
            'csrf' => $this->token(),
            'error' => session()->getFlashdata('error'),
            'success' => session()->getFlashdata('success'),
            'old' => session()->getFlashdata('old') ?? [],
        ]);
    }

    private function guarded(bool $staff, string $fallback, callable $action)
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        $store = new WorkspaceStore();
        $client = $store->client((int) session()->get('client_id'));
        if ($staff ? !session()->has('user_id') : (!$client || !$client['active'])) {
            if (!$staff) { session()->remove('client_id'); }
            return redirect()->to(site_url($staff ? 'login' : 'portal/login'));
        }
        if ($this->request->getMethod() !== 'GET' && !$this->csrfValid()) {
            return $this->response->setStatusCode(403)->setBody('Your session expired. Reload the page and try again.');
        }
        try { return $action($store, $client); }
        catch (DomainException $error) { throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound($error->getMessage()); }
        catch (InvalidArgumentException $error) {
            $old = array_intersect_key($this->request->getPost() ?? [], array_flip(['name', 'company', 'email', 'active', 'projects', 'project_id', 'title', 'description', 'priority', 'type', 'body']));
            foreach ($old as $key => $value) {
                if ($key === 'projects') { $old[$key] = is_array($value) ? array_values(array_filter($value, 'is_string')) : []; }
                elseif (!is_string($value)) { unset($old[$key]); }
            }
            return redirect()->to(site_url($fallback))->with('error', $error->getMessage())->with('old', $old);
        }
    }

    public function login()
    {
        $client = (new WorkspaceStore())->client((int) session()->get('client_id'));
        if ($client && $client['active']) { return redirect()->to(site_url('portal')); }
        return $this->render('login');
    }

    public function authenticate()
    {
        if (!$this->csrfValid()) { return $this->response->setStatusCode(403)->setBody('Your session expired. Reload the sign-in page.'); }
        $store = new WorkspaceStore();
        $key = hash('sha256', 'client:' . $this->request->getIPAddress());
        if (!$store->loginAllowed($key)) { return redirect()->to(site_url('portal/login'))->with('error', 'Too many attempts. Try again in 15 minutes.'); }
        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');
        $client = is_string($email) ? $store->clientByEmail($email) : false;
        $success = $client && $client['active'] && is_string($password) && password_verify($password, $client['password']);
        $store->recordLogin($key, (bool) $success);
        if (!$success) { return redirect()->to(site_url('portal/login'))->with('error', 'Email or password is incorrect, or your account is inactive.'); }
        session()->regenerate(true);
        session()->remove('user_id');
        session()->set(['client_id' => (int) $client['id'], 'workspace_csrf' => bin2hex(random_bytes(32))]);
        return redirect()->to(site_url('portal'));
    }

    public function logout()
    {
        if (!$this->csrfValid()) { return $this->response->setStatusCode(403); }
        session()->destroy();
        return redirect()->to(site_url('portal/login'));
    }

    public function index()
    {
        return $this->guarded(false, 'portal', fn ($store, $client) => $this->render('index', ['bodyClass' => 'portal-dashboard', 'client' => $client, 'projects' => $store->clientProjects($client['id']), 'tickets' => $store->clientTickets($client['id'])]));
    }

    public function submit()
    {
        return $this->guarded(false, 'portal', function ($store, $client) {
            $files = TicketImages::receive($this->request->getFileMultiple('images') ?? []);
            try { $id = $store->submitClientTicket($client['id'], $this->request->getPost(), $files); }
            catch (\Throwable $error) { TicketImages::discard($files); throw $error; }
            return redirect()->to(site_url('portal/tickets/' . $id))->with('success', 'Your ticket is with our team. You can follow its progress here.');
        });
    }

    public function ticket(int $id)
    {
        return $this->guarded(false, 'portal', fn ($store, $client) => $this->render('ticket', ['client' => $client, 'staff' => false, 'ticket' => $store->clientTicket($client['id'], $id), 'images' => $store->ticketImages($id, $client['id']), 'messages' => $store->clientMessages($id)]));
    }

    public function reply(int $id)
    {
        return $this->guarded(false, 'portal/tickets/' . $id, function ($store, $client) use ($id) {
            $store->addClientMessage($id, $this->request->getPost('body'), $client['id']);
            return redirect()->to(site_url('portal/tickets/' . $id))->with('success', 'Reply sent to the team.');
        });
    }

    public function updates(int $id)
    {
        return $this->guarded(false, 'portal', function ($store, $client) use ($id) {
            $data = $store->clientConversation($id, (int) $client['id']);
            return $this->response->setJSON(['html' => view('portal/messages', ['messages' => $data['messages']]), 'count' => count($data['messages']), 'ticket' => $data['ticket']]);
        });
    }

    public function clients()
    {
        return $this->guarded(true, 'clients', fn ($store) => $this->render('clients', ['clients' => $store->clients(), 'projects' => $store->data()['projects'], 'editing' => (int) $this->request->getGet('edit')]));
    }

    public function saveClient(?int $id = null)
    {
        return $this->guarded(true, 'clients' . ($id ? '?edit=' . $id : ''), function ($store) use ($id) {
            $store->saveClient($this->request->getPost(), $id);
            return redirect()->to(site_url('clients'))->with('success', $id ? 'Client access updated.' : 'Client created. Share the portal URL and credentials with them securely.');
        });
    }

    public function staffTicket(int $id)
    {
        return $this->guarded(true, 'clients', function ($store) use ($id) {
            $ticket = $store->issue($id);
            if (!$ticket || !$ticket['client_id']) { throw new DomainException('Client ticket not found.'); }
            $project = array_values(array_filter($store->data()['projects'], fn ($project) => $project['id'] === $ticket['project_id']));
            $ticket['project_name'] = $project[0]['name'] ?? 'No project';
            return $this->render('ticket', ['staff' => true, 'client' => $store->client($ticket['client_id']), 'ticket' => $ticket, 'images' => $store->ticketImages($id), 'messages' => $store->clientMessages($id)]);
        });
    }

    public function image(int $id)
    {
        return $this->serveImage($id, false);
    }

    public function staffImage(int $id)
    {
        return $this->serveImage($id, true);
    }

    private function serveImage(int $id, bool $staff)
    {
        return $this->guarded($staff, $staff ? 'clients' : 'portal', function ($store, $client) use ($id, $staff) {
            $image = $store->ticketImage($id, $staff ? null : (int) $client['id']);
            $path = TicketImages::path($image['storage_key']);
            if (!is_file($path)) { throw new DomainException('Image not found.'); }
            return $this->response->setHeader('Content-Type', $image['mime'])->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Content-Disposition', "inline; filename*=UTF-8''" . rawurlencode($image['filename']))
                ->setHeader('Content-Security-Policy', "default-src 'none'; sandbox")
                ->setBody(file_get_contents($path));
        });
    }

    public function staffUpdate(int $id)
    {
        return $this->guarded(true, 'clients/tickets/' . $id, function ($store) use ($id) {
            if (!($store->issue($id)['client_id'] ?? null)) { throw new DomainException('Client ticket not found.'); }
            $store->saveIssue(['status' => $this->request->getPost('status'), 'priority' => $this->request->getPost('priority')], $id);
            return redirect()->to(site_url('clients/tickets/' . $id))->with('success', 'Ticket updated. The client can see the new status.');
        });
    }

    public function staffReply(int $id)
    {
        return $this->guarded(true, 'clients/tickets/' . $id, function ($store) use ($id) {
            $store->addClientMessage($id, $this->request->getPost('body'), null, (int) session()->get('user_id'));
            return redirect()->to(site_url('clients/tickets/' . $id))->with('success', 'Reply published to the client portal.');
        });
    }
}
