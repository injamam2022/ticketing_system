<?php

namespace App\Controllers;

use App\Libraries\WorkspaceStore;
use InvalidArgumentException;

class Team extends BaseController
{
    use SessionProtection;

    public function index()
    {
        if (!session()->has('user_id')) { return redirect()->to(site_url('login')); }
        $this->response->setHeader('Cache-Control', 'no-store');
        $store = new WorkspaceStore();
        return view('team', [
            'pageTitle' => 'Team',
            'staff' => true,
            'users' => $store->data()['users'],
            'colors' => WorkspaceStore::USER_COLORS,
            'csrf' => $this->token(),
            'error' => session()->getFlashdata('error'),
            'success' => session()->getFlashdata('success'),
            'old' => session()->getFlashdata('old') ?? [],
            'editing' => session()->getFlashdata('editing') ?? $this->request->getGet('edit'),
            'currentUser' => (int) session()->get('user_id'),
        ]);
    }

    public function save(?int $id = null)
    {
        if (!session()->has('user_id')) { return redirect()->to(site_url('login')); }
        if (!$this->csrfValid()) { return $this->response->setStatusCode(403)->setBody('Your session expired. Reload the page and try again.'); }
        try {
            (new WorkspaceStore())->saveUser($this->request->getPost(), $id);
            return redirect()->to(site_url('team'))->with('success', $id ? 'Teammate updated.' : 'Teammate created. Share their sign-in credentials securely.');
        } catch (InvalidArgumentException $error) {
            $old = array_intersect_key($this->request->getPost() ?? [], array_flip(['name', 'email', 'color']));
            foreach ($old as $key => $value) {
                if (!is_string($value)) { unset($old[$key]); }
            }
            return redirect()->to(site_url('team' . ($id ? '?edit=' . $id : '')))->with('error', $error->getMessage())->with('old', $old)->with('editing', $id ?? 'new');
        }
    }
}
