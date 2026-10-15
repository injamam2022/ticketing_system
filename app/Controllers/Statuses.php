<?php

namespace App\Controllers;

use App\Libraries\WorkspaceStore;
use InvalidArgumentException;

class Statuses extends BaseController
{
    use SessionProtection;

    public function index()
    {
        if (!session()->has('user_id')) { return redirect()->to(site_url('login')); }
        $this->response->setHeader('Cache-Control', 'no-store');
        return view('statuses', ['pageTitle' => 'Manage statuses', 'staff' => true, 'statuses' => (new WorkspaceStore())->statuses(), 'categories' => WorkspaceStore::STATUS_CATEGORIES,
            'csrf' => $this->token(), 'error' => session()->getFlashdata('error'), 'success' => session()->getFlashdata('success'),
            'old' => session()->getFlashdata('old') ?? [], 'editing' => session()->getFlashdata('editing')]);
    }

    public function save(?int $id = null)
    {
        return $this->mutate(function ($store) use ($id) { $store->saveStatus($this->request->getPost(), $id); }, $id);
    }

    public function move(int $id)
    {
        return $this->mutate(fn ($store) => $store->moveStatus($id, $this->request->getPost('direction')), $id);
    }

    private function mutate(callable $action, ?int $id)
    {
        if (!session()->has('user_id')) { return redirect()->to(site_url('login')); }
        if (!$this->csrfValid()) { return $this->response->setStatusCode(403)->setBody('Your session expired. Reload the page and try again.'); }
        try {
            $action(new WorkspaceStore());
            return redirect()->to(site_url('statuses'))->with('success', 'Statuses updated.');
        } catch (InvalidArgumentException $error) {
            $old = array_filter(array_intersect_key($this->request->getPost(), array_flip(['name', 'color', 'category'])), 'is_string');
            return redirect()->to(site_url('statuses'))->with('error', $error->getMessage())->with('old', $old)->with('editing', $id ?? 'new');
        }
    }
}
